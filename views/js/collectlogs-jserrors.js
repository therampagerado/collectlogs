/**
 * CollectLogs – client-side JavaScript error logger
 * Copyright (C) 2017-2024 thirty bees
 * License: AFL 3.0
 *
 * Captures runtime errors, unhandled promise rejections and resource load
 * failures.  Batches them and sends to the server via sendBeacon / fetch /
 * XHR fallback.  Stack traces are normalised across Chrome, Firefox, Safari,
 * and older Opera/IE/Edge formats.
 *
 * Configuration is injected by the PHP module via Media::addJsDef into the
 * global object  window.collectlogsJsCfg  before this script loads.
 *
 * Expected shape:
 * {
 *   endpoint   : "/module/collectlogs/jslog",
 *   token      : "<signed-token>",
 *   shopId     : 1,
 *   sampling   : 100,          // 1-100  (%)
 *   maxEvents  : 10,
 *   includeQS  : false,
 *   includeStack : true,
 *   tags       : { tb_version, theme, page_type, controller, currency, lang }
 * }
 */
(function (w, d) {
    'use strict';

    /* -------------------------------------------------------------------------
     * Bootstrap – read config injected by PHP
     * ---------------------------------------------------------------------- */
    var cfg = w.collectlogsJsCfg;
    if (!cfg || !cfg.endpoint || !cfg.token) {
        return; // module disabled or config missing
    }

    // Sampling: skip this session entirely based on random draw
    if (cfg.sampling < 100 && Math.random() * 100 > cfg.sampling) {
        return;
    }

    var endpoint  = cfg.endpoint;
    var token     = cfg.token;
    var maxEvents = cfg.maxEvents  || 10;
    var includeQS    = !!cfg.includeQS;
    var includeStack = (cfg.includeStack !== false); // default true

    /* -------------------------------------------------------------------------
     * Queue & flush logic
     * ---------------------------------------------------------------------- */
    var queue        = [];
    var flushed      = false;
    var flushTimer   = null;
    var FLUSH_DELAY  = 5000; // ms between automatic flushes

    function enqueue(event) {
        if (queue.length >= maxEvents) {
            return;
        }
        queue.push(event);
        scheduleFlush();
    }

    function scheduleFlush() {
        if (flushTimer || flushed) {
            return;
        }
        flushTimer = setTimeout(flush, FLUSH_DELAY);
    }

    function flush() {
        clearTimeout(flushTimer);
        flushTimer = null;
        if (!queue.length) {
            return;
        }
        var events = queue.slice();
        queue = [];
        send(events);
    }

    function flushAndStop() {
        flush();
        flushed = true; // no more enqueuing after page hides/unloads
    }

    /* -------------------------------------------------------------------------
     * Send helpers  (sendBeacon → fetch+keepalive → XHR)
     * ---------------------------------------------------------------------- */
    function send(events) {
        var body = JSON.stringify({ token: token, events: events });
        var ct   = 'application/json';

        // Prefer sendBeacon (works on page unload, no CORS preflight for simple
        // types – but we need JSON, so we use a Blob with the correct type).
        if (navigator.sendBeacon) {
            var blob = new Blob([body], { type: ct });
            if (navigator.sendBeacon(endpoint, blob)) {
                return;
            }
        }

        // Fetch with keepalive (supported in modern browsers)
        if (w.fetch) {
            try {
                w.fetch(endpoint, {
                    method      : 'POST',
                    headers     : { 'Content-Type': ct },
                    body        : body,
                    keepalive   : true,
                    credentials : 'omit'
                });
                return;
            } catch (e) { /* fall through */ }
        }

        // XHR fallback (synchronous only on unload to guarantee delivery)
        try {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', endpoint, true); // async
            xhr.setRequestHeader('Content-Type', ct);
            xhr.send(body);
        } catch (e) { /* silently ignore */ }
    }

    /* -------------------------------------------------------------------------
     * Stack-trace normalisation
     * Adapted from the cross-browser stacktrace parsing approach (Wikipedia /
     * stacktrace.js).  Handles Chrome/V8, Gecko (Firefox), WinJS, and Opera.
     * ---------------------------------------------------------------------- */

    var CHROME_RE  = /^\s*at (?:(.+?) \()?(?:(.+?):(\d+):(\d+)|([^)]+))\)?\s*$/;
    var GECKO_RE   = /^(?:\s*([^@]*)(?:\(.*?\))?@)?(.*?):(\d+)(?::(\d+))?\s*$/;
    var WINJS_RE   = /^\s*at (?:(.+?) \()?(?:([^(]+?):(\d+):(\d+)?)\)?\s*$/;

    function parseStack(error) {
        if (!error) { return []; }

        var raw = error.stack || error.stacktrace || '';
        if (!raw) { return []; }

        var lines  = raw.split('\n');
        var frames = [];

        for (var i = 0; i < lines.length && frames.length < 20; i++) {
            var line = lines[i];
            var m;

            if ((m = CHROME_RE.exec(line))) {
                frames.push({
                    func : m[1]  || '<anonymous>',
                    url  : m[2]  || m[5] || '',
                    line : m[3]  ? +m[3] : null,
                    col  : m[4]  ? +m[4] : null
                });
            } else if ((m = GECKO_RE.exec(line))) {
                frames.push({
                    func : m[1] || '<anonymous>',
                    url  : m[2] || '',
                    line : m[3] ? +m[3] : null,
                    col  : m[4] ? +m[4] : null
                });
            } else if ((m = WINJS_RE.exec(line))) {
                frames.push({
                    func : m[1] || '<anonymous>',
                    url  : m[2] || '',
                    line : m[3] ? +m[3] : null,
                    col  : m[4] ? +m[4] : null
                });
            }
            // Lines that don't match any pattern are silently skipped.
        }
        return frames;
    }

    /* -------------------------------------------------------------------------
     * PII masking helpers
     * ---------------------------------------------------------------------- */
    var EMAIL_RE = /[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/g;
    var PHONE_RE = /(?:\+?\d[\s\-.]?){7,}/g;

    function maskPii(str) {
        if (typeof str !== 'string') { return str; }
        return str.replace(EMAIL_RE, '[email]').replace(PHONE_RE, '[phone]');
    }

    function sanitizeUrl(url) {
        if (typeof url !== 'string') { return ''; }
        url = maskPii(url);
        if (!includeQS) {
            var qIdx = url.indexOf('?');
            if (qIdx !== -1) { url = url.substring(0, qIdx); }
        }
        return url.substring(0, 2048);
    }

    /* -------------------------------------------------------------------------
     * Build a normalised event object
     * ---------------------------------------------------------------------- */
    function buildEvent(opts) {
        /* opts: { type, severity, message, errorType, error, url, line, col } */
        var frames    = (includeStack && opts.error) ? parseStack(opts.error) : [];
        var topFrame  = frames[0] || null;

        return {
            type       : opts.type      || 'error',
            severity   : opts.severity  || 'error',
            message    : maskPii(String(opts.message || '').substring(0, 1000)),
            error_type : String(opts.errorType || '').substring(0, 128),
            url        : sanitizeUrl(opts.url || w.location.href),
            referrer   : sanitizeUrl(d.referrer),
            user_agent : navigator.userAgent.substring(0, 512),
            dt_iso     : new Date().toISOString(),
            stack_trace: frames,
            script_url : topFrame ? sanitizeUrl(topFrame.url) : sanitizeUrl(opts.scriptUrl || ''),
            line       : topFrame ? topFrame.line : (opts.line || null),
            col        : topFrame ? topFrame.col  : (opts.col  || null),
            tags       : cfg.tags || {}
        };
    }

    /* -------------------------------------------------------------------------
     * window.onerror – runtime errors
     * ---------------------------------------------------------------------- */
    var prevOnerror = w.onerror;

    w.onerror = function (message, source, lineno, colno, error) {
        // Call any previously-registered handler first
        if (typeof prevOnerror === 'function') {
            prevOnerror.apply(this, arguments);
        }

        enqueue(buildEvent({
            type      : 'error',
            severity  : 'error',
            message   : message,
            errorType : (error && error.name) ? error.name : 'Error',
            error     : error,
            url       : w.location.href,
            scriptUrl : source,
            line      : lineno,
            col       : colno
        }));

        return false; // do not suppress default browser error handling
    };

    /* -------------------------------------------------------------------------
     * Unhandled promise rejections
     * ---------------------------------------------------------------------- */
    w.addEventListener('unhandledrejection', function (evt) {
        var reason = evt.reason;
        var message, errorType, error;

        if (reason instanceof Error) {
            message   = reason.message;
            errorType = reason.name;
            error     = reason;
        } else if (typeof reason === 'string') {
            message   = reason;
            errorType = 'UnhandledRejection';
        } else {
            try {
                message = JSON.stringify(reason);
            } catch (e) {
                message = String(reason);
            }
            errorType = 'UnhandledRejection';
        }

        enqueue(buildEvent({
            type      : 'unhandledrejection',
            severity  : 'error',
            message   : message,
            errorType : errorType,
            error     : error,
            url       : w.location.href
        }));
    }, true);

    /* -------------------------------------------------------------------------
     * Resource load failures (script, link, img, etc.)
     * useCapture=true is required because load errors don't bubble.
     * ---------------------------------------------------------------------- */
    w.addEventListener('error', function (evt) {
        var target = evt.target || evt.srcElement;
        if (!target || !target.tagName) {
            return; // not a resource error
        }
        var tagName = target.tagName.toUpperCase();
        if (tagName === 'SCRIPT' || tagName === 'LINK' || tagName === 'IMG') {
            var src = target.src || target.href || '';
            enqueue(buildEvent({
                type      : 'resource',
                severity  : 'warn',
                message   : 'Failed to load ' + tagName.toLowerCase() + ': ' + sanitizeUrl(src),
                errorType : 'ResourceError',
                url       : w.location.href,
                scriptUrl : src
            }));
        }
    }, true /* useCapture */);

    /* -------------------------------------------------------------------------
     * Flush on visibility/unload events to capture queued events
     * ---------------------------------------------------------------------- */
    d.addEventListener('visibilitychange', function () {
        if (d.visibilityState === 'hidden') {
            flushAndStop();
        }
    });

    w.addEventListener('pagehide', flushAndStop);
    // beforeunload is unreliable on mobile; pagehide is preferred.

}(window, document));
