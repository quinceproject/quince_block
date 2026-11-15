/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 *
 * Bloki quince_block na froncie: odświeżanie po zmianie koszyka, leniwe
 * ładowanie, statystyki testu A/B, zapamiętanie wariantu i parametrów UTM.
 *
 * Bloki dodane do strony później (szuflada koszyka, doładowanie listy) łapie
 * MutationObserver. Ciasteczka zapisujemy tylko przy działającym quince_cmp
 * i po zgodzie na kategorię analityczną, przez jego publiczne API.
 */
(function () {
    'use strict';

    var cfg = window.quinceBlock || {};
    var SELECTOR = '.quince-block[data-qb-id]';
    var COOKIE_DAYS = 30;

    if (!cfg.render) {
        return;
    }

    var ready = typeof WeakSet === 'function' ? new WeakSet() : null;
    var viewed = typeof WeakSet === 'function' ? new WeakSet() : null;
    var clicked = typeof WeakSet === 'function' ? new WeakSet() : null;
    var lazyQueue = [];
    var lazyTimer = null;
    var refreshTimer = null;
    var refreshBusy = false;
    var refreshAgain = false;

    /* Zgoda i ciasteczka */

    function onConsent(fn) {
        if (!cfg.cmp) {
            return;
        }

        var done = false;

        function run() {
            if (!done) {
                done = true;
                fn();
            }
        }

        function viaApi() {
            var api = window.QuinceCMP;

            if (api && typeof api.whenAllowed === 'function') {
                api.whenAllowed(cfg.consent, run);

                return true;
            }

            return false;
        }

        if (viaApi()) {
            return;
        }

        // Skrypt quince_cmp może wczytać się po naszym.
        document.addEventListener('quince:consent', function (event) {
            var codes = (event.detail && event.detail.codes) || [];

            if (codes.indexOf(cfg.consent) !== -1) {
                run();
            }
        });
        window.addEventListener('load', viaApi);
    }

    function readCookie(name) {
        var parts = document.cookie ? document.cookie.split('; ') : [];

        for (var i = 0; i < parts.length; i++) {
            if (parts[i].indexOf(name + '=') === 0) {
                return decodeURIComponent(parts[i].substring(name.length + 1));
            }
        }

        return null;
    }

    function writeCookie(name, value) {
        document.cookie = name + '=' + encodeURIComponent(value)
            + '; path=/; max-age=' + (COOKIE_DAYS * 86400) + '; SameSite=Lax'
            + (window.location.protocol === 'https:' ? '; Secure' : '');
    }

    function rememberUtm() {
        if (!cfg.utm) {
            return;
        }

        var params = new URLSearchParams(window.location.search);
        var found = {};
        var any = false;

        ['source', 'medium', 'campaign', 'term', 'content'].forEach(function (name) {
            var value = params.get('utm_' + name);

            if (value) {
                found[name] = value.substring(0, 255);
                any = true;
            }
        });

        if (any) {
            onConsent(function () {
                writeCookie(cfg.utmCookie, JSON.stringify(found));
            });
        }
    }

    function rememberVariant(el) {
        if (el.getAttribute('data-qb-random') !== '1') {
            return;
        }

        var name = cfg.abCookie + el.getAttribute('data-qb-id');
        var variant = el.getAttribute('data-qb-variant') === 'b' ? 'b' : 'a';

        onConsent(function () {
            if (readCookie(name) === null) {
                writeCookie(name, variant);
            }
        });
    }

    /* Statystyki */

    function send(el, kind) {
        var token = el.getAttribute('data-qb-token');

        if (!token || !cfg.track) {
            return;
        }

        var data = new FormData();
        // Wariant jest w tokenie wyświetlenia; serwer bierze go stamtąd.
        data.append('id', el.getAttribute('data-qb-id'));
        data.append('kind', kind);
        data.append('token', token);

        if (navigator.sendBeacon && navigator.sendBeacon(cfg.track, data)) {
            return;
        }

        fetch(cfg.track, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true }).catch(function () {});
    }

    var viewObserver = typeof IntersectionObserver === 'function'
        ? new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }

                viewObserver.unobserve(entry.target);

                if (viewed && !viewed.has(entry.target)) {
                    viewed.add(entry.target);
                    send(entry.target, 'view');
                }
            });
        }, { threshold: 0.5 })
        : null;

    function watchViews(el) {
        if (!el.getAttribute('data-qb-token') || el.hasAttribute('data-qb-lazy') || el.hidden) {
            return;
        }

        rememberVariant(el);

        if (viewObserver && viewed && !viewed.has(el)) {
            viewObserver.observe(el);
        }
    }

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        var block = target ? target.closest(SELECTOR + '[data-qb-token]') : null;

        if (!block || !clicked || clicked.has(block)) {
            return;
        }

        var control = target.closest('a, button, [role="button"], input[type="submit"]');

        if (control && block.contains(control)) {
            clicked.add(block);
            send(block, 'click');
        }
    }, true);

    /* Podmiana treści */

    function runScripts(container) {
        // Skrypty wstawione przez innerHTML się nie wykonują, trzeba je odtworzyć.
        Array.prototype.forEach.call(container.querySelectorAll('script'), function (old) {
            var script = document.createElement('script');

            Array.prototype.forEach.call(old.attributes, function (attr) {
                script.setAttribute(attr.name, attr.value);
            });
            script.text = old.text;
            old.parentNode.replaceChild(script, old);
        });
    }

    function apply(el, result) {
        el.removeAttribute('data-qb-lazy');
        el.setAttribute('data-qb-variant', result.variant === 'b' ? 'b' : 'a');

        if (result.token) {
            el.setAttribute('data-qb-token', result.token);
        } else {
            el.removeAttribute('data-qb-token');
        }

        if (result.random) {
            el.setAttribute('data-qb-random', '1');
        } else {
            el.removeAttribute('data-qb-random');
        }

        el.innerHTML = result.visible ? result.html : '';
        el.hidden = !result.visible;

        if (result.visible) {
            runScripts(el);
            watchViews(el);
        }

        el.dispatchEvent(new CustomEvent('quince:block', { bubbles: true, detail: { id: result.id } }));
    }

    function request(elements) {
        var items = elements.map(function (el) {
            return {
                id: parseInt(el.getAttribute('data-qb-id'), 10) || 0,
                hook: el.getAttribute('data-qb-hook') || '',
                ctx: el.getAttribute('data-qb-ctx') || '',
                variant: el.getAttribute('data-qb-variant') || ''
            };
        });
        var body = new URLSearchParams();
        body.append('items', JSON.stringify(items));

        return fetch(cfg.render, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        })
            .then(function (response) {
                return response.ok ? response.json() : { blocks: [] };
            })
            .then(function (data) {
                (data.blocks || []).forEach(function (result) {
                    var el = elements[result.key];

                    if (el && el.isConnected) {
                        apply(el, result);
                    }
                });
            })
            .catch(function () {});
    }

    /* Leniwe ładowanie */

    function flushLazy() {
        lazyTimer = null;

        var batch = lazyQueue.splice(0, lazyQueue.length).filter(function (el) {
            return el.isConnected;
        });

        if (batch.length) {
            request(batch);
        }
    }

    function queueLazy(el) {
        if (lazyQueue.indexOf(el) === -1) {
            lazyQueue.push(el);
        }

        if (!lazyTimer) {
            lazyTimer = window.setTimeout(flushLazy, 50);
        }
    }

    var lazyObserver = typeof IntersectionObserver === 'function'
        ? new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    lazyObserver.unobserve(entry.target);
                    queueLazy(entry.target);
                }
            });
        }, { rootMargin: '200px 0px' })
        : null;

    /* Odświeżanie po zmianie koszyka */

    function refresh() {
        refreshTimer = null;

        if (refreshBusy) {
            refreshAgain = true;

            return;
        }

        var elements = Array.prototype.filter.call(
            document.querySelectorAll(SELECTOR + '[data-qb-refresh]'),
            function (el) {
                return !el.hasAttribute('data-qb-lazy');
            }
        );

        if (!elements.length) {
            return;
        }

        refreshBusy = true;
        request(elements).then(function () {
            refreshBusy = false;

            if (refreshAgain) {
                refreshAgain = false;
                refresh();
            }
        });
    }

    // Rdzeń i motyw ogłaszają zmianę koszyka dwoma zdarzeniami (`updateCart`,
    // `updatedCart`), często jedno po drugim. Zbieramy je w jedno odświeżenie.
    function scheduleRefresh() {
        if (refreshTimer) {
            window.clearTimeout(refreshTimer);
        }

        refreshTimer = window.setTimeout(refresh, 300);
    }

    function listenCart() {
        if (!cfg.refresh) {
            return true;
        }

        var shop = window.prestashop;

        if (!shop || typeof shop.on !== 'function') {
            return false;
        }

        // Na stronie koszyka rdzeń wysyła `updateCart`, a po przerysowaniu koszyka
        // `updatedCart`, nawet sekundę później. Tam czekamy tylko na to drugie,
        // bo dopiero wtedy koszyk ma nowe kwoty. Na pozostałych stronach wystarcza `updateCart`.
        var onCartPage = document.body && document.body.id === 'cart';
        shop.on(onCartPage ? 'updatedCart' : 'updateCart', scheduleRefresh);

        return true;
    }

    /* Inicjalizacja, także dla bloków dodanych później */

    function init(el) {
        if (ready) {
            if (ready.has(el)) {
                return;
            }

            ready.add(el);
        } else if (el.getAttribute('data-qb-ready')) {
            return;
        } else {
            el.setAttribute('data-qb-ready', '1');
        }

        if (el.hasAttribute('data-qb-lazy')) {
            if (lazyObserver) {
                lazyObserver.observe(el);
            } else {
                queueLazy(el);
            }

            return;
        }

        watchViews(el);
    }

    function scan(root) {
        if (root.nodeType !== 1) {
            return;
        }

        if (root.matches(SELECTOR)) {
            init(root);
        }

        Array.prototype.forEach.call(root.querySelectorAll(SELECTOR), init);
    }

    function start() {
        scan(document.body);
        rememberUtm();

        if (!listenCart()) {
            window.addEventListener('load', listenCart);
        }

        if (typeof MutationObserver === 'function') {
            new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    Array.prototype.forEach.call(mutation.addedNodes, scan);
                });
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
