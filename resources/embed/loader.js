/*!
 * Meetings Embed loader. Add one <script> tag, then call __GLOBAL__.meetings.embed({...}).
 *
 *   <div id="meeting" style="height:600px"></div>
 *   <script src="https://YOUR-HOST/api/dev/v1/embed/loader.js"></script>
 *   <script>
 *     const m = __GLOBAL__.meetings.embed({
 *       container: '#meeting',
 *       // Your server calls POST /api/dev/v1/embed/sessions with your API key and returns the result here.
 *       getToken: () => fetch('/my-backend/meeting-token').then(r => r.json()),   // string, or the whole { token, embed_url } object
 *       onReady:  (d) => console.log('joined as', d.participant),
 *       onError:  (d) => console.warn(d.code),
 *     });
 *   </script>
 *
 * The page you embed on must be in the workspace's allowed origins, otherwise the browser refuses to show the frame.
 */
(function (root) {
  'use strict';
  var NS = "__NS__";
  var FRAME_PATH = '/api/dev/v1/embed/frame';
  var script = document.currentScript;
  var DEFAULT_BASE = '';
  try { DEFAULT_BASE = script && script.src ? new URL(script.src).origin : ''; } catch (e) { DEFAULT_BASE = ''; }

  var G = root["__GLOBAL__"] = root["__GLOBAL__"] || {};
  G.meetings = G.meetings || {};

  function isSafeBase(u) {
    try {
      var x = new URL(u);
      return x.protocol === 'https:' || (x.protocol === 'http:' && (x.hostname === 'localhost' || x.hostname === '127.0.0.1'));
    } catch (e) { return false; }
  }

  G.meetings.embed = function (opts) {
    opts = opts || {};
    var el = typeof opts.container === 'string' ? document.querySelector(opts.container) : opts.container;
    if (!el || !el.appendChild) { throw new Error('embed: container not found'); }
    if (!opts.token && typeof opts.getToken !== 'function') { throw new Error('embed: pass token or getToken'); }

    var base = opts.baseUrl || DEFAULT_BASE;
    var listeners = {};
    var frameOrigin = '';
    var retries = 0, lastRetry = 0, destroyed = false;

    var iframe = document.createElement('iframe');
    iframe.title = opts.title || 'Meeting';
    iframe.setAttribute('allow', opts.allow || 'camera; microphone; display-capture; autoplay; fullscreen; clipboard-write');
    iframe.setAttribute('referrerpolicy', 'no-referrer');
    iframe.setAttribute('sandbox', opts.sandbox || 'allow-scripts allow-same-origin allow-forms allow-popups allow-modals allow-downloads');
    iframe.style.cssText = 'border:0;width:100%;height:100%;display:block;';
    if (opts.className) { iframe.className = opts.className; }
    el.appendChild(iframe);

    function emit(type, data) {
      (listeners[type] || []).slice().forEach(function (fn) { try { fn(data); } catch (e) { /* listener errors never break the loader */ } });
      var map = { ready: 'onReady', error: 'onError', left: 'onLeft', 'token-expired': 'onTokenExpired', 'session-expired': 'onSessionExpired' };
      if (map[type] && typeof opts[map[type]] === 'function') { try { opts[map[type]](data); } catch (e) { /* ignore */ } }
      if (typeof opts.onEvent === 'function') { try { opts.onEvent(type, data); } catch (e) { /* ignore */ } }
    }

    function resolve(t) {
      var token = t, b = base;
      if (t && typeof t === 'object') {
        token = t.token;
        if (t.embed_url) {                       // the server may hand back the branded host (custom domain)
          try {
            var u = new URL(t.embed_url);
            if (u.pathname === FRAME_PATH && isSafeBase(u.origin)) { b = u.origin; token = u.searchParams.get('token') || token; }
          } catch (e) { /* fall back to base */ }
        }
      }
      if (typeof token !== 'string' || !/^ejt_[A-Za-z0-9_-]{20,90}$/.test(token)) { throw new Error('embed: invalid join token'); }
      if (!b || !isSafeBase(b)) { throw new Error('embed: unknown host, pass baseUrl'); }
      return { token: token, base: b };
    }

    function load() {
      return Promise.resolve(opts.token || opts.getToken()).then(function (t) {
        if (destroyed) { return; }
        var r = resolve(t);
        frameOrigin = new URL(r.base).origin;
        iframe.src = r.base + FRAME_PATH + '?token=' + encodeURIComponent(r.token) + '&parent=' + encodeURIComponent(location.origin);
      }).catch(function (err) { emit('error', { code: 'token_unavailable', message: String(err && err.message || err) }); });
    }

    function onMessage(e) {
      if (destroyed || e.source !== iframe.contentWindow || e.origin !== frameOrigin) { return; }
      var m = e.data;
      if (!m || m.source !== NS + '-embed' || typeof m.type !== 'string') { return; }
      if (m.type === 'ready') { retries = 0; }
      emit(m.type, m.data || {});
      if (m.type === 'token-expired' && typeof opts.getToken === 'function' && opts.autoRefresh !== false) {
        var now = Date.now();
        if (retries < 2 && now - lastRetry > 3000) { retries++; lastRetry = now; load(); }
      }
    }
    window.addEventListener('message', onMessage);
    load();

    return {
      iframe: iframe,
      on: function (type, fn) { (listeners[type] = listeners[type] || []).push(fn); return this; },
      off: function (type, fn) { listeners[type] = (listeners[type] || []).filter(function (f) { return f !== fn; }); return this; },
      send: function (type, data) { if (frameOrigin && iframe.contentWindow) { iframe.contentWindow.postMessage({ source: NS + '-host', v: 1, type: type, data: data || {} }, frameOrigin); } },
      leave: function () { this.send('leave'); },
      reload: function () { retries = 0; return load(); },
      destroy: function () { destroyed = true; window.removeEventListener('message', onMessage); if (iframe.parentNode) { iframe.parentNode.removeChild(iframe); } }
    };
  };
})(window);
