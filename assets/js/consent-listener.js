/* DB SEO Manager — Consent Loader & Listener
 *
 * Carica i tracker configurati (GA4, GTM, Matomo, Plausible, Umami, Meta
 * Pixel) SOLO dopo il consenso e propaga i cambi di consenso a Google
 * Consent Mode v2 via gtag('consent', 'update', ...) — senza ricaricare la
 * pagina.
 *
 * Perché lato client (dalla 1.5.0, vedi class-consent-gate.php):
 *  - L'HTML emesso dal PHP è identico per tutti i visitatori (Consent Mode
 *    default 'denied' + config del loader) ed è quindi sicuro con qualsiasi
 *    full-page cache. La decisione sul consenso avviene qui, nel browser
 *    del singolo visitatore.
 *
 * Fonti del consenso (in ordine di priorità):
 *  1. DB Cookie Manager: window.DBCM.hasConsent(), eventi 'dbcm:ready' e
 *     'dbcm:consent' su document.
 *  2. WP Consent API: wp_has_consent(), evento 'wp_listen_for_consent_change'.
 *  3. Cookiebot / Complianz: Cookiebot.consent o cookie standard.
 *  4. Default: DENIED.
 *
 * Il caricamento è idempotente: ogni servizio viene iniettato al massimo
 * una volta per pagina, indipendentemente da quante volte il consenso
 * viene rivalutato (ready, DOMContentLoaded, load, eventi di consenso).
 *
 * Evento custom 'dbseo:consent_updated' rilanciato su document dopo ogni
 * 'dbcm:consent': i plugin dell'ecosistema DB possono ascoltarlo senza
 * dover sapere del Cookie Manager.
 */
(function () {
    'use strict';

    // Config iniettata inline dal PHP (DBSEOConsent).
    if (typeof window.DBSEOConsent === 'undefined') return;
    var CFG = window.DBSEOConsent;

    var SIGNAL_MAP = CFG.signalMap || {
        statistics: ['analytics_storage'],
        marketing:  ['ad_storage', 'ad_user_data', 'ad_personalization']
    };
    var SERVICES = CFG.services || [];
    var loaded = {};
    var lastSignals = null;

    function log() {
        if (CFG.debug && window.console) {
            window.console.log.apply(window.console, ['[DBSEO]'].concat([].slice.call(arguments)));
        }
    }

    function has(obj, key) {
        return !!obj && Object.prototype.hasOwnProperty.call(obj, key);
    }

    function readCookie(name) {
        var parts = document.cookie ? document.cookie.split(';') : [];
        for (var i = 0; i < parts.length; i++) {
            var p = parts[i].replace(/^\s+/, '');
            if (p.indexOf(name + '=') === 0) {
                try {
                    return decodeURIComponent(p.substring(name.length + 1));
                } catch (e) {
                    return p.substring(name.length + 1);
                }
            }
        }
        return null;
    }

    function escapeRegExp(str) {
        return String(str).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * Consenso per una categoria WP Consent API.
     *
     * @param {string} category
     * @param {object} [snapshot] mappa categoria → bool dall'evento corrente.
     * @returns {boolean}
     */
    function hasConsent(category, snapshot) {
        if (has(snapshot, category)) return !!snapshot[category];

        // 1. DB Cookie Manager.
        if (window.DBCM && typeof window.DBCM.hasConsent === 'function') {
            return !!window.DBCM.hasConsent(category);
        }
        // 2. WP Consent API.
        if (typeof window.wp_has_consent === 'function') {
            return !!window.wp_has_consent(category);
        }
        // 3a. Cookiebot (oggetto JS).
        if (window.Cookiebot && window.Cookiebot.consent && has(window.Cookiebot.consent, category)) {
            return !!window.Cookiebot.consent[category];
        }
        // 3b. Cookiebot (cookie CookieConsent: {…,statistics:true,…}).
        var cb = readCookie('CookieConsent');
        if (cb && new RegExp(escapeRegExp(category) + ':true', 'i').test(cb)) return true;
        // 3c. Complianz (cmplz_<categoria> = allow).
        var cm = readCookie('cmplz_' + category);
        if (cm && ['allow', 'true', '1'].indexOf(String(cm).toLowerCase()) !== -1) return true;

        // 4. Default: DENIED.
        return false;
    }

    /* ------------------------------------------------------------------
     * Google Consent Mode v2
     * ---------------------------------------------------------------- */

    /**
     * Stub gtag standard: garantisce che i comandi finiscano nel dataLayer
     * nella forma corretta (oggetto arguments), anche prima del caricamento
     * di gtag.js/gtm.js. Un array semplice NON viene interpretato da Google.
     */
    function ensureGtag() {
        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
    }

    /**
     * Costruisce il payload per gtag('consent','update', payload) a partire
     * dal mapping categorie → signals. Categoria non mappata → ignorata
     * (Google accetta payload parziali).
     */
    function buildSignalPayload(snapshot) {
        var payload = {};
        for (var category in SIGNAL_MAP) {
            if (!has(SIGNAL_MAP, category)) continue;
            var signals = SIGNAL_MAP[category];
            if (!signals || !signals.length) continue;
            var value = hasConsent(category, snapshot) ? 'granted' : 'denied';
            for (var i = 0; i < signals.length; i++) {
                payload[signals[i]] = value;
            }
        }
        return payload;
    }

    /**
     * Emette gtag('consent','update', payload) solo se i signals sono
     * cambiati rispetto all'ultimo invio (lo stato iniziale è il default
     * 'denied' emesso dal PHP).
     */
    function pushConsentUpdate(snapshot) {
        var payload = buildSignalPayload(snapshot);
        var key = JSON.stringify(payload);

        if (lastSignals === null) {
            var denied = {};
            for (var k in payload) {
                if (has(payload, k)) denied[k] = 'denied';
            }
            lastSignals = JSON.stringify(denied);
        }
        if (key === lastSignals) return;

        lastSignals = key;
        ensureGtag();
        window.gtag('consent', 'update', payload);
        log('consent update', payload);
    }

    /* ------------------------------------------------------------------
     * Loader dei servizi
     * ---------------------------------------------------------------- */

    function injectScript(src, attrs) {
        var s = document.createElement('script');
        s.async = true;
        s.src = src;
        if (attrs) {
            for (var a in attrs) {
                if (has(attrs, a)) s.setAttribute(a, attrs[a]);
            }
        }
        var target = document.head || document.getElementsByTagName('head')[0] || document.documentElement;
        target.appendChild(s);
    }

    var LOADERS = {
        ga4: function (p) {
            ensureGtag();
            window.gtag('js', new Date());
            window.gtag('config', p.id, p.config || {});
            injectScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(p.id));
        },
        gtm: function (p) {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
            injectScript('https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(p.id));
        },
        matomo: function (p) {
            var _paq = window._paq = window._paq || [];
            _paq.push(['disableCookies']); // privacy-friendly
            _paq.push(['setDoNotTrack', true]);
            _paq.push(['enableHeartBeatTimer']);
            _paq.push(['trackPageView']);
            _paq.push(['enableLinkTracking']);
            _paq.push(['setTrackerUrl', p.url + 'matomo.php']);
            _paq.push(['setSiteId', String(p.siteId)]);
            injectScript(p.url + 'matomo.js');
        },
        plausible: function (p) {
            injectScript(p.src, { 'data-domain': p.domain });
        },
        umami: function (p) {
            injectScript(p.src, { 'data-website-id': p.websiteId });
        },
        meta_pixel: function (p) {
            if (!window.fbq) {
                var n = window.fbq = function () {
                    if (n.callMethod) {
                        n.callMethod.apply(n, arguments);
                    } else {
                        n.queue.push(arguments);
                    }
                };
                if (!window._fbq) window._fbq = n;
                n.push = n;
                n.loaded = true;
                n.version = '2.0';
                n.queue = [];
                injectScript('https://connect.facebook.net/en_US/fbevents.js');
            }
            window.fbq('init', p.id);
            window.fbq('track', 'PageView');
        }
    };

    /**
     * Rivaluta il consenso: aggiorna Consent Mode (prima dei tag, così
     * gtag.js parte già con i signals corretti) e carica i servizi
     * consentiti non ancora caricati. Idempotente.
     */
    function evaluate(snapshot) {
        if (CFG.consentMode) {
            pushConsentUpdate(snapshot);
        }
        for (var i = 0; i < SERVICES.length; i++) {
            var svc = SERVICES[i];
            if (!svc || loaded[svc.service] || !LOADERS[svc.service]) continue;
            if (!hasConsent(svc.category, snapshot)) continue;
            loaded[svc.service] = true;
            try {
                LOADERS[svc.service](svc.params || {});
                log('loaded', svc.service);
            } catch (e) {
                if (window.console) window.console.warn('[DBSEO] errore caricamento ' + svc.service, e);
            }
        }
    }

    /* ------------------------------------------------------------------
     * Listener
     * ---------------------------------------------------------------- */

    // DB Cookie Manager: cambio consenso (detail.consent = { categoria: bool }).
    document.addEventListener('dbcm:consent', function (ev) {
        var detail  = (ev && ev.detail) || {};
        var consent = detail.consent || {};
        var type    = detail.type || 'custom';

        evaluate(consent);

        // Rilancia un evento DBSEO-specific così i consumatori dell'ecosistema
        // DB possono ascoltare un solo evento invece di sapere del Cookie
        // Manager. Detail invariata + payload Consent Mode v2.
        try {
            document.dispatchEvent(new CustomEvent('dbseo:consent_updated', {
                detail: { type: type, consent: consent, signals: buildSignalPayload(consent) }
            }));
        } catch (e) { /* old browser */ }
    });

    // DB Cookie Manager: API pubblica pronta (anche con JS ritardato dagli ottimizzatori).
    document.addEventListener('dbcm:ready', function () { evaluate(); });

    // WP Consent API: detail = { categoria: 'allow' | 'deny' } (solo le categorie cambiate).
    function onWpConsentChange(ev) {
        var changed  = (ev && ev.detail) || {};
        var snapshot = {};
        for (var cat in changed) {
            if (has(changed, cat)) snapshot[cat] = changed[cat] === 'allow';
        }
        evaluate(snapshot);
    }
    document.addEventListener('wp_listen_for_consent_change', onWpConsentChange);
    window.addEventListener('wp_listen_for_consent_change', onWpConsentChange);

    // Cookiebot.
    window.addEventListener('CookiebotOnConsentReady', function () { evaluate(); });

    // Consenso già salvato: valuta subito e poi quando le API dei consent
    // manager sono sicuramente disponibili.
    evaluate();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { evaluate(); });
    }
    window.addEventListener('load', function () { evaluate(); });

})();
