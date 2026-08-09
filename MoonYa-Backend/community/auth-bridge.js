(function () {
    'use strict';

    var authenticatedMeta = document.querySelector('meta[name="moonya-authenticated"]');
    var csrfMeta = document.querySelector('meta[name="moonya-community-csrf"]');
    var authenticated = authenticatedMeta && authenticatedMeta.content === '1';
    var csrfToken = csrfMeta ? csrfMeta.content : '';
    var nativeFetch = window.fetch.bind(window);

    function safeCommunityPath(value) {
        if (typeof value !== 'string') return null;
        try {
            var parsed = new URL(value, window.location.origin);
            if (parsed.origin !== window.location.origin || !parsed.pathname.startsWith('/community/')) return null;
            return parsed.pathname + parsed.search + parsed.hash;
        } catch (_) {
            return null;
        }
    }

    function currentReturnPath() {
        return safeCommunityPath(window.location.pathname + window.location.search + window.location.hash) || '/community/index.php';
    }

    function requireLogin(returnPath) {
        var safePath = safeCommunityPath(returnPath || currentReturnPath()) || '/community/index.php';
        if (window.opener && !window.opener.closed) {
            window.opener.postMessage({ type: 'moonya:auth-required', return_url: safePath }, window.location.origin);
            window.opener.focus();
            return false;
        }
        window.location.href = '../index.php?auth=1&return=' + encodeURIComponent(safePath);
        return false;
    }

    window.MoonYaCommunityAuth = {
        isLoggedIn: function () { return authenticated; },
        requireLogin: requireLogin,
        safeCommunityPath: safeCommunityPath
    };

    window.fetch = function (input, init) {
        var requestUrl = typeof input === 'string' || input instanceof URL ? String(input) : input.url;
        var resolved = new URL(requestUrl, window.location.href);
        var options = Object.assign({}, init || {});
        var method = String(options.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
        var isCommunityWrite = resolved.origin === window.location.origin
            && resolved.pathname.endsWith('/community/api/api.php')
            && method !== 'GET'
            && method !== 'HEAD'
            && method !== 'OPTIONS';

        if (isCommunityWrite) {
            var headers = new Headers(input instanceof Request ? input.headers : undefined);
            new Headers(options.headers || {}).forEach(function (value, key) { headers.set(key, value); });
            if (csrfToken) headers.set('X-CSRF-Token', csrfToken);
            options.headers = headers;
        }

        return nativeFetch(input, options).then(function (response) {
            if (isCommunityWrite && response.status === 401) requireLogin();
            return response;
        });
    };

    var nativeXhrOpen = XMLHttpRequest.prototype.open;
    var nativeXhrSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
        var resolved = new URL(String(url), window.location.href);
        this.__moonyaCommunityWrite = resolved.origin === window.location.origin
            && resolved.pathname.endsWith('/community/api/api.php')
            && !['GET', 'HEAD', 'OPTIONS'].includes(String(method).toUpperCase());
        return nativeXhrOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function () {
        if (this.__moonyaCommunityWrite && csrfToken) this.setRequestHeader('X-CSRF-Token', csrfToken);
        if (this.__moonyaCommunityWrite) {
            this.addEventListener('load', function () {
                if (this.status === 401) requireLogin();
            });
        }
        return nativeXhrSend.apply(this, arguments);
    };

    document.addEventListener('click', function (event) {
        var target = event.target.closest('[data-requires-auth]');
        if (!target || authenticated) return;
        event.preventDefault();
        event.stopPropagation();
        requireLogin(target.getAttribute('href') || currentReturnPath());
    }, true);

    window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin || !event.data || event.data.type !== 'moonya:auth-completed') return;
        window.location.reload();
    });
})();
