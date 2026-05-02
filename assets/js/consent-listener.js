/* DB SEO Manager — Consent Listener
 *
 * Ascolta i cambi di consenso emessi dal Cookie Manager DB (evento
 * 'dbcm:consent' su document) e li propaga a Google Consent Mode v2 via
 * gtag('consent', 'update', ...) — senza ricaricare la pagina.
 *
 * Filosofia (vedi class-consent-gate.php):
 *  - Il SEO Manager NON carica dinamicamente GA4/GTM/Pixel se l'utente
 *    accetta dopo aver rifiutato. Gli script restano server-rendered:
 *    chi accetta vedrà i tracker al prossimo page-load.
 *  - L'unico aggiornamento dinamico legittimo è il signal di Consent Mode
 *    v2: è esattamente per questo che lo standard esiste (defaults emessi
 *    a 'denied' al primo render, poi 'update' quando arriva il consenso).
 *
 * Compatibilità:
 *  - Funziona con Cookie Manager DB 3.0.0+ (evento 'dbcm:consent').
 *  - Funziona anche se gtag non è ancora definito: in quel caso l'update
 *    è no-op (è il caso "utente alla prima visita ha rifiutato → accetta
 *    poi → GA4 verrà caricato server-side al prossimo reload").
 *  - Evento custom 'dbseo:consent_updated' rilanciato su document a fine
 *    propagazione: futuri plugin DB (Form Builder, ecc.) possono
 *    ascoltarlo senza dover sapere del Cookie Manager.
 *
 * Configurazione:
 *  - Stampato lato PHP solo se Consent Mode v2 è attivo (analytics_enabled
 *    + analytics_consent_mode + provider in ['ga4','gtm']).
 *  - Il mapping categorie → signals può essere modificato via filter PHP
 *    'dbseo_consent_mode_signal_map' (vedi class-consent-gate.php).
 */
(function () {
    'use strict';

    // Config iniettato via wp_localize_script (DBSEOConsent).
    if (typeof DBSEOConsent === 'undefined') return;

    var SIGNAL_MAP = DBSEOConsent.signalMap || {
        statistics: ['analytics_storage'],
        marketing:  ['ad_storage', 'ad_user_data', 'ad_personalization']
    };

    /**
     * Costruisce il payload per gtag('consent','update', payload).
     * Riceve il consent dall'evento 'dbcm:consent' (oggetto
     * { statistics: bool, marketing: bool, ... }) e lo trasforma nei
     * signals previsti da Google.
     *
     * Categoria assente / non mappata → ignorata (Google accetta payload
     * parziali).
     */
    function buildSignalPayload(consent) {
        var payload = {};
        for (var category in SIGNAL_MAP) {
            if (!Object.prototype.hasOwnProperty.call(SIGNAL_MAP, category)) continue;
            var signals = SIGNAL_MAP[category];
            if (!signals || !signals.length) continue;
            var value = consent[category] ? 'granted' : 'denied';
            for (var i = 0; i < signals.length; i++) {
                payload[signals[i]] = value;
            }
        }
        return payload;
    }

    /**
     * Emette il gtag('consent','update', payload) se gtag è disponibile.
     * Se gtag non è definito (utente non aveva ancora consentito → script
     * non caricato), spinge comunque l'update sul dataLayer: gtag.js
     * processerà la coda al primo carico.
     */
    function pushConsentUpdate(payload) {
        if (typeof window.gtag === 'function') {
            window.gtag('consent', 'update', payload);
            return true;
        }
        // Fallback: dataLayer queue. gtag.js stub standard.
        if (Array.isArray(window.dataLayer)) {
            // Stessa shape che gtag('consent','update',...) genera.
            window.dataLayer.push(['consent', 'update', payload]);
            return true;
        }
        return false;
    }

    /**
     * Handler del cambio consenso.
     */
    function onConsentChange(ev) {
        var detail = (ev && ev.detail) || {};
        var consent = detail.consent || {};
        var type    = detail.type || 'custom';

        var payload = buildSignalPayload(consent);
        var pushed  = pushConsentUpdate(payload);

        if (DBSEOConsent.debug && window.console) {
            window.console.log('[DBSEO] consent update', { type: type, payload: payload, pushed: pushed });
        }

        // Rilancia un evento DBSEO-specific così i consumatori dell'ecosistema
        // DB (futuri plugin) possono ascoltare un solo evento invece di sapere
        // del Cookie Manager. Detail invariata + payload Consent Mode v2.
        try {
            var out = new CustomEvent('dbseo:consent_updated', {
                detail: { type: type, consent: consent, signals: payload }
            });
            document.dispatchEvent(out);
        } catch (e) { /* old browser */ }
    }

    // Registra il listener. Il Cookie Manager emette su document.
    document.addEventListener('dbcm:consent', onConsentChange);

})();
