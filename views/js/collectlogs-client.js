(function (window, document) {
  'use strict';

  var config = window.collectlogsClientConfig || null;
  if (!config || !config.endpoint || !config.token) {
    return;
  }

  var queue = [];
  var sent = 0;
  var flushTimer = null;
  var lastRuntimeKey = '';
  var lastRuntimeAt = 0;
  var maxEvents = Math.max(1, Number(config.maxEventsPerPage || 10) || 10);
  var sampleRate = Math.max(0, Math.min(100, Number(config.sampleRate || 100) || 100));
  var visitorId = getVisitorId();
  var debugEnabled = /(?:\?|&)collectlogs_debug=1(?:&|$)/.test(window.location.search);
  var sourceTextCache = {};
  var sourceTextLoading = {};
  var pendingSourceLoads = 0;
  var nativeFetch = window.fetch.bind(window);
  var locationCacheHref = '';
  var locationCacheUrl = '';
  var locationCachePath = '';
  var referrerUrl = cleanUrl(document.referrer || '');

  if (Math.random() * 100 >= sampleRate) {
    return;
  }

  var CHROME = /^\s*at (?:(.*?) ?\()?((?:file|https?|blob|chrome-extension|native|eval|webpack|<anonymous>|[-a-z]+:|\/).*?)(?::(\d+))?(?::(\d+))?\)?\s*$/i;
  var GECKO = /^\s*(.*?)(?:\((.*?)\))?(?:^|@)?((?:file|https?|blob|chrome|webpack|resource|moz-extension).*?:\/.*?|\[native code\]|[^@]*(?:bundle|\d+\.js))(?::(\d+))?(?::(\d+))?\s*$/i;
  var FORBIDDEN_META_KEYS = { '__proto__': true, constructor: true, prototype: true };
  var TOKEN_CHAR = /[A-Za-z0-9_$]/;

  function warn(message) {
    if (window.console && typeof window.console.warn === 'function') {
      window.console.warn('collectlogs:', message);
    }
  }

  function info(message, value) {
    if (!debugEnabled || !window.console || typeof window.console.info !== 'function') {
      return;
    }
    if (arguments.length > 1) {
      window.console.info('collectlogs:', message, value);
      return;
    }
    window.console.info('collectlogs:', message);
  }

  function createPlainObject() {
    return Object.create(null);
  }

  function shouldRedactPhoneMatch(value) {
    return !/^\d{4}-\d{2}-\d{2}$/.test(value);
  }

  function scrubCoreText(value, limit, singleLine) {
    value = String(value || '');
    if (singleLine) {
      value = value.replace(/[\r\n]+/g, ' ');
      value = value.replace(/\t/g, ' ');
    }
    value = value.replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/ig, '[email]');
    value = value.replace(/\+?[0-9][0-9\s\-()]{7,}[0-9]/g, function (match) {
      return shouldRedactPhoneMatch(match) ? '[phone]' : match;
    });
    value = value.replace(/([?&])(token|secure_key|password|passwd|pwd|email)=([^&\s]+)/ig, '$1$2=[removed]');
    return value.slice(0, limit || 2000);
  }

  function scrubText(value, limit) {
    return scrubCoreText(value, limit || 2000, false);
  }

  function scrubSnippetText(value, limit) {
    return scrubCoreText(value, limit || 200, true);
  }

  function scrubUrlText(value, limit) {
    value = String(value || '');
    value = value.replace(/[\r\n\t]+/g, '');
    return value.slice(0, limit || 2000);
  }

  function normalizeNumber(value) {
    value = Number(value);
    return isFinite(value) && value > 0 ? value : null;
  }

  function cleanUrl(url) {
    try {
      var parsed = new URL(url || window.location.href, window.location.origin);
      return parsed.origin + parsed.pathname + (config.includeQueryString ? parsed.search : '');
    } catch (e) {
      return '';
    }
  }

  function cleanPath(url) {
    try {
      var parsed = new URL(url || window.location.href, window.location.origin);
      if (parsed.origin !== window.location.origin) {
        return 'cross-origin';
      }
      return parsed.pathname || '/';
    } catch (e) {
      return 'unknown';
    }
  }

  function refreshLocationCache() {
    var href = window.location.href;
    if (href === locationCacheHref) {
      return;
    }
    locationCacheHref = href;
    locationCacheUrl = cleanUrl(href);
    locationCachePath = cleanPath(href);
  }

  function getCurrentPageUrl() {
    refreshLocationCache();
    return locationCacheUrl;
  }

  function getCurrentPagePath() {
    refreshLocationCache();
    return locationCachePath;
  }

  function getRandomId() {
    var bytes = new Uint8Array(12);
    var out = '';
    window.crypto.getRandomValues(bytes);
    for (var i = 0; i < bytes.length; i++) {
      out += ('0' + bytes[i].toString(16)).slice(-2);
    }
    return out;
  }

  function getVisitorId() {
    var storageKey = String(config.visitorStorageKey || 'collectlogs-visitor');
    try {
      var existing = window.sessionStorage.getItem(storageKey);
      if (existing) {
        return existing;
      }
      var generated = getRandomId();
      window.sessionStorage.setItem(storageKey, generated);
      return generated;
    } catch (e) {}
    return getRandomId();
  }

  function mergeMeta(base, extra) {
    var result = createPlainObject();
    var key;
    base = base || {};
    extra = extra || {};

    for (key in base) {
      if (Object.prototype.hasOwnProperty.call(base, key) && !FORBIDDEN_META_KEYS[key]) {
        result[key] = base[key];
      }
    }

    for (key in extra) {
      if (Object.prototype.hasOwnProperty.call(extra, key) && !FORBIDDEN_META_KEYS[key]) {
        result[key] = extra[key];
      }
    }

    return result;
  }

  function sanitizeMetaValue(value, depth, seen) {
    depth = depth || 0;
    seen = seen || [];

    if (value === null) {
      return null;
    }
    if (typeof value === 'undefined') {
      return 'undefined';
    }
    if (typeof value === 'string') {
      return scrubText(value, 500);
    }
    if (typeof value === 'number' || typeof value === 'boolean') {
      return value;
    }
    if (typeof value === 'bigint') {
      return 'bigint';
    }
    if (typeof value === 'function') {
      return 'function(' + scrubText(value.name || 'anonymous', 120) + ')';
    }
    if (typeof value !== 'object') {
      return scrubText(String(value), 500);
    }

    if (depth >= 3) {
      return '[truncated]';
    }

    for (var i = 0; i < seen.length; i++) {
      if (seen[i] === value) {
        return '[circular]';
      }
    }

    var nextSeen = seen.slice(0);
    nextSeen.push(value);

    if (Array.isArray(value)) {
      var outArray = [];
      for (var j = 0; j < value.length && j < 12; j++) {
        outArray.push(sanitizeMetaValue(value[j], depth + 1, nextSeen));
      }
      if (value.length > 12) {
        outArray.push('[truncated]');
      }
      return outArray;
    }

    var outObject = createPlainObject();
    var count = 0;
    for (var prop in value) {
      if (!Object.prototype.hasOwnProperty.call(value, prop) || FORBIDDEN_META_KEYS[prop]) {
        continue;
      }
      outObject[scrubText(prop, 80)] = sanitizeMetaValue(value[prop], depth + 1, nextSeen);
      count += 1;
      if (count >= 30) {
        outObject.__truncated__ = true;
        break;
      }
    }
    return outObject;
  }

  function sanitizeMeta(meta) {
    if (!meta || typeof meta !== 'object') {
      return {};
    }
    return sanitizeMetaValue(meta, 0, []);
  }

  function summarizeValue(value, depth) {
    depth = depth || 0;
    if (depth >= 2) {
      return '[truncated]';
    }
    if (value === null) {
      return 'null';
    }
    if (typeof value === 'undefined') {
      return 'undefined';
    }
    if (typeof value === 'string') {
      return 'string(len=' + value.length + ')';
    }
    if (typeof value === 'number' || typeof value === 'boolean' || typeof value === 'bigint') {
      return typeof value;
    }
    if (typeof value === 'function') {
      return 'function(' + scrubText(value.name || 'anonymous', 120) + ')';
    }
    if (Array.isArray(value)) {
      var items = [];
      for (var i = 0; i < value.length && i < 5; i++) {
        items.push(summarizeValue(value[i], depth + 1));
      }
      return { type: 'array', length: value.length, items: items };
    }
    if (typeof value === 'object') {
      var keys = [];
      var total = 0;
      for (var key in value) {
        if (!Object.prototype.hasOwnProperty.call(value, key)) {
          continue;
        }
        keys.push(sanitizeMetaValue(key, depth + 1, []));
        total += 1;
        if (keys.length >= 8) {
          break;
        }
      }
      return {
        type: 'object',
        ctor: value && value.constructor && value.constructor.name ? scrubText(value.constructor.name, 120) : 'Object',
        keys: keys,
        key_count: total
      };
    }
    return typeof value;
  }

  function summarizeArgs(argsLike) {
    var args = [];
    for (var i = 0; i < argsLike.length && i < 8; i++) {
      args.push(summarizeValue(argsLike[i], 0));
    }
    return {
      count: argsLike.length,
      items: args
    };
  }

  function getErrorMeta(error) {
    var meta = createPlainObject();
    if (!error || typeof error !== 'object') {
      return meta;
    }
    try {
      if (error.__collectlogsMeta && typeof error.__collectlogsMeta === 'object') {
        meta = mergeMeta(meta, sanitizeMeta(error.__collectlogsMeta));
      }
    } catch (e) {}
    try {
      if (error.name) {
        meta.error_name = scrubText(error.name, 120);
      }
    } catch (e) {}
    try {
      if (error.constructor && error.constructor.name) {
        meta.error_ctor = scrubText(error.constructor.name, 120);
      }
    } catch (e) {}
    return meta;
  }

  function normalizeStack(error) {
    if (!config.includeStackTrace || !error) {
      return [];
    }

    var stack = error.stacktrace || error.stack || '';
    if (!stack) {
      return [];
    }

    var lines = String(stack).split('\n');
    var out = [];
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      var match = CHROME.exec(line);
      if (match) {
        out.push({
          url: scrubUrlText(match[2] || '', 2000),
          func: scrubText(match[1] || '?', 255),
          args: [],
          line: normalizeNumber(match[3]),
          column: normalizeNumber(match[4])
        });
        continue;
      }

      match = GECKO.exec(line);
      if (!match) {
        continue;
      }
      out.push({
        url: scrubUrlText(match[3] || '', 2000),
        func: scrubText(match[1] || '?', 255),
        args: [],
        line: normalizeNumber(match[4]),
        column: normalizeNumber(match[5])
      });
    }
    return out.slice(0, 20);
  }

  function getPreferredPageType() {
    if (config.pageContext && config.pageContext.page_type) {
      return scrubText(config.pageContext.page_type, 80);
    }
    return getCurrentPagePath();
  }

  function readInputValue(selectors) {
    for (var i = 0; i < selectors.length; i++) {
      var element = document.querySelector(selectors[i]);
      if (element && typeof element.value !== 'undefined' && element.value !== '') {
        return element.value;
      }
    }
    return null;
  }

  function readNumericValue(value) {
    value = normalizeNumber(value);
    return value === null ? null : value;
  }

  function getCartCount() {
    if (config.pageContext && typeof config.pageContext.cart_count !== 'undefined') {
      return Number(config.pageContext.cart_count) || 0;
    }

    var selectors = [
      '.ajax_cart_quantity',
      '.cart-products-count',
      '[data-cart-count]'
    ];

    for (var i = 0; i < selectors.length; i++) {
      var element = document.querySelector(selectors[i]);
      if (!element) {
        continue;
      }
      var raw = element.getAttribute && element.getAttribute('data-cart-count') ? element.getAttribute('data-cart-count') : element.textContent;
      var digits = String(raw || '').match(/\d+/);
      if (digits) {
        return Number(digits[0]) || 0;
      }
    }

    return 0;
  }

  function getDynamicPageState() {
    var state = createPlainObject();
    var pageContext = config.pageContext || {};
    var productId = readNumericValue(pageContext.product_id);
    var categoryId = readNumericValue(pageContext.category_id);
    var combinationId = readNumericValue(pageContext.combination_id);

    if (productId === null) {
      productId = readNumericValue(readInputValue(['input[name="id_product"]', '#product_page_product_id']));
    }
    if (categoryId === null) {
      categoryId = readNumericValue(readInputValue(['input[name="id_category"]']));
    }
    if (combinationId === null) {
      combinationId = readNumericValue(readInputValue(['input[name="id_product_attribute"]', 'input[name="ipa"]', '#idCombination']));
    }

    state.page_type = scrubText(pageContext.page_type || getPreferredPageType(), 80);
    state.product_id = productId;
    state.category_id = categoryId;
    state.combination_id = combinationId;
    state.cart_count = getCartCount();
    state.currency = scrubText(pageContext.currency || (config.tags && config.tags.currency) || '', 20);
    state.lang = scrubText(pageContext.lang || (config.tags && config.tags.lang) || '', 20);
    state.login_state = scrubText(pageContext.login_state || 'guest', 20);
    return sanitizeMeta(state);
  }

  function getViewportLabel() {
    var width = window.innerWidth || (document.documentElement && document.documentElement.clientWidth) || 0;
    var height = window.innerHeight || (document.documentElement && document.documentElement.clientHeight) || 0;
    if (!width && !height) {
      return '';
    }
    return width + 'x' + height;
  }

  function getRuntimeSnapshot() {
    return sanitizeMeta({
      ready_state: document.readyState || '',
      viewport: getViewportLabel()
    });
  }

  function canCaptureSourceContext(url) {
    try {
      var parsed = new URL(url, window.location.href);
      if (parsed.origin !== window.location.origin) {
        return false;
      }
      if (!/\.js$/i.test(parsed.pathname || '')) {
        return false;
      }
      return /\/(themes|modules|js)\//i.test(parsed.pathname || '');
    } catch (e) {
      return false;
    }
  }

  function loadSourceText(url, callback) {
    if (!canCaptureSourceContext(url)) {
      callback('');
      return;
    }

    if (Object.prototype.hasOwnProperty.call(sourceTextCache, url)) {
      callback(sourceTextCache[url]);
      return;
    }

    if (sourceTextLoading[url]) {
      sourceTextLoading[url].push(callback);
      return;
    }

    sourceTextLoading[url] = [callback];
    pendingSourceLoads += 1;

    nativeFetch(url, {
      credentials: 'same-origin',
      cache: 'force-cache'
    }).then(function (response) {
      if (!response || !response.ok) {
        return '';
      }

      var contentType = response.headers && typeof response.headers.get === 'function'
        ? String(response.headers.get('content-type') || '')
        : '';

      if (contentType && contentType.indexOf('html') !== -1) {
        return '';
      }

      return response.text();
    }).then(function (text) {
      if (typeof text !== 'string' || text.length > 1048576) {
        text = '';
      }
      completeSourceLoad(url, text);
    }).catch(function () {
      completeSourceLoad(url, '');
    });
  }

  function completeSourceLoad(url, text) {
    sourceTextCache[url] = text || '';
    var callbacks = sourceTextLoading[url] || [];
    delete sourceTextLoading[url];
    if (pendingSourceLoads > 0) {
      pendingSourceLoads -= 1;
    }

    for (var i = 0; i < callbacks.length; i++) {
      callbacks[i](sourceTextCache[url]);
    }

    if (!flushTimer && queue.length && pendingSourceLoads === 0) {
      flushTimer = window.setTimeout(function () {
        flush(false);
      }, 50);
    }
  }

  function resolveFocusRange(lineText, columnIndex) {
    var index = lineText.length ? Math.max(0, Math.min(lineText.length - 1, columnIndex)) : 0;
    var focusIndex = index;
    var offset;

    if (!TOKEN_CHAR.test(lineText.charAt(focusIndex))) {
      for (offset = 1; offset <= 24; offset++) {
        if (focusIndex - offset >= 0 && TOKEN_CHAR.test(lineText.charAt(focusIndex - offset))) {
          focusIndex = focusIndex - offset;
          break;
        }
        if (focusIndex + offset < lineText.length && TOKEN_CHAR.test(lineText.charAt(focusIndex + offset))) {
          focusIndex = focusIndex + offset;
          break;
        }
      }
    }

    var start = focusIndex;
    var end = Math.min(lineText.length, focusIndex + 1);

    if (TOKEN_CHAR.test(lineText.charAt(focusIndex))) {
      while (start > 0 && TOKEN_CHAR.test(lineText.charAt(start - 1))) {
        start -= 1;
      }
      while (end < lineText.length && TOKEN_CHAR.test(lineText.charAt(end))) {
        end += 1;
      }
    }

    return {
      start: start,
      end: Math.max(start + 1, end),
      index: index
    };
  }

  function extractSourceExcerpt(sourceText, url, line, column) {
    if (!sourceText || !line) {
      return null;
    }

    var lines = String(sourceText).split(/\r\n|\r|\n/);
    if (line < 1 || line > lines.length) {
      return null;
    }

    var lineText = String(lines[line - 1] || '');
    if (!lineText) {
      return null;
    }

    var focus = resolveFocusRange(lineText, Math.max(0, (column || 1) - 1));
    var beforeStart = Math.max(0, focus.start - 100);
    var afterEnd = Math.min(lineText.length, focus.end + 100);
    var focusText = lineText.slice(focus.start, focus.end);

    if (!focusText) {
      focusText = lineText.charAt(focus.index || 0) || '';
    }

    return {
      source_url: cleanUrl(url),
      line: normalizeNumber(line),
      column: normalizeNumber(column),
      has_prefix: beforeStart > 0,
      has_suffix: afterEnd < lineText.length,
      before: scrubSnippetText(lineText.slice(beforeStart, focus.start), 100),
      focus: scrubSnippetText(focusText, 80),
      after: scrubSnippetText(lineText.slice(focus.end, afterEnd), 100)
    };
  }

  function attachSourceExcerpt(event) {
    if (!event || !event.script_url || !event.line || !canCaptureSourceContext(event.script_url)) {
      return;
    }

    loadSourceText(event.script_url, function (sourceText) {
      var excerpt = extractSourceExcerpt(sourceText, event.script_url, event.line, event.column);
      if (excerpt) {
        event.source_excerpt = excerpt;
      }
    });
  }

  function createEvent(details, error) {
    details = details || {};

    var stackTrace = normalizeStack(error);
    var scriptUrl = cleanUrl(details.script_url || '');
    var line = normalizeNumber(details.line);
    var column = normalizeNumber(details.column);

    if ((!scriptUrl || !line) && stackTrace.length) {
      if (!scriptUrl) {
        scriptUrl = cleanUrl(stackTrace[0].url || '');
      }
      if (!line) {
        line = normalizeNumber(stackTrace[0].line);
      }
      if (!column) {
        column = normalizeNumber(stackTrace[0].column);
      }
    }

    return {
      meta: mergeMeta({
        dt: new Date().toISOString(),
        stream: 'client-runtime-error',
        shop_id: config.shopId
      }, mergeMeta(
        getErrorMeta(error),
        mergeMeta(
          sanitizeMeta({
            runtime_state: getRuntimeSnapshot()
          }),
          sanitizeMeta(details.meta || {})
        )
      )),
      severity: scrubText(details.severity || 'error', 20) || 'error',
      type: scrubText(details.type || 'runtime', 120) || 'runtime',
      message: scrubText(details.message || (error && error.message) || 'Client exception', 2000),
      url: getCurrentPageUrl(),
      referrer: referrerUrl,
      script_url: scriptUrl,
      line: line,
      column: column,
      stack_trace: stackTrace,
      tags: mergeMeta(sanitizeMeta(config.tags || {}), getDynamicPageState())
    };
  }

  function scheduleFlush() {
    if (queue.length >= maxEvents) {
      flush(false);
      return;
    }
    if (!flushTimer) {
      flushTimer = window.setTimeout(function () {
        flush(false);
      }, 3000);
    }
  }

  function enqueue(details, error) {
    var event;

    if (sent >= maxEvents) {
      info('max events reached, dropping event');
      return null;
    }

    event = createEvent(details, error);
    queue.push(event);
    sent += 1;
    info('queued event #' + sent, details && details.type ? details.type : 'runtime');
    scheduleFlush();
    attachSourceExcerpt(event);
    return event;
  }

  function sendWithFetch(payload) {
    nativeFetch(config.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: { 'Content-Type': 'application/json' },
      body: payload
    }).then(function (response) {
      if (!response.ok) {
        warn('jslog returned HTTP ' + response.status);
        return;
      }
      response.json().then(function (data) {
        if (!data) {
          return;
        }
        if (debugEnabled && data.debug) {
          info('server debug trace', data.debug);
        }
        if (data.status !== 'ok') {
          warn('jslog returned status "' + data.status + '"');
          return;
        }
        if (typeof data.stored === 'number' && data.stored < 1) {
          warn('jslog accepted payload but stored 0 events');
        }
      }).catch(function () {});
    }).catch(function (error) {
      warn('failed to send jslog: ' + (error && error.message ? error.message : error));
    });
  }

  function send(payload, useBeacon) {
    if (useBeacon && navigator.sendBeacon) {
      try {
        if (navigator.sendBeacon(config.endpoint, new Blob([payload], { type: 'application/json' }))) {
          return;
        }
      } catch (e) {}
    }

    sendWithFetch(payload);
  }

  function flush(useBeacon) {
    if (flushTimer) {
      window.clearTimeout(flushTimer);
      flushTimer = null;
    }
    if (!queue.length) {
      return;
    }
    if (!useBeacon && pendingSourceLoads > 0) {
      flushTimer = window.setTimeout(function () {
        flush(false);
      }, 400);
      return;
    }
    send(JSON.stringify({
      token: config.token,
      events: queue.splice(0, queue.length),
      visitor_id: visitorId,
      debug: debugEnabled
    }), !!useBeacon);
  }

  function shouldSkipRuntime(message, source, line, column, error) {
    var key = scrubText([message || '', source || '', line || 0, column || 0].join('|'), 500);
    var now = Date.now();

    if (error && error.__collectlogsWrappedHandled) {
      return true;
    }
    if (key && key === lastRuntimeKey && (now - lastRuntimeAt) < 1000) {
      return true;
    }

    lastRuntimeKey = key;
    lastRuntimeAt = now;
    return false;
  }

  function captureRuntime(message, source, line, column, error) {
    var cleanSource = cleanUrl(source || '');
    if (shouldSkipRuntime(message, cleanSource, line, column, error)) {
      return;
    }

    enqueue({
      severity: 'error',
      type: 'runtime',
      message: message || (error && error.message) || 'Runtime error',
      script_url: cleanSource,
      line: line,
      column: column,
      meta: {
        source_kind: !cleanSource || cleanSource === getCurrentPageUrl() ? 'document_or_inline' : 'external_script'
      }
    }, error);
  }

  function markWrappedError(error, meta) {
    if (!error || typeof error !== 'object') {
      return;
    }
    try {
      error.__collectlogsWrappedHandled = true;
      error.__collectlogsMeta = meta;
    } catch (e) {}
  }

  window.onerror = (function (original) {
    return function (message, source, line, column, error) {
      captureRuntime(message, source, line, column, error);
      if (typeof original === 'function') {
        return original.apply(window, arguments);
      }
      return false;
    };
  })(window.onerror);

  window.addEventListener('error', function (event) {
    var target = event && event.target;
    if (!target) {
      return;
    }

    if (event.error || event.message) {
      captureRuntime(event.message, event.filename, event.lineno, event.colno, event.error);
      if (target === window || target === document) {
        return;
      }
    }

    if (target === window || target === document) {
      return;
    }

    enqueue({
      severity: 'warn',
      type: 'resource',
      message: 'Resource failed to load: ' + scrubText(target.tagName || 'unknown', 40),
      script_url: target.currentSrc || target.src || target.href || '',
      meta: {
        source_kind: 'resource',
        tag_name: scrubText(target.tagName || 'unknown', 40)
      }
    });
  }, true);

  window.addEventListener('unhandledrejection', function (event) {
    var reason = event && event.reason;
    var rejectionType = typeof reason;
    if (reason && reason.constructor && reason.constructor.name) {
      rejectionType = reason.constructor.name;
    }
    enqueue({
      severity: 'error',
      type: 'unhandledrejection',
      message: reason && reason.message ? reason.message : String(reason || 'Unhandled promise rejection'),
      meta: {
        rejection_type: scrubText(rejectionType, 120)
      }
    }, reason && typeof reason === 'object' ? reason : null);
  });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flush(true);
    }
  });

  window.addEventListener('pagehide', function () {
    flush(true);
  });

  window.addEventListener('beforeunload', function () {
    flush(true);
  });

  window.collectlogsClient = window.collectlogsClient || {};
  window.collectlogsClient.captureException = function (error, details) {
    enqueue(details || {}, error);
  };
  window.collectlogsClient.wrap = function (fn, label) {
    if (typeof fn !== 'function') {
      return fn;
    }
    return function () {
      try {
        return fn.apply(this, arguments);
      } catch (error) {
        var wrappedLabel = scrubText(label || fn.name || 'anonymous', 120);
        markWrappedError(error, {
          wrapped_function: wrappedLabel,
          argument_summary: summarizeArgs(arguments)
        });
        enqueue({
          severity: 'error',
          type: 'wrapped_runtime',
          message: error && error.message ? error.message : 'Wrapped runtime error',
          meta: {
            wrapped_function: wrappedLabel,
            argument_summary: summarizeArgs(arguments)
          }
        }, error);
        throw error;
      }
    };
  };
  window.collectlogsWrap = window.collectlogsClient.wrap;
})(window, document);
