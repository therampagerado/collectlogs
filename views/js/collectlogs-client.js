(function (window, document) {
  'use strict';
  var config = window.collectlogsClientConfig || null;
  if (!config || !config.endpoint || !config.token) {
    return;
  }

  var queue = [];
  var sent = 0;
  var flushTimer = null;
  var maxEvents = Number(config.maxEventsPerPage || 10);
  var sampleRate = Number(config.sampleRate || 100);
  if (Math.random() * 100 >= sampleRate) {
    return;
  }

  var CHROME = /^\s*at (?:(.*?) ?\()?((?:file|https?|blob|chrome-extension|native|eval|webpack|<anonymous>|[-a-z]+:|\/).*?)(?::(\d+))?(?::(\d+))?\)?\s*$/i;
  var GECKO = /^\s*(.*?)(?:\((.*?)\))?(?:^|@)?((?:file|https?|blob|chrome|webpack|resource|moz-extension).*?:\/.*?|\[native code\]|[^@]*(?:bundle|\d+\.js))(?::(\d+))?(?::(\d+))?\s*$/i;

  function scrubText(value) {
    value = String(value || '');
    value = value.replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/ig, '[email]');
    value = value.replace(/\+?[0-9][0-9\s\-()]{7,}[0-9]/g, '[phone]');
    return value.slice(0, 2000);
  }

  function cleanUrl(url) {
    try {
      var u = new URL(url || window.location.href, window.location.origin);
      return u.origin + u.pathname + (config.includeQueryString ? u.search : '');
    } catch (e) {
      return '';
    }
  }

  function normalizeStack(err) {
    if (!config.includeStackTrace || !err) {
      return [];
    }
    var stack = err.stacktrace || err.stack || '';
    if (!stack) {
      return [];
    }
    var lines = String(stack).split('\n');
    var out = [];
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      var m = CHROME.exec(line) || GECKO.exec(line);
      if (!m) continue;
      out.push({
        url: scrubText(m[2] || m[3] || ''),
        func: scrubText(m[1] || '?'),
        args: [],
        line: m[3] ? Number(m[3]) : (m[4] ? Number(m[4]) : null),
        column: m[4] ? Number(m[4]) : (m[5] ? Number(m[5]) : null)
      });
    }
    return out;
  }

  function enqueue(event) {
    if (sent >= maxEvents) {
      return;
    }
    sent += 1;
    queue.push(event);
    if (!flushTimer) {
      flushTimer = window.setTimeout(flush, 3000);
    }
  }

  function send(body) {
    var payload = JSON.stringify(body);
    if (navigator.sendBeacon) {
      try {
        var ok = navigator.sendBeacon(config.endpoint, new Blob([payload], { type: 'application/json' }));
        if (ok) return;
      } catch (e) {}
    }
    if (window.fetch) {
      fetch(config.endpoint, { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: payload });
      return;
    }
    var xhr = new XMLHttpRequest();
    xhr.open('POST', config.endpoint, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.send(payload);
  }

  function flush() {
    if (!queue.length) return;
    flushTimer = null;
    send({ token: config.token, events: queue.splice(0, queue.length), visitor_id: String(config.token).slice(0, 12) });
  }

  window.onerror = (function (orig) {
    return function (msg, source, lineno, colno, error) {
      enqueue({
        meta: { dt: new Date().toISOString(), stream: 'client-runtime-error', shop_id: config.shopId },
        severity: 'error',
        type: 'runtime',
        message: scrubText(msg || (error && error.message) || 'Runtime error'),
        url: cleanUrl(window.location.href),
        referrer: cleanUrl(document.referrer || ''),
        script_url: cleanUrl(source || ''),
        line: lineno || null,
        column: colno || null,
        stack_trace: normalizeStack(error),
        tags: config.tags || {}
      });
      if (typeof orig === 'function') {
        return orig.apply(window, arguments);
      }
      return false;
    };
  })(window.onerror);

  window.addEventListener('unhandledrejection', function (event) {
    var reason = event && event.reason;
    enqueue({
      meta: { dt: new Date().toISOString(), stream: 'client-runtime-error', shop_id: config.shopId },
      severity: 'error',
      type: 'unhandledrejection',
      message: scrubText(reason && reason.message ? reason.message : String(reason || 'Unhandled promise rejection')),
      url: cleanUrl(window.location.href),
      referrer: cleanUrl(document.referrer || ''),
      stack_trace: normalizeStack(reason),
      tags: config.tags || {}
    });
  });

  window.addEventListener('error', function (event) {
    var t = event && event.target;
    if (!t || t === window) return;
    var src = t.currentSrc || t.src || t.href || '';
    enqueue({
      meta: { dt: new Date().toISOString(), stream: 'client-runtime-error', shop_id: config.shopId },
      severity: 'warn',
      type: 'resource',
      message: scrubText('Resource failed to load: ' + (t.tagName || 'unknown')),
      url: cleanUrl(window.location.href),
      referrer: cleanUrl(document.referrer || ''),
      script_url: cleanUrl(src),
      stack_trace: [],
      tags: config.tags || {}
    });
  }, true);


  function replayPreQueue() {
    var pre = window.collectlogsPreQueue;
    if (!pre || !pre.length) {
      return;
    }
    for (var i = 0; i < pre.length; i++) {
      var item = pre[i] || {};
      if (item.kind === 'onerror') {
        var args = item.args || [];
        var msg = args[0], source = args[1], lineno = args[2], colno = args[3], error = args[4];
        enqueue({
          meta: { dt: new Date().toISOString(), stream: 'client-runtime-error', shop_id: config.shopId },
          severity: 'error',
          type: 'runtime',
          message: scrubText(msg || (error && error.message) || 'Runtime error'),
          url: cleanUrl(window.location.href),
          referrer: cleanUrl(document.referrer || ''),
          script_url: cleanUrl(source || ''),
          line: lineno || null,
          column: colno || null,
          stack_trace: normalizeStack(error),
          tags: config.tags || {}
        });
      } else if (item.kind === 'unhandledrejection') {
        var event = item.event || {};
        var reason = event.reason;
        enqueue({
          meta: { dt: new Date().toISOString(), stream: 'client-runtime-error', shop_id: config.shopId },
          severity: 'error',
          type: 'unhandledrejection',
          message: scrubText(reason && reason.message ? reason.message : String(reason || 'Unhandled promise rejection')),
          url: cleanUrl(window.location.href),
          referrer: cleanUrl(document.referrer || ''),
          stack_trace: normalizeStack(reason),
          tags: config.tags || {}
        });
      } else if (item.kind === 'resource') {
        var ev = item.event || {};
        var t = ev.target;
        if (t && t !== window) {
          var src = t.currentSrc || t.src || t.href || '';
          enqueue({
            meta: { dt: new Date().toISOString(), stream: 'client-runtime-error', shop_id: config.shopId },
            severity: 'warn',
            type: 'resource',
            message: scrubText('Resource failed to load: ' + (t.tagName || 'unknown')),
            url: cleanUrl(window.location.href),
            referrer: cleanUrl(document.referrer || ''),
            script_url: cleanUrl(src),
            stack_trace: [],
            tags: config.tags || {}
          });
        }
      }
    }
    window.collectlogsPreQueue = [];
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flush();
    }
  });
  window.addEventListener('beforeunload', flush);
  replayPreQueue();
})(window, document);
