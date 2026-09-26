<?php
/**
 * DBSEO_Privacy_Declarations — Dichiarazione trattamenti al registro unificato.
 *
 * Dichiara al DB Privacy Hub (filter `dbph_processing_register`) i
 * trattamenti effettivamente attivi in base alla configurazione del sito.
 * Le voci compaiono in "Privacy → Registro trattamenti" e nella Privacy
 * Policy generata dall'Hub.
 *
 * Solo `dbph_processing_register`, NON il legacy `dbseo_processing_register`:
 * il SEO Manager era il proprietario del filter legacy e dalla 1.3.0 non lo
 * applica più; oggi lo applica solo l'Hub (DBPH_Deprecated_Aliases, merge a
 * priorità 999 con de-duplica per id). Agganciarsi a entrambi non
 * aggiungerebbe nulla: stesso consumatore, stesse voci.
 *
 * Dalla 1.3.0 alla 1.4.x queste dichiarazioni erano andate perse con la
 * rimozione di DBSEO_Privacy (inc/class-privacy.php non più caricato):
 * questa classe le ripristina sul canale corretto.
 *
 * Voci dinamiche (id con prefisso dbseo_):
 *  - dbseo_monitor_404  monitor 404 attivo
 *  - dbseo_indexnow     IndexNow attivo
 *  - dbseo_analytics    provider analytics configurato (GA4/GTM/Matomo/Plausible/Umami)
 *  - dbseo_meta_pixel   Meta Pixel configurato
 *
 * Non dichiarati perché non trattano dati personali: SEO on-page, sitemap,
 * redirect (solo contatori aggregati), meta tag di verifica dei motori di
 * ricerca (stringhe statiche nell'HTML, nessuna chiamata esterna).
 *
 * Con Hard Privacy attivo (DBSEO_Core::is_hard_privacy()) analytics, Pixel e
 * IndexNow sono sospesi e quindi non dichiarati.
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Privacy_Declarations {

    public static function init() {
        add_filter('dbph_processing_register', array(__CLASS__, 'declare_processing'), 10, 1);
    }

    /**
     * @param array $register
     * @return array
     */
    public static function declare_processing($register) {
        $register = (array) $register;
        $core     = DBSEO_Core::instance();
        $hard     = DBSEO_Core::is_hard_privacy();

        if ($core->get('monitor_404_enabled')) {
            $register[] = self::build_monitor_404_entry($core);
        }

        if (!$hard && $core->get('indexnow_enabled')) {
            $register[] = self::build_indexnow_entry();
        }

        if (!$hard && $core->get('analytics_enabled')) {
            $entry = self::build_analytics_entry($core);
            if ($entry !== null) {
                $register[] = $entry;
            }
        }

        if (!$hard && $core->get('meta_pixel_enabled') && trim((string) $core->get('meta_pixel_id')) !== '') {
            $register[] = self::build_meta_pixel_entry();
        }

        return $register;
    }

    /* =====================================================================
     * Builder
     * ================================================================== */

    private static function build_monitor_404_entry($core) {
        return array(
            'id'             => 'dbseo_monitor_404',
            'label'          => __('Monitoraggio errori 404 (DB SEO Manager)', 'db-seo-manager'),
            'status'         => 'active',
            'purpose'        => __('Individuare URL non funzionanti e link rotti per correggerli o creare redirect, migliorando la qualità del sito.', 'db-seo-manager'),
            'legal_basis'    => __('Legittimo interesse del titolare alla manutenzione tecnica del sito (art. 6.1.f GDPR).', 'db-seo-manager'),
            'data_collected' => __('Percorso dell\'URL richiesto e della pagina di provenienza (referer), SENZA query string (parametri come token, email, fbclid/gclid vengono scartati), conteggio aggregato e data dell\'ultima occorrenza. Nessun indirizzo IP, nessuno user-agent, nessun cookie. I 404 generati dagli amministratori non vengono registrati.', 'db-seo-manager'),
            'retention'      => sprintf(
                /* translators: 1: giorni di conservazione, 2: numero massimo di righe */
                __('Cancellazione automatica (WP-Cron giornaliero) delle voci non più viste da %1$d giorni; tetto massimo di %2$d righe con eliminazione delle meno rilevanti.', 'db-seo-manager'),
                max(1, (int) $core->get('monitor_404_keep_days', 30)),
                max(100, (int) $core->get('monitor_404_max_rows', 5000))
            ),
            'transfers'      => __('Nessuno. Dati salvati solo nel database WordPress locale.', 'db-seo-manager'),
        );
    }

    private static function build_indexnow_entry() {
        return array(
            'id'             => 'dbseo_indexnow',
            'label'          => __('Notifica IndexNow ai motori di ricerca (DB SEO Manager)', 'db-seo-manager'),
            'status'         => 'active',
            'purpose'        => __('Notificare ai motori di ricerca compatibili gli URL pubblici appena pubblicati o aggiornati, per un\'indicizzazione più rapida.', 'db-seo-manager'),
            'legal_basis'    => __('Legittimo interesse del titolare alla visibilità del sito (art. 6.1.f GDPR). Nessun dato personale dei visitatori è coinvolto.', 'db-seo-manager'),
            'data_collected' => __('Solo URL pubblici delle pagine del sito e la chiave IndexNow del sito. Invio server-to-server: nessun cookie, nessun dato dei visitatori.', 'db-seo-manager'),
            'retention'      => __('Coda locale degli URL: voci inviate eliminate automaticamente dopo 7 giorni.', 'db-seo-manager'),
            'transfers'      => __('URL pubblici inviati all\'endpoint api.indexnow.org e condivisi con i motori aderenti al protocollo (es. Microsoft Bing – USA, Yandex – Russia, Seznam – Rep. Ceca, Naver – Corea del Sud). Non si tratta di dati personali.', 'db-seo-manager'),
        );
    }

    /**
     * Voce analytics per il provider configurato, o null se il provider
     * non è configurato.
     *
     * @param DBSEO_Core $core
     * @return array|null
     */
    private static function build_analytics_entry($core) {
        $provider = (string) $core->get('analytics_provider', 'none');
        $id       = trim((string) $core->get('analytics_id'));
        if ($provider === 'none' || $id === '') {
            return null;
        }

        $consent_basis = __('Consenso dell\'interessato (art. 6.1.a GDPR e art. 122 Codice Privacy), raccolto tramite il banner cookie: lo script viene caricato solo dopo il consenso alla categoria "statistiche".', 'db-seo-manager');

        switch ($provider) {
            case 'ga4':
                $consent_mode = (bool) $core->get('analytics_consent_mode');
                return array(
                    'id'             => 'dbseo_analytics',
                    'label'          => __('Statistiche di navigazione con Google Analytics 4 (DB SEO Manager)', 'db-seo-manager'),
                    'status'         => 'active',
                    'purpose'        => __('Misurazione del traffico e analisi statistica dell\'uso del sito.', 'db-seo-manager'),
                    'legal_basis'    => $consent_basis,
                    'data_collected' => $consent_mode
                        ? __('Identificatori online pseudonimi (cookie _ga, _ga_*), indirizzo IP (troncato da Google per gli utenti UE), user-agent e dati del dispositivo, pagine visitate, referer, eventi di interazione. Google Consent Mode v2 attivo: default "denied" finché l\'utente non acconsente.', 'db-seo-manager')
                        : __('Identificatori online pseudonimi (cookie _ga, _ga_*), indirizzo IP (troncato da Google per gli utenti UE), user-agent e dati del dispositivo, pagine visitate, referer, eventi di interazione.', 'db-seo-manager'),
                    'retention'      => __('Dati a livello di utente/evento conservati da Google secondo l\'impostazione della proprietà GA4 (2 o 14 mesi); cookie _ga fino a 2 anni.', 'db-seo-manager'),
                    'transfers'      => __('Google Ireland Ltd (UE) come responsabile del trattamento; possibile trasferimento extra-UE verso Google LLC (USA), aderente all\'EU-US Data Privacy Framework (decisione di adeguatezza), con Clausole Contrattuali Standard (SCC) come garanzia aggiuntiva.', 'db-seo-manager'),
                );

            case 'gtm':
                return array(
                    'id'             => 'dbseo_analytics',
                    'label'          => __('Google Tag Manager (DB SEO Manager)', 'db-seo-manager'),
                    'status'         => 'active',
                    'purpose'        => __('Caricamento e gestione dei tag di misurazione configurati nel contenitore GTM.', 'db-seo-manager'),
                    'legal_basis'    => $consent_basis,
                    'data_collected' => __('Indirizzo IP e user-agent necessari al caricamento del contenitore; ulteriori dati (identificatori, eventi, pagine visitate) dipendono dai tag configurati nel contenitore, da documentare separatamente.', 'db-seo-manager'),
                    'retention'      => __('Definita dai singoli servizi caricati tramite GTM.', 'db-seo-manager'),
                    'transfers'      => __('Google Ireland Ltd (UE); possibile trasferimento extra-UE verso Google LLC (USA), aderente all\'EU-US Data Privacy Framework, con Clausole Contrattuali Standard (SCC). Ulteriori destinatari in base ai tag del contenitore.', 'db-seo-manager'),
                );

            case 'matomo':
                list($url) = array_pad(explode('|', $id, 2), 2, '');
                $host = (string) wp_parse_url(trim($url), PHP_URL_HOST);
                return array(
                    'id'             => 'dbseo_analytics',
                    'label'          => __('Statistiche di navigazione con Matomo (DB SEO Manager)', 'db-seo-manager'),
                    'status'         => 'active',
                    'purpose'        => __('Misurazione del traffico e analisi statistica dell\'uso del sito.', 'db-seo-manager'),
                    'legal_basis'    => $consent_basis,
                    'data_collected' => __('Pagine visitate, referer, dati tecnici del dispositivo e indirizzo IP (anonimizzabile nelle impostazioni Matomo). Tracciamento senza cookie (disableCookies) e con rispetto del Do Not Track.', 'db-seo-manager'),
                    'retention'      => __('Definita nelle impostazioni dell\'istanza Matomo (cancellazione automatica dei log grezzi configurabile).', 'db-seo-manager'),
                    'transfers'      => $host !== ''
                        ? sprintf(
                            /* translators: %s: host dell'istanza Matomo */
                            __('Dati inviati all\'istanza Matomo su %s. Nessun trasferimento extra-UE se l\'istanza è ospitata nello SEE; in caso contrario verificare le garanzie del fornitore di hosting.', 'db-seo-manager'),
                            $host
                        )
                        : __('Dati inviati all\'istanza Matomo configurata. Nessun trasferimento extra-UE se l\'istanza è ospitata nello SEE.', 'db-seo-manager'),
                );

            case 'plausible':
                $src  = (string) apply_filters('dbseo_plausible_script_url', 'https://plausible.io/js/script.js');
                $host = strtolower((string) wp_parse_url($src, PHP_URL_HOST));
                $is_cloud = ($host === 'plausible.io' || substr($host, -13) === '.plausible.io');
                return array(
                    'id'             => 'dbseo_analytics',
                    'label'          => __('Statistiche di navigazione con Plausible Analytics (DB SEO Manager)', 'db-seo-manager'),
                    'status'         => 'active',
                    'purpose'        => __('Misurazione aggregata del traffico, senza profilazione.', 'db-seo-manager'),
                    'legal_basis'    => $consent_basis,
                    'data_collected' => __('Pagine visitate, referer, browser, sistema operativo, tipo di dispositivo e Paese (ricavato dall\'IP, che non viene memorizzato). Nessun cookie, nessun identificatore persistente: dati solo aggregati.', 'db-seo-manager'),
                    'retention'      => $is_cloud
                        ? __('Statistiche aggregate conservate da Plausible Insights OÜ per la durata dell\'account del titolare.', 'db-seo-manager')
                        : __('Definita dall\'amministratore dell\'istanza Plausible self-hosted.', 'db-seo-manager'),
                    'transfers'      => $is_cloud
                        ? __('Plausible Insights OÜ (Estonia, UE), server nell\'UE. Nessun trasferimento extra-UE.', 'db-seo-manager')
                        : sprintf(
                            /* translators: %s: host dell'istanza Plausible */
                            __('Istanza Plausible self-hosted su %s. Nessun trasferimento extra-UE se ospitata nello SEE.', 'db-seo-manager'),
                            $host !== '' ? $host : '—'
                        ),
                );

            case 'umami':
                list($script) = array_pad(explode('|', $id, 2), 2, '');
                $host = (string) wp_parse_url(trim($script), PHP_URL_HOST);
                return array(
                    'id'             => 'dbseo_analytics',
                    'label'          => __('Statistiche di navigazione con Umami (DB SEO Manager)', 'db-seo-manager'),
                    'status'         => 'active',
                    'purpose'        => __('Misurazione aggregata del traffico, senza profilazione.', 'db-seo-manager'),
                    'legal_basis'    => $consent_basis,
                    'data_collected' => __('Pagine visitate, referer, browser, sistema operativo, tipo di dispositivo e Paese. Nessun cookie; identificatore di sessione derivato da hash giornaliero non persistente.', 'db-seo-manager'),
                    'retention'      => __('Definita dall\'amministratore dell\'istanza Umami.', 'db-seo-manager'),
                    'transfers'      => sprintf(
                        /* translators: %s: host dell'istanza Umami */
                        __('Dati inviati all\'istanza Umami su %s. Nessun trasferimento extra-UE se ospitata nello SEE (Umami Cloud: verificare la regione scelta e le garanzie del fornitore).', 'db-seo-manager'),
                        $host !== '' ? $host : '—'
                    ),
                );
        }

        return null;
    }

    private static function build_meta_pixel_entry() {
        return array(
            'id'             => 'dbseo_meta_pixel',
            'label'          => __('Meta Pixel — misurazione conversioni e remarketing (DB SEO Manager)', 'db-seo-manager'),
            'status'         => 'active',
            'purpose'        => __('Misurazione delle conversioni delle campagne pubblicitarie, creazione di pubblici personalizzati e remarketing sulle piattaforme Meta (Facebook, Instagram).', 'db-seo-manager'),
            'legal_basis'    => __('Consenso dell\'interessato (art. 6.1.a GDPR e art. 122 Codice Privacy), raccolto tramite il banner cookie: il Pixel viene caricato solo dopo il consenso alla categoria "marketing". Contitolarità con Meta Platforms Ireland Ltd per la raccolta e trasmissione dei dati (art. 26 GDPR, CGUE C-40/17 Fashion ID).', 'db-seo-manager'),
            'data_collected' => __('Indirizzo IP, user-agent, identificatori dei cookie Meta (_fbp, _fbc), pagine visitate, referer, eventi di navigazione e di conversione; eventuale abbinamento con l\'account Meta dell\'utente.', 'db-seo-manager'),
            'retention'      => __('Cookie _fbp e _fbc: 90 giorni. Dati ricevuti da Meta conservati secondo le policy di Meta Platforms Ireland Ltd.', 'db-seo-manager'),
            'transfers'      => __('Trasferimento extra-UE verso Meta Platforms, Inc. (USA), aderente all\'EU-US Data Privacy Framework (decisione di adeguatezza), con Clausole Contrattuali Standard (SCC) come garanzia aggiuntiva.', 'db-seo-manager'),
        );
    }
}
