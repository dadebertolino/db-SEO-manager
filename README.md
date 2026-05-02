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
- **Anonymous 404 monitor** — URL + count + last_seen only. No IP. No user-agent. Auto-cleanup via WP-Cron.
- **Breadcrumbs** — accessible `<nav>` markup, shortcode, Gutenberg block, template tag, JSON-LD `BreadcrumbList`
- **Content analyzer** — word count, heading structure, link analysis, image alt audit, focus keyword density, readability (Gulpease for Italian, Flesch for English) — runs entirely on your server, no external API
- **Live SERP preview** — real-time Google-style preview in the post editor with character counters
- **IndexNow** — server-to-server URL submission to Bing/Yandex on publish/update. No user data involved.
- **Importer** — batch migration from Yoast SEO, Rank Math, All in One SEO with preserve/replace modes
- **GitHub auto-updates** — receive updates in the WordPress admin like any wp.org plugin

### GDPR & privacy
- **Consent-gated analytics** — GA4, GTM, Matomo, Plausible, Umami, Meta Pixel. All scripts require explicit consent.
- **WP Consent API full registration** — declares plugin, services, and per-cookie metadata to compatible consent managers (Complianz, Real Cookie Banner, GDPR Cookie Compliance). Falls back to direct cookie detection for DB Cookie Manager, Cookiebot, Complianz.
- **Google Consent Mode v2** — emits `denied` defaults as the first script on the page when GA4/GTM is enabled
- **Hard Privacy mode** — global toggle that suppresses all external integrations and applies anti-fingerprinting hardening: emoji script strip, oEmbed strip, DNS prefetch strip, Gravatar replacement with inline SVG, optional Google Fonts strip, XML-RPC disable, REST users endpoint protection
- **Third-party audit** — on-demand homepage scan that identifies external resources by category (analytics, advertising, fonts, embeds, captchas, CDNs) and consent requirement, against a catalog of 30+ known hosts
- **GDPR status dashboard** — synthetic 0-100 score with breakdown of contributing factors and live module status
- **Auto-generated privacy register** — dynamic list of active technical processings with legal basis, retention, extra-EU transfer notes
- **Register export** — JSON for compliance tooling, print-ready HTML for browser-native PDF (no embedded library)
- **WP Privacy Tools integration** — exporter and eraser for user-attached SEO meta (GDPR Art. 15 & 17)

---

## Privacy by Design

This plugin's defaults are deliberately conservative:

- All external integrations (analytics, pixels, IndexNow) are **off** out of the box
- The 404 monitor never logs IP addresses or user-agents
- The redirect log only stores aggregated hit counters — and can be switched to zero-counting if even aggregates are unwanted
- No phone-home for licensing or telemetry — updates pull from GitHub Releases only
- No Google Fonts, no remote CDN for core functionality, no avatar fetching from the plugin itself
- Uninstall removes all stored data (tables, options, post meta, transients)

The "Privacy SEO" admin page generates a real-time register of active technical processings with suggested legal basis, retention period, and extra-EU transfer notes — ready to copy into your privacy policy or export as JSON / print-ready HTML.

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

The frontend output (`<head>` tags, JSON-LD, sitemap) is fully server-rendered with **zero external HTTP calls** for core functionality. Analytics scripts pass through `DBSEO_Consent_Gate`, which evaluates consent in this order: `dbseo_consent_granted` filter → WP Consent API → DB Cookie Manager cookie → Cookiebot/Complianz cookies → default DENIED.

The sitemap uses transient caching with automatic invalidation on `save_post`/`edited_term`. The 404 monitor and redirect logger use `INSERT ... ON DUPLICATE KEY UPDATE` with hash-based primary keys for O(1) lookups and atomic increments.

---

## Configuration

The admin UI is split into 13 tabs under **DB SEO** in the sidebar:

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
| Privacy SEO | Live register of active technical processings, JSON / print-PDF export |

Each post/page/CPT also gets a metabox with four tabs (General, Advanced, Social, Analysis) including live SERP preview and on-demand content analysis.

---

## Integration with DB Cookie Manager

When **DB Cookie Manager 3.0.2+** is installed alongside SEO Manager, the two plugins automatically wire together — no configuration needed. The integration follows a producer-consumer pattern: the Cookie Manager produces consent and cookie scan data, the SEO Manager consumes them.

**What you get out of the box:**

- The consent gate uses the Cookie Manager as authoritative consent source via `DBCM_Consent_API::has_consent()`, with WP Consent API as the primary path when installed.
- The JS consent listener propagates user choices to Google Consent Mode v2 in real time via `gtag('consent','update', ...)`.
- The Cookie Manager declares its own three processing activities (consent collection, consent log, cookie scanner) into the SEO Manager's privacy register via the `dbseo_processing_register` filter. They appear in `Privacy SEO` with a "🔌 Plugin esterno" badge.
- The audit page enriches each external host finding with the cookies actually observed by the Cookie Manager scanner, shown in a "🍪 Cookie scritti" column.
- The GDPR Status dashboard recognizes the Cookie Manager and awards a `+5` bonus, plus a `−15` penalty if external integrations are active without any consent manager at all.

**Without the Cookie Manager**, none of this is broken: the consent gate falls back to Cookiebot/Complianz detection, the audit table omits the cookie column, and the privacy register shows only the SEO Manager's own activities.

---

## Public Filters & Actions

```php
// Filter the generated meta title
add_filter( 'dbseo_meta_title', function( $title, $meta ) {
    return $title;
}, 10, 2 );

// Force consent for a specific service (e.g., from your custom consent system)
add_filter( 'dbseo_consent_granted', function( $default, $service, $category ) {
    return my_custom_consent_check( $category );
}, 10, 3 );

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

// Declare your plugin's own processing activities into the SEO Manager's
// privacy register (DB ecosystem pattern, used by DB Cookie Manager 3.0.1+).
add_filter( 'dbseo_processing_register', function( $register ) {
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

Other filters: `dbseo_canonical_url`, `dbseo_robots_directives`, `dbseo_sitemap_post_types`, `dbseo_sitemap_exclude_ids`, `dbseo_breadcrumb_items`, `dbseo_404_should_ignore`, `dbseo_consent_mode_defaults`, `dbseo_consent_mode_signal_map`, `dbseo_processing_register`.

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

L'interfaccia di amministrazione è in italiano. Tutte le integrazioni con servizi esterni (Google Analytics, Tag Manager, Meta Pixel, IndexNow) sono **disattivate di default**: vanno abilitate esplicitamente dopo aver letto la pagina "Privacy SEO" che genera il registro tecnico dei trattamenti attivi sul sito, con base giuridica suggerita, periodo di conservazione e note sui trasferimenti extra-UE.

Gli snippet di tracciamento vengono caricati **solo dopo il consenso**: il consent gate delega a `DBCM_Consent_API::has_consent()` quando DB Cookie Manager 3.0.0+ è installato, oppure usa la WP Consent API (`wp_has_consent`) come strada principale, con fallback su Cookiebot e Complianz per siti senza ecosistema DB. Quando l'utente cambia consenso, un listener JavaScript dedicato propaga le scelte a Google Consent Mode v2 (`gtag('consent','update', ...)`) senza ricaricare la pagina. Il monitor 404 è anonimo per design (mai IP, mai user-agent). Il log redirect contiene solo conteggi aggregati — e può essere disattivato del tutto in modalità zero-trust.

La pagina **Hard Privacy** è una modalità avanzata che disabilita in blocco le integrazioni esterne e applica una serie di hardening anti-fingerprinting: rimozione degli script di rilevamento emoji di WordPress, blocco del prefetch DNS verso domini terzi, sostituzione di Gravatar con un avatar locale SVG, blocco opzionale di Google Fonts, disabilitazione di XML-RPC, protezione dell'endpoint REST `/wp/v2/users` dalle enumerazioni anonime.

La pagina **Stato GDPR** mostra un punteggio sintetico 0-100 calcolato sulle impostazioni attive, e include un audit on-demand che analizza la homepage del sito per identificare quali risorse di terze parti vengono effettivamente caricate dal tema e dagli altri plugin (analytics, advertising, font, embed, captcha, CDN). Quando il DB Cookie Manager 3.0.2+ è installato, l'audit arricchisce ogni host noto con i cookie effettivamente rilevati dallo scanner del Cookie Manager, mostrandoli in una colonna "🍪 Cookie scritti". Il registro privacy è esportabile in JSON e in HTML stampabile (PDF nativo del browser, senza librerie embedded), e accoglie automaticamente i trattamenti dichiarati da altri plugin DB tramite il filter `dbseo_processing_register` (badge "Plugin esterno").

La disinstallazione rimuove completamente tutti i dati persistenti. Il plugin segue gli standard di sviluppo dei plugin di Davide Bertolino: auto-aggiornamento da GitHub, design system condiviso, nessuna dipendenza SaaS, accessibilità WCAG 2.1 AA, installabile in 30 secondi.
