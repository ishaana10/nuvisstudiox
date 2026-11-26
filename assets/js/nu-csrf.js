/* Adds the session CSRF token to every same-origin state-changing request (fetch and XMLHttpRequest).
   The server refuses signed-in POST/PUT/PATCH/DELETE calls without it (core/SecurityGate.php). */
(function () {
  'use strict';
  function token() { return window.nuCsrfToken || ''; }
  function unsafe(m) { m = String(m || 'GET').toUpperCase(); return m !== 'GET' && m !== 'HEAD' && m !== 'OPTIONS'; }
  function sameOrigin(u) {
    try { return new URL(u, location.href).origin === location.origin; } catch (e) { return true; }
  }

  var nativeFetch = window.fetch;
  if (nativeFetch) {
    window.fetch = function (input, init) {
      try {
        var isReq = typeof Request !== 'undefined' && input instanceof Request;
        var method = (init && init.method) || (isReq ? input.method : 'GET');
        var url = isReq ? input.url : String(input);
        if (unsafe(method) && sameOrigin(url) && token()) {
          init = init || {};
          var h = new Headers(init.headers || (isReq ? input.headers : undefined) || {});
          if (!h.has('X-CSRF-Token')) h.set('X-CSRF-Token', token());
          init.headers = h;
        }
      } catch (e) { /* fall through to the plain call */ }
      return nativeFetch.call(this, input, init);
    };
  }

  var open = XMLHttpRequest.prototype.open, send = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, url) {
    this._nuUnsafe = unsafe(method) && sameOrigin(url);
    return open.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function () {
    if (this._nuUnsafe && token()) {
      try { this.setRequestHeader('X-CSRF-Token', token()); } catch (e) { /* already set or not opened */ }
    }
    return send.apply(this, arguments);
  };
})();
