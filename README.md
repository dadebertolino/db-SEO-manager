# DB SEO Manager

**GDPR-first SEO plugin for WordPress. No external services, no telemetry, no nag screens. Analytics scripts only load after explicit consent.**

> Most SEO plugins are bloated with upsells and quietly ship third-party trackers. This one ships nothing you didn't ask for.

---

## Features

### SEO core
- **On-page SEO** — meta titles, descriptions, robots directives, canonical URLs, OpenGraph, Twitter Cards
- **Schema.org JSON-LD** — unified `@graph` with WebSite, Organization/Person, WebPage, Article, BreadcrumbList
- **XML sitemap** — extended index + per-type/taxonomy sub-sitemaps with image extension, paginated at 1000 URLs/file, transient-cached
- **Virtual robots.txt** — editable rules with automatic sitemap reference
- **Redirect manager** — 301/302/307/410, regex support with capture groups, anonymous aggregated hit counters (no IP, no user-agent), optional zero-counting mode
- **Anonymous 404 monitor** — URL path + referer path + count + last_seen only. Query strings stripped (no tokens, emails, fbclid/gclid). No IP. No user-agent. Auto-cleanup via WP-Cron.
- **Breadcrumbs** — accessible `<nav>` markup, shortcode, Gutenberg block, template tag, JSON-LD `BreadcrumbList`
- **Content analyzer** — word count, heading structure, link analysis, image alt audit, focus keyword density, readability (Gulpease for Italian, Flesch for English) — runs entirely on your server, no external API
- **Live SERP preview** — real-time Google-style preview in the post editor with character counters
- **IndexNow** — server-to-server URL submission to Bing/Yandex on publish/update. No user data involved.
- **Importer** — batch migration from Yoast SEO, Rank Math, All in One SEO with preserve/replace modes
- **Term SEO** — per-term title, meta description, canonical and noindex for categories, tags and custom taxonomies
- **Hreflang** — manual per-post language alternates with automatic self-reference, plus a filter for Polylang/WPML integration
- **Duplicate content report** — site-wide detection of duplicate SEO titles, meta descriptions and post titles
- **FAQ & HowTo schema** — `[dbseo_faq]` / `[dbseo_howto]` shortcodes with accessible markup and automatic JSON-LD
- **Redirect CSV import/export** — bulk migration of redirect rules, inline rule editing, one-click redirect creation from the 404 log
- **GitHub auto-updates** — receive updates in the WordPress admin like any wp.org plugin

### GDPR & privacy
- **Consent-gated analytics** — GA4, GTM, Matomo, Plausible, Umami, Meta Pixel. All scripts require explicit consent. The gate runs in the browser (idempotent JS loader), so it is **safe with full-page caching**: the HTML is identical for every visitor.
- **WP Consent API registration** — declares compliance via `wp_consent_api_registered_{plugin}` and registers the cookies set by the configured trackers with `wp_add_cookie_info()`. Consent sources, in order: DB Cookie Manager (`window.DBCM`), WP Consent API, Cookiebot/Complianz.
- **Google Consent Mode v2** — always emits `denied` defaults as the first script on the page when GA4/GTM is enabled; sends `gtag('consent','update', …)` when consent is given
- **Hard Privacy mode** — global toggle that suppresses all external integrations and applies anti-fingerprinting hardening: emoji script strip, oEmbed strip, DNS prefetch strip, Gravatar replacement with inline SVG, optional Google Fonts strip, XML-RPC disable, REST users endpoint protection
- **Third-party audit** — on-demand homepage scan that identifies external resources by category (analytics, advertising, fonts, embeds, captchas, CDNs) and consent requirement, against a catalog of 30+ known hosts
- **Scheduled audit with alerts** — weekly automatic scan with email notification when a new external host appears (privacy regression monitoring; one alert per host)
- **AI crawler control** — selectively block known AI crawlers (GPTBot, ClaudeBot, Google-Extended, PerplexityBot, CCBot, Bytespider and more) via robots.txt; nothing blocked by default
- **llms.txt** — optional virtual llms.txt (llmstxt.org spec) with site info, key pages and custom Markdown
- **GDPR status dashboard** — synthetic 0-100 score with breakdown of contributing factors and live module status
- **Processing declarations to DB Privacy Hub** — the active processings (404 monitor, IndexNow, analytics provider, Meta Pixel) are declared dynamically to the unified register of [DB Privacy Hub](https://www.davidebertolino.it/progetti/db-privacy-hub/), with legal basis, retention and extra-EU transfers
- **WP Privacy Tools integration** — exporter for author-attached SEO meta (GDPR Art. 15); the eraser (Art. 17) retains post SEO meta as editorial content and explains why

---

## Privacy by Design

This plugin's defaults are deliberately conservative:

- All external integrations (analytics, pixels, IndexNow) are **off** out of the box
- The 404 monitor never logs IP addresses or user-agents, and strips query strings from URL and referer
- The redirect log only stores aggregated hit counters — and can be switched to zero-counting if even aggregates are unwanted
- No phone-home for licensing or telemetry — updates pull from GitHub Releases only
- No Google Fonts, no remote CDN for core functionality, no avatar fetching from the plugin itself
- Uninstall removes all stored data (tables, options, post meta, transients)

The register of processing activities and the Privacy Policy live in **DB Privacy Hub** (the former "Privacy SEO" page was moved there in 1.3.0): SEO Manager declares its active processings to the Hub automatically, based on the actual settings.

For sites that want to go further, the **Hard Privacy mode** acts as a global kill-switch for external integrations and adds optional anti-fingerprinting hardening (emoji script strip, oEmbed removal, DNS prefetch filtering, Gravatar replacement, XML-RPC disable, REST users protection). It is opt-in and modular — every sub-option can be turned on or off independently.

The **GDPR status dashboard** (Stato GDPR) gives a synthetic 0-100 score and a third-party audit that scans the site's homepage to identify which external resources are actually being loaded by the theme, other plugins, or content embeds — independent of what this plugin manages.

---

## Installation

1. Download the latest release ZIP from [Releases](https://github.com/dadebertolino/db-seo-manager/releases)
2. In WordPress, go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP file and click **Install Now**
4. Activate the plugin

The plugin auto-updates from GitHub — you'll receive update notifications directly in your WordPress dashboard.

---

## Requirements

- WordPress 5.8+
- PHP 7.4+

---

## Tech Stack

`PHP` `JavaScript` `WordPress API` `WP-Cron` `Schema.org JSON-LD` `IndexNow protocol` `Google Consent Mode v2`

---

## How It Works

The plugin is built around a single bootstrap singleton (`DBSEO_Core`) that lazy-loads modules from `inc/`. Each module is independent and can be deactivated via settings without breaking the others.

The frontend output (`<head>` tags, JSON-LD, sitemap) is fully server-rendered with **zero external HTTP calls** for core functionality. Analytics scripts pass through `DBSEO_Consent_Gate`. The server only emits the Consent Mode v2 `denied` defaults and the tracker configuration; the JS loader (`assets/js/consent-listener.js`) injects each script once consent for its category is detected in the browser: DB Cookie Manager (`DBCM.hasConsent`, `dbcm:ready`, `dbcm:consent`) → WP Consent API (`wp_has_consent`, `wp_listen_for_consent_change`) → Cookiebot/Complianz → default DENIED. With no consent manager at all, scripts are not loaded. The `dbseo_consent_granted` filter can still force a service server-side (`true` = always printed, `false` = never).

The sitemap uses transient caching with automatic invalidation on `save_post`/`edited_term`. The 404 monitor and redirect logger use `INSERT ... ON DUPLICATE KEY UPDATE` with hash-based primary keys for O(1) lookups and atomic increments.

---

## Configuration

The admin UI is split into these pages under **DB SEO** in the sidebar:

| Tab | Purpose |
|-----|---------|
| Dashboard | Status overview and quick links |
| Titles & Meta | Templates with `%title% %sitename% %sep%` placeholders, robots defaults |
| Social | OpenGraph + Twitter Card defaults |
| Schema.org | Organization/Person settings, breadcrumb config |
| Sitemap & Robots | Post types, taxonomies, exclusions, custom robots.txt rules |
| Redirects | CRUD interface for redirect rules, optional zero-counting |
| 404 Errors | Anonymous monitor, retention period |
| Analytics & Consent | Provider selection, Consent Mode v2 |
| IndexNow | Auto-generated key, virtual `/{key}.txt` endpoint |
| Verification | Search engine verification meta tags |
| Hard Privacy | Master toggle + 7 anti-fingerprinting hardening modules |
| GDPR Status | Synthetic score, third-party audit, live module status |
| Import | Migration from Yoast SEO, Rank Math, All in One SEO |
| Duplicates | Duplicate SEO titles, meta descriptions and post titles |

Each post/page/CPT also gets a metabox with four tabs (General, Advanced, Social, Analysis) including live SERP preview and on-demand content analysis.

---

## Integration with DB Cookie Manager

When **DB Cookie Manager 3.0.2+** is installed alongside SEO Manager, the two plugins automatically wire together — no configuration needed. The integration follows a producer-consumer pattern: the Cookie Manager produces consent and cookie scan data, the SEO Manager consumes them.

**What you get out of the box:**

- The consent gate uses the Cookie Manager as authoritative consent source in the browser (`window.DBCM.hasConsent()`, events `dbcm:ready` / `dbcm:consent`): trackers load without page reload as soon as the visitor accepts, and cached pages stay correct.
- The JS loader propagates user choices to Google Consent Mode v2 in real time via `gtag('consent','update', ...)`.
- Both plugins declare their own processing activities to **DB Privacy Hub** via `dbph_processing_register` (the Cookie Manager: consent collection, consent log, cookie scanner; the SEO Manager: see below). They appear in `Privacy → Registro trattamenti`.
- The audit page enriches each external host finding with the cookies actually observed by the Cookie Manager scanner, shown in a "🍪 Cookie scritti" column.
- The GDPR Status dashboard recognizes the Cookie Manager and awards a `+5` bonus, plus a `−15` penalty if external integrations are active without any consent manager at all.

**Without the Cookie Manager**, none of this is broken: the consent gate falls back to WP Consent API or Cookiebot/Complianz detection (and loads nothing if no consent manager is present), and the audit table omits the cookie column.

---

## Integrazione ecosistema DB privacy

Quando uno o più di questi plugin sono installati, DB SEO Manager li sfrutta automaticamente — senza configurazione.

### Consent gate per analytics e Meta Pixel
Gli snippet GA4, GTM, Matomo, Plausible, Umami e Meta Pixel non sono mai stampati nell'HTML: il PHP emette solo il default Google Consent Mode v2 `denied` e la configurazione, e un loader JS idempotente inietta ogni script solo quando il consenso per la sua categoria (`statistics`, `marketing`; filtrabile con `dbseo_consent_category`) risulta concesso nel browser — DB Cookie Manager (`window.DBCM`, eventi `dbcm:ready` / `dbcm:consent`), WP Consent API (`wp_has_consent`, `wp_listen_for_consent_change`), Cookiebot/Complianz. L'HTML è identico per tutti i visitatori: nessun problema con la cache di pagina. Senza consent manager gli script non vengono caricati. Override site-wide: filter `dbseo_consent_granted` (`true` = sempre, `false` = mai, `null` = gate client-side).

### Dichiarazione trattamenti al registro privacy unificato
Quando **DB Privacy Hub 1.0.0+** è installato, DB SEO Manager dichiara automaticamente fino a 4 trattamenti nel pannello "Privacy → Registro trattamenti", in base alle impostazioni effettive (filter `dbph_processing_register`; con Hard Privacy attivo analytics, Pixel e IndexNow non vengono dichiarati):

| ID | Quando appare |
|---|---|
| `dbseo_monitor_404` | monitor 404 attivo |
| `dbseo_indexnow` | IndexNow attivo |
| `dbseo_analytics` | analytics attivi con provider configurato (GA4, GTM, Matomo, Plausible o Umami — testo, base giuridica e trasferimenti specifici per provider) |
| `dbseo_meta_pixel` | Meta Pixel attivo con ID configurato |

SEO on-page, sitemap, redirect (solo contatori aggregati) e meta tag di verifica non trattano dati personali e non vengono dichiarati.

### DSAR routing via Privacy Hub
Exporter ed eraser si registrano su `dbph_user_data_exporters` / `dbph_user_data_erasers` quando l'Hub è presente, e in fallback direttamente su `wp_privacy_personal_data_exporters` / `wp_privacy_personal_data_erasers` (saltati se esiste `DBPH_DSAR`, per evitare doppie registrazioni). L'export (art. 15) include i meta SEO personalizzati sui post dell'autore (16 chiavi, incluso `_dbseo_hreflang`). L'eraser (art. 17) **conserva** i meta SEO dei post — contenuto editoriale, non dati personali: cancellarli distruggerebbe l'indicizzazione — e lo segnala con `items_retained` e un messaggio; cancella solo lo user meta del plugin.

### Marker `DBSEO_DSAR_AVAILABLE`
Il file principale definisce `DBSEO_DSAR_AVAILABLE = true` (con il blocco "Privacy capabilities" nell'header): indica al generatore di Privacy Policy dell'Hub che il plugin implementa la procedura DSAR.

---

## Public Filters & Actions

```php
// Filter the generated meta title
add_filter( 'dbseo_meta_title', function( $title, $meta ) {
    return $title;
}, 10, 2 );

// Site-wide override for a service: true = always printed server-side,
// false = never loaded, null = client-side consent gate (default).
// Must NOT depend on the visitor (cookies): the page may be cached.
add_filter( 'dbseo_consent_granted', function( $default, $service, $category ) {
    return 'matomo' === $service ? true : $default;
}, 10, 3 );

// Move a service to another consent category
add_filter( 'dbseo_consent_category', function( $category, $service ) {
    return 'plausible' === $service ? 'statistics-anonymous' : $category;
}, 10, 2 );

// Keep selected query args in the 404 log (default: none)
add_filter( 'dbseo_404_allowed_query_args', function( $args ) {
    return array( 'lang' );
} );

// Add custom JSON-LD nodes
add_filter( 'dbseo_schema_data', function( $graph, $context ) {
    if ( $context['is_singular'] && get_post_type() === 'product' ) {
        $graph[] = array( '@type' => 'Product', /* ... */ );
    }
    return $graph;
}, 10, 2 );

// Hreflang for multilingual setups
add_filter( 'dbseo_hreflang_alternatives', function( $alts, $meta ) {
    return array( 'en' => 'https://example.com/en/page' );
}, 10, 2 );

// Declare your plugin's own processing activities into the unified
// register of DB Privacy Hub (the legacy dbseo_processing_register filter
// is no longer applied by SEO Manager since 1.3.0).
add_filter( 'dbph_processing_register', function( $register ) {
    $register[] = array(
        'id'             => 'myplugin_form_submissions',
        'label'          => __( 'Form submissions', 'myplugin' ),
        'status'         => 'active',
        'purpose'        => __( 'Save contact form data.', 'myplugin' ),
        'legal_basis'    => __( 'Consent (GDPR art. 7).', 'myplugin' ),
        'data_collected' => __( 'Name, email, message.', 'myplugin' ),
        'retention'      => __( '365 days, then deletion.', 'myplugin' ),
        'transfers'      => __( 'None.', 'myplugin' ),
    );
    return $register;
} );

// Customize the WP Consent API → Google Consent Mode v2 signal map (1.2.0+)
add_filter( 'dbseo_consent_mode_signal_map', function( $map ) {
    $map['statistics-anonymous'] = array( 'analytics_storage' );
    return $map;
} );
```

Other filters: `dbseo_canonical_url`, `dbseo_robots_directives`, `dbseo_sitemap_post_types`, `dbseo_sitemap_exclude_ids`, `dbseo_breadcrumb_items`, `dbseo_404_should_ignore`, `dbseo_404_allowed_query_args`, `dbseo_consent_mode_defaults`, `dbseo_consent_mode_signal_map`, `dbseo_consent_category`, `dbseo_ga4_config`, `dbseo_plausible_script_url`.

Actions: `dbseo_redirect_before`, `dbseo_redirect_after`, `dbseo_404_logged`, `dbseo_meta_saved`, `dbseo_indexnow_flushed`.

JS events on `document`:
- `dbseo:consent_updated` — re-emitted after consent propagation. Detail: `{ type, consent, signals }`.

---

## Shortcodes & Blocks

```
[dbseo_breadcrumbs separator="/" class="my-class"]
```

Or in your theme:

```php
<?php if ( function_exists( 'dbseo_breadcrumbs' ) ) dbseo_breadcrumbs(); ?>
```

Or in the block editor: **DB SEO → Breadcrumbs**.

---

## Changelog

### 1.3.0 — Privacy moved to DB Privacy Hub _(2026)_ ⚠️ Breaking change

The privacy register and the unified processing UI have been **extracted into a dedicated plugin**: [DB Privacy Hub](https://www.davidebertolino.it/progetti/db-privacy-hub/). This plugin focuses on its core responsibility (SEO).

Migration:
- The "Privacy SEO" admin page has been removed (now: `Privacy → Registro trattamenti` in DB Privacy Hub)
- The legacy filter `dbseo_processing_register` is no longer emitted by SEO Manager but DB Privacy Hub still listens to it for retro-compat with third-party plugins
- The class `DBSEO_Privacy` has been removed
- **WP Privacy Tools integration retained**: the exporter and eraser for SEO post meta (15 keys: `_dbseo_title`, `_dbseo_description`, OpenGraph, Twitter, robots, focus keyword, score, cornerstone, schema type) live in the new isolated module `DBSEO_WP_Privacy_Tools`. They register through DB Privacy Hub's `dbph_user_data_exporters` / `dbph_user_data_erasers` filters when the Hub is installed, and fall back to the WordPress core filters when standalone — no loss of GDPR compliance either way.
- Existing dashboards/cards referencing "Privacy SEO" have been removed
- An admin notice suggests installing DB Privacy Hub on first load after upgrade

What's NOT removed: the `Hard Privacy` page and the `Stato GDPR` page — both belong to SEO concerns (anti-fingerprinting, third-party audit) and stay where they are.

### 1.2.2 — Bugfix manual release _(2026)_

Internal bugfixes by the maintainer (not formally released).

### 1.2.0 — Cookie Manager Alignment

Aligns the SEO Manager to the rebuilt **DB Cookie Manager 3.0.2** and establishes the inter-plugin DB ecosystem protocol. All changes are additive: SEO Manager keeps working standalone or with any consent manager.

- **Consent gate cleanup**: removed manual cookie parsing (`dbcm_consent`); now delegates to `DBCM_Consent_API::has_consent()` when the Cookie Manager is active. Schema validation is now done by the Cookie Manager itself (versioned cookie). Cookiebot/Complianz fallback retained for non-DB sites.
- **Consent listener** (`assets/js/consent-listener.js`): listens for `dbcm:consent` events and propagates Google Consent Mode v2 updates via `gtag('consent','update', ...)` without page reload. Loaded only when Consent Mode v2 is enabled.
- **Privacy register opens to other plugins**: `dbseo_processing_register` filter now fully documented with public contract. Entries declared by external plugins are tagged with `_source = 'external'` and shown with a "Plugin esterno" badge in the admin UI.
- **GDPR Status improvements**: new `+5` bonus when DB Cookie Manager is active (cumulative with `+5` for WP Consent API), new `−15` penalty when external integrations (analytics or pixel) are active without any consent manager. New "DB Cookie Manager" row in the Module Status table.
- **Audit ↔ Scanner integration**: when the audit identifies a known third-party host, it queries the Cookie Manager scanner for cookies actually written by that provider. New "Cookie scritti" column in the audit table with a tooltip listing the cookie names.
- **New filters**: `dbseo_consent_mode_signal_map` (customize WP Consent API category → Consent Mode v2 signal mapping).
- **New DOM event**: `dbseo:consent_updated` re-emitted on `document` after consent propagation, intended for future DB plugins to listen to instead of `dbcm:consent` directly.

### 1.1.0 — GDPR Hardening

- WP Consent API full registration: declares its services and cookies to compliant consent managers
- Hard Privacy mode toggle that suppresses external integrations and applies anti-fingerprinting hardening (emoji strip, oEmbed strip, DNS prefetch strip, Gravatar replacement, optional Google Fonts strip, XML-RPC disable, REST users protection)
- Third-party audit page that scans the homepage and identifies external resources by category and consent requirement
- GDPR Status dashboard with synthetic 0-100 score and breakdown of contributing factors
- Privacy register exportable to JSON and print-ready HTML (browser-native PDF, no embedded library)
- Optional zero-counting mode for redirects (skips even aggregated hit counters)

### 1.0.0 — Initial release

- On-page SEO, Schema.org JSON-LD, XML sitemap, virtual robots.txt, redirects, anonymous 404 monitor, breadcrumbs, content analyzer, consent-gated analytics, IndexNow, importer for Yoast/Rank Math/AIO SEO

---

## License

This project is licensed under the [GPL v2 or later](LICENSE).

---

## Author

**Davide Bertolino** — Electronic Engineer · STEM Teacher · Open Source Developer

- 🌐 [davidebertolino.it](https://www.davidebertolino.it)
- 📚 [handsonstem.it](https://www.handsonstem.it)
- 💼 [LinkedIn](https://www.linkedin.com/in/davide-bertolino/)

---

## 🇮🇹 Italiano

Plugin WordPress per la SEO completa, progettato secondo i principi del GDPR.

L'interfaccia di amministrazione è in italiano. Tutte le integrazioni con servizi esterni (Google Analytics, Tag Manager, Meta Pixel, IndexNow) sono **disattivate di default**: vanno abilitate esplicitamente. I trattamenti attivi (monitor 404, IndexNow, analytics, Meta Pixel) vengono dichiarati automaticamente al registro unificato di **DB Privacy Hub**, con base giuridica, conservazione e trasferimenti extra-UE.

Gli snippet di tracciamento vengono caricati **solo dopo il consenso**, lato browser (compatibile con la cache di pagina): il loader JS usa DB Cookie Manager (`window.DBCM`) quando presente, altrimenti la WP Consent API, con fallback su Cookiebot e Complianz per siti senza ecosistema DB; senza consent manager non carica nulla. Quando l'utente cambia consenso, gli script vengono caricati senza ricaricare la pagina e le scelte sono propagate a Google Consent Mode v2 (`gtag('consent','update', ...)`). Il monitor 404 è anonimo per design (mai IP, mai user-agent, query string scartate). Il log redirect contiene solo conteggi aggregati — e può essere disattivato del tutto in modalità zero-trust.

La pagina **Hard Privacy** è una modalità avanzata che disabilita in blocco le integrazioni esterne e applica una serie di hardening anti-fingerprinting: rimozione degli script di rilevamento emoji di WordPress, blocco del prefetch DNS verso domini terzi, sostituzione di Gravatar con un avatar locale SVG, blocco opzionale di Google Fonts, disabilitazione di XML-RPC, protezione dell'endpoint REST `/wp/v2/users` dalle enumerazioni anonime.

La pagina **Stato GDPR** mostra un punteggio sintetico 0-100 calcolato sulle impostazioni attive, e include un audit on-demand che analizza la homepage del sito per identificare quali risorse di terze parti vengono effettivamente caricate dal tema e dagli altri plugin (analytics, advertising, font, embed, captcha, CDN). Quando il DB Cookie Manager 3.0.2+ è installato, l'audit arricchisce ogni host noto con i cookie effettivamente rilevati dallo scanner del Cookie Manager, mostrandoli in una colonna "🍪 Cookie scritti". Il registro dei trattamenti e la Privacy Policy sono gestiti da DB Privacy Hub, che raccoglie le dichiarazioni di tutti i plugin DB tramite il filter `dbph_processing_register`.

La disinstallazione rimuove completamente tutti i dati persistenti. Il plugin segue gli standard di sviluppo dei plugin di Davide Bertolino: auto-aggiornamento da GitHub, design system condiviso, nessuna dipendenza SaaS, accessibilità WCAG 2.1 AA, installabile in 30 secondi.

## Changelog

### 1.5.0 — 2026-09 — Privacy Hub declarations, cache-safe consent gate, query-less 404 log

**Processing declarations to DB Privacy Hub (restored)**
- Since 1.3.0 the plugin declared no processing at all: `DBSEO_Privacy` (`inc/class-privacy.php`) was no longer loaded and the legacy filter no longer applied. New class `DBSEO_Privacy_Declarations` (`inc/class-privacy-declarations.php`) hooked to `dbph_processing_register`, with entries built from the actual settings: `dbseo_monitor_404`, `dbseo_indexnow`, `dbseo_analytics` (per provider: GA4/GTM → Google Ireland + US transfer under EU-US DPF with SCC; Matomo/Umami → instance host; Plausible → EU cloud or self-hosted), `dbseo_meta_pixel` (joint controllership art. 26, US transfer DPF + SCC). All 8 contract fields. With Hard Privacy on, analytics, Pixel and IndexNow are not declared.
- `dbph_processing_register` only: the legacy `dbseo_processing_register` is applied solely by the Hub (merged with id de-duplication), so hooking both would be redundant.
- Dead file `inc/class-privacy.php` removed.

**Cache-safe consent gate (high priority)**
- Before: consent evaluated server-side from the visitor's cookies → with full-page caching, the page rendered for a visitor who had accepted (Consent Mode `granted` + inline GA4/Pixel snippets) was served to everyone.
- Now: Consent Mode v2 default is ALWAYS `denied` for `ad_storage`, `ad_user_data`, `ad_personalization`, `analytics_storage`; scripts are injected by an idempotent JS loader (`assets/js/consent-listener.js`) once consent is detected via `DBCM.hasConsent` / `dbcm:ready` / `dbcm:consent`, `wp_has_consent` / `wp_listen_for_consent_change`, Cookiebot/Complianz; `gtag('consent','update', …)` is sent on consent. No consent manager → no scripts (unchanged behavior).
- `dbseo_consent_granted` kept: `true` prints the snippet server-side (with a `granted` consent update), `false` excludes it, `null` = client-side gate. `dbseo_consent_mode_defaults`, `dbseo_consent_mode_signal_map`, `dbseo_ga4_config`, `dbseo_plausible_script_url` unchanged. New `dbseo_consent_category`.
- GTM `<noscript>` iframe only when GTM is forced server-side (consent cannot be checked without JS).

**Fixes**
- Meta Pixel was never output when analytics were disabled (it lived inside `output_analytics_head`, which returned early): it is now an independent service.
- JS consent update: `dataLayer.push(['consent','update',…])` is not understood by Google; now a `window.gtag` stub + `gtag('consent','update', …)`.
- WP Consent API: `wp_register_consent_plugin()` / `wp_register_consent_service()` do not exist in the Consent API. Replaced with `wp_consent_api_registered_{plugin_basename}` and `wp_add_cookie_info()` for the cookies of the configured trackers (`_ga`, `_ga_*`, `_dc_gtm_*`, `_fbp`, `_fbc`).
- Hard Privacy is now honored by the consent gate (the `option_*` filter was added after the Core settings cache was already populated).
- Hard Privacy, complete: the ineffective `option_*` filter has been removed; new helper `DBSEO_Core::is_hard_privacy()` checked directly by IndexNow (no hooks, no cron — any existing `dbseo_cron_indexnow_flush` event is cleared — and `flush_queue()` never sends requests), consent gate, Privacy Hub declarations, privacy score and the "Module status" / dashboard cards. Saved flags are never overwritten: turning Hard Privacy off restores the previous settings.
- Deactivation now also clears the `dbseo_cron_404_cleanup` and `dbseo_cron_audit` events.

**404 monitor — data minimization**
- Query strings stripped from URL and referer before storage (tokens, emails, `fbclid`/`gclid`…). Optional whitelist via the `dbseo_404_allowed_query_args` filter (empty by default).
- DB migration 1.0.0 → 1.1.0: query strings removed from already stored rows too (rows that become duplicates are deleted).
- The cleanup cron is always registered: with the monitor disabled, previously collected rows are still deleted when the retention period expires.

**DSAR**
- Eraser (Art. 17): the author's post SEO meta are no longer deleted (editorial content, not personal data — deleting them wiped the site's SEO); reported as `items_retained` with an explanatory message. Only the plugin's user meta is erased.
- Exporter (Art. 15): `_dbseo_hreflang` added (16 keys).
- "Privacy capabilities" header block and `DBSEO_DSAR_AVAILABLE` constant.

**No breaking change** for settings. Sites using `dbseo_consent_granted` to return the individual visitor's consent must now return `null` (client-side gate) or a site-wide decision.

### 1.4.0 — 2026-07

**Fixes**
- Redirects to external domains now work: the target host is whitelisted via `allowed_redirect_hosts` at execution time (previously `wp_safe_redirect()` silently fell back to home)
- Target URL validation on rule creation; empty target now allowed only for 410 rules

**Performance**
- Sitemap generation no longer uses a `NOT EXISTS` meta_query (heavy postmeta LEFT JOIN on large sites): noindex IDs are read with a cached direct query and merged into `post__not_in`
- Targeted sitemap cache invalidation: saving a post flushes only that post type's transients instead of the whole sitemap cache
- Optional "regex rules only on 404" mode: zeroes the regex evaluation cost on normal traffic

**404 monitor hardening**
- Configurable row cap with least-relevant eviction (anti-flooding from bot scans)
- Configurable regex exclusion patterns (defaults cover vulnerability probes and static assets)
- Optional "internal referer only" mode to log real broken links and ignore bot scans

**New features**
- AI crawler control in robots.txt + virtual llms.txt
- Term SEO fields (title, description, canonical, noindex) for categories, tags and sitemap taxonomies
- Manual hreflang metabox with per-line validation and automatic self-reference
- Duplicate content report (SEO titles, meta descriptions, post titles)
- FAQ and HowTo shortcodes with automatic JSON-LD (footer-emitted, Google-valid)
- Redirect CSV import/export, inline rule editing, one-click 301 creation from the 404 log
- Scheduled weekly third-party audit with email alert on new external hosts
- Cornerstone content flag now used: priority 1.0 in the XML sitemap

**Infrastructure**
- GitHub Actions CI (PHP 7.4–8.3 lint + PHPCS report) and release workflow that builds and attaches the plugin ZIP consumed by the auto-updater
- Multisite-aware uninstall (per-blog tables, options, meta, cron)

## Internationalization

Source strings are intentionally in Italian: the plugin targets Italian sites and the entire admin UI, documentation and legal terminology (GDPR register, legal bases) are written for that audience. A `.pot` file is provided; an English translation can be added as a standard `en_US.po` if needed.
