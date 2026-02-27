(function (window, document) {
  'use strict';

  var cfg = window.collectlogsClientLogConfig || null;
  if (!cfg || !cfg.endpoint || !cfg.token) {
    return;
  }

  if (Math.random() * 100 >= (parseInt(cfg.sampling_rate, 10) || 100)) {
    return;
  }

  var chrome = /^\s*at (?:(.*?) ?\()?((?:file|https?|blob|chrome-extension|native|eval|webpack|<anonymous>|[-a-z]+:|\/).*?)(?::(\d+))?(?::(\d+))?\)?\s*$/i;
  var gecko = /^\s*(.*?)(?:\((.*?)\))?(?:^|@)?((?:file|https?|blob|chrome|webpack|resource|moz-extension).*?:\/.*?|\[native code\]|[^@]*(?:bundle|\d+\.js))(?::(\d+))?(?::(\d+))?\s*$/i;

  var queue = [];
  var pending = false;
  var maxEvents = parseInt(cfg.max_events, 10) || 10;
  var visitorId = sessionStorage.getItem('collectlogs_visitor_id');
  if (!visitorId) {
    visitorId = String(Date.now()) + '-' + String(Math.random()).slice(2, 10);
    try { sessionStorage.setItem('collectlogs_visitor_id', visitorId); } catch (e) {}
  }

  function sanitizeText(str, max) {
    str = String(str || '').replace(/[\r\n\t]+/g, ' ');
    str = str.replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/ig, '[email]');
    str = str.replace(/\+?[0-9][0-9\s\-]{7,}[0-9]/g, '[phone]');
    if (str.length > max) {
      str = str.slice(0, max);
    }
    return str;
  }

  function sanitizeUrl(url) {
    var a = document.createElement('a');
    a.href = String(url || window.location.href);
    var output = a.protocol + '//' + a.host + a.pathname;
    if (cfg.include_query && a.search) {
      output += a.search;
    }
    return sanitizeText(output, 2000);
  }

  function parseStack(err) {
    if (!cfg.include_stack || !err) {
      return [];
    }
    var stack = err.stack || err.stacktrace || '';
    if (!stack || typeof stack !== 'string') {
      return [];
    }
    var frames = [];
    stack.split('\n').forEach(function (line) {
      var part = chrome.exec(line) || gecko.exec(line);
      if (!part) {
        return;
      }
      frames.push({
        url: sanitizeUrl(part[2] || part[3] || ''),
        func: sanitizeText(part[1] || '?', 200),
        args: [],
        line: part[3] || part[4] ? parseInt(part[3] || part[4], 10) : null,
        column: part[4] || part[5] ? parseInt(part[4] || part[5], 10) : null
      });
    });
    return frames.slice(0, 50);
  }

  function buildBasePayload(type, message, extra) {
    return {
      severity: extra.severity || 'error',
      meta: {
        dt: new Date().toISOString(),
        stream: 'client-runtime-error',
        build: cfg.tb_version || ''
      },
      type: type,
      raw_type: extra.rawType || '',
      message: sanitizeText(message || 'Unknown JS error', 2000),
      url: sanitizeUrl(window.location.href),
      referrer: sanitizeUrl(document.referrer || ''),
      user_agent: sanitizeText(navigator.userAgent || '', 1500),
      stack_trace: extra.stack || [],
      tags: {
        tb_version: cfg.tb_version || '',
        theme: cfg.theme || '',
        page_type: cfg.controller || '',
        controller: cfg.controller || '',
        currency: cfg.currency || '',
        lang: cfg.lang || ''
      }
    };
  }

  function enqueue(ev) {
    if (queue.length >= maxEvents) {
      return;
    }
    queue.push(ev);
    if (!pending) {
      pending = true;
      setTimeout(flush, parseInt(cfg.flush_interval_ms, 10) || 5000);
    }
  }

  function flush() {
    if (!queue.length) {
      pending = false;
      return;
    }
    var payload = JSON.stringify({ token: cfg.token, visitor_id: visitorId, events: queue.splice(0, queue.length) });
    pending = false;

    if (navigator.sendBeacon) {
      var blob = new Blob([payload], { type: 'application/json' });
      if (navigator.sendBeacon(cfg.endpoint, blob)) {
        return;
      }
    }

    if (window.fetch) {
      fetch(cfg.endpoint, {
        method: 'POST',
        credentials: 'omit',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: payload
      })['catch'](function () {});
      return;
    }

    try {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', cfg.endpoint, true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.send(payload);
    } catch (e) {}
  }

  window.onerror = (function (orig) {
    return function (msg, src, line, col, err) {
      enqueue(buildBasePayload('runtime', msg, {
        severity: 'error',
        rawType: Object.prototype.toString.call(err),
        stack: parseStack(err || { stack: msg + '\n at ' + src + ':' + line + ':' + col })
      }));
      if (typeof orig === 'function') {
        return orig.apply(window, arguments);
      }
      return false;
    };
  })(window.onerror);

  window.addEventListener('unhandledrejection', function (event) {
    var reason = event && event.reason;
    var message = reason && reason.message ? reason.message : String(reason || 'Unhandled promise rejection');
    enqueue(buildBasePayload('unhandledrejection', message, {
      severity: 'error',
      rawType: Object.prototype.toString.call(reason),
      stack: parseStack(reason)
    }));
  });

  window.addEventListener('error', function (event) {
    var target = event.target || event.srcElement;
    if (!target || target === window) {
      return;
    }
    var src = target.src || target.href || '';
    enqueue(buildBasePayload('resource', 'Resource load error: ' + (target.tagName || 'unknown'), {
      severity: 'warn',
      rawType: 'resource',
      stack: [{ url: sanitizeUrl(src), func: 'resource', args: [], line: null, column: null }]
    }));
  }, true);

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flush();
    }
  });
  window.addEventListener('beforeunload', flush);
})(window, document);
