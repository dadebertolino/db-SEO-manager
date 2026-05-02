<?php
/**
 * DBSEO_Analyzer — Analisi contenuti per SEO.
 *
 * Endpoint AJAX dbseo_analyze: riceve post_id (e opzionalmente content + title
 * dal block editor non ancora salvato), restituisce metriche e check superati/falliti.
 *
 * Privacy:
 *  - Tutto rimane sul server WordPress. Nessuna chiamata a servizi esterni.
 *  - L'analisi è disponibile solo a utenti loggati con permessi edit_post.
 *
 * Metriche calcolate:
 *  - Lunghezza title / description / contenuto (parole)
 *  - Distribuzione heading (H1, H2, H3+)
 *  - Link interni / esterni
 *  - Immagini totali / con alt
 *  - Densità focus keyword (con sinonimi via filter)
 *  - Presenza focus keyword in: title, description, slug, primo paragrafo, H1
 *  - Indice di leggibilità Gulpease (italiano) o Flesch-Kincaid (inglese)
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Analyzer {

    /** @var DBSEO_Analyzer|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_dbseo_analyze', array($this, 'ajax_analyze'));
    }

    /* =====================================================================
     * AJAX endpoint
     * ================================================================== */

    public function ajax_analyze() {
        check_ajax_referer('dbseo_analyzer', 'nonce');

        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if ($post_id < 1) {
            wp_send_json_error(array('message' => 'invalid_post_id'));
        }
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(array('message' => 'not_found'), 404);
        }

        // Accettiamo override dal client (block editor non ancora salvato).
        $title       = isset($_POST['title'])       ? sanitize_text_field(wp_unslash($_POST['title']))         : $post->post_title;
        $content     = isset($_POST['content'])     ? wp_kses_post(wp_unslash($_POST['content']))              : $post->post_content;
        $seo_title   = isset($_POST['seo_title'])   ? sanitize_text_field(wp_unslash($_POST['seo_title']))     : (string) get_post_meta($post_id, '_dbseo_title', true);
        $seo_desc    = isset($_POST['seo_desc'])    ? sanitize_textarea_field(wp_unslash($_POST['seo_desc'])) : (string) get_post_meta($post_id, '_dbseo_description', true);
        $focus       = isset($_POST['focus'])       ? sanitize_text_field(wp_unslash($_POST['focus']))         : (string) get_post_meta($post_id, '_dbseo_focus_keyword', true);

        $analysis = $this->analyze($post, $title, $content, $seo_title, $seo_desc, $focus);
        wp_send_json_success($analysis);
    }

    /* =====================================================================
     * Analisi core
     * ================================================================== */

    public function analyze($post, $title, $content, $seo_title, $seo_desc, $focus) {
        $plain = wp_strip_all_tags($content);
        $plain = preg_replace('/\s+/u', ' ', trim($plain));

        $word_count    = $this->count_words($plain);
        $sentence_count = $this->count_sentences($plain);

        $headings = $this->extract_headings($content);
        $links    = $this->extract_links($content, $post);
        $images   = $this->extract_images($content);

        $focus_lc      = mb_strtolower(trim($focus));
        $title_lc      = mb_strtolower($title);
        $seo_title_lc  = mb_strtolower($seo_title);
        $seo_desc_lc   = mb_strtolower($seo_desc);
        $slug_lc       = mb_strtolower((string) $post->post_name);
        $first_para_lc = mb_strtolower($this->first_paragraph($plain));
        $h1_lc         = !empty($headings['h1_text']) ? mb_strtolower(implode(' ', $headings['h1_text'])) : '';

        $density = ($focus_lc !== '' && $word_count > 0)
            ? $this->keyword_density($plain, $focus_lc, $word_count)
            : 0.0;

        $readability = $this->readability($plain, $word_count, $sentence_count);

        // Check list (ognuno: status = good|warn|bad, message)
        $checks = array();

        // Lunghezza SEO title.
        $tl = mb_strlen($seo_title !== '' ? $seo_title : $title);
        $checks[] = $this->status_check('title_length',
            __('Lunghezza title', 'db-seo-manager'),
            $tl,
            ($tl >= 30 && $tl <= 60) ? 'good' : (($tl > 0 && $tl <= 70) ? 'warn' : 'bad'),
            sprintf(__('%d caratteri (consigliato 30–60).', 'db-seo-manager'), $tl)
        );

        // Lunghezza description.
        $dl = mb_strlen($seo_desc);
        if ($dl === 0) {
            $checks[] = $this->status_check('desc_length', __('Meta description', 'db-seo-manager'), 0, 'warn',
                __('Manca: WP useremo l\'estratto come fallback.', 'db-seo-manager'));
        } else {
            $checks[] = $this->status_check('desc_length', __('Lunghezza description', 'db-seo-manager'),
                $dl,
                ($dl >= 120 && $dl <= 160) ? 'good' : (($dl > 0 && $dl <= 180) ? 'warn' : 'bad'),
                sprintf(__('%d caratteri (consigliato 120–160).', 'db-seo-manager'), $dl)
            );
        }

        // Lunghezza contenuto.
        $checks[] = $this->status_check('word_count', __('Lunghezza contenuto', 'db-seo-manager'),
            $word_count,
            $word_count >= 600 ? 'good' : ($word_count >= 300 ? 'warn' : 'bad'),
            sprintf(__('%d parole (consigliato ≥ 600 per articoli).', 'db-seo-manager'), $word_count)
        );

        // H1.
        $h1_count = $headings['h1'];
        $checks[] = $this->status_check('h1', __('Tag H1', 'db-seo-manager'),
            $h1_count,
            $h1_count === 1 ? 'good' : ($h1_count === 0 ? 'warn' : 'bad'),
            $h1_count === 1
                ? __('Un solo H1 nel contenuto.', 'db-seo-manager')
                : sprintf(__('%d tag H1 trovati. Idealmente uno solo (gestito dal tema).', 'db-seo-manager'), $h1_count)
        );

        // H2 / H3.
        $checks[] = $this->status_check('headings_structure', __('Struttura heading', 'db-seo-manager'),
            $headings['h2'] + $headings['h3'],
            ($headings['h2'] >= 1) ? 'good' : 'warn',
            sprintf(__('%d H2, %d H3+ (suggerite almeno 2 H2 per contenuti lunghi).', 'db-seo-manager'),
                $headings['h2'], $headings['h3'])
        );

        // Immagini con alt.
        if ($images['total'] > 0) {
            $no_alt = $images['total'] - $images['with_alt'];
            $checks[] = $this->status_check('images_alt', __('Alt text immagini', 'db-seo-manager'),
                $no_alt,
                $no_alt === 0 ? 'good' : ($no_alt <= 2 ? 'warn' : 'bad'),
                $no_alt === 0
                    ? sprintf(__('Tutte le %d immagini hanno alt.', 'db-seo-manager'), $images['total'])
                    : sprintf(__('%d immagini su %d senza alt.', 'db-seo-manager'), $no_alt, $images['total'])
            );
        }

        // Link interni.
        $checks[] = $this->status_check('internal_links', __('Link interni', 'db-seo-manager'),
            $links['internal'],
            $links['internal'] >= 2 ? 'good' : ($links['internal'] === 1 ? 'warn' : 'bad'),
            sprintf(__('%d link interni (consigliati ≥ 2).', 'db-seo-manager'), $links['internal'])
        );

        // Focus keyword checks (solo se presente).
        if ($focus_lc !== '') {
            // Densità.
            $checks[] = $this->status_check('keyword_density', __('Densità focus keyword', 'db-seo-manager'),
                $density,
                ($density >= 0.5 && $density <= 2.5) ? 'good' : (($density > 0 && $density <= 4) ? 'warn' : 'bad'),
                sprintf(__('%.2f%% (consigliato 0.5–2.5%%).', 'db-seo-manager'), $density)
            );

            // Presenza.
            $checks[] = $this->presence_check('keyword_in_title',
                __('Keyword nel title', 'db-seo-manager'),
                $this->contains($seo_title_lc !== '' ? $seo_title_lc : $title_lc, $focus_lc));

            $checks[] = $this->presence_check('keyword_in_description',
                __('Keyword nella description', 'db-seo-manager'),
                $this->contains($seo_desc_lc, $focus_lc));

            $checks[] = $this->presence_check('keyword_in_slug',
                __('Keyword nello slug URL', 'db-seo-manager'),
                $this->contains($slug_lc, $this->slugify($focus_lc)));

            $checks[] = $this->presence_check('keyword_in_first_paragraph',
                __('Keyword nel primo paragrafo', 'db-seo-manager'),
                $this->contains($first_para_lc, $focus_lc));

            $checks[] = $this->presence_check('keyword_in_h1',
                __('Keyword in H1', 'db-seo-manager'),
                $h1_lc !== '' ? $this->contains($h1_lc, $focus_lc) : null);
        }

        // Leggibilità.
        $checks[] = $this->readability_check($readability);

        // Punteggio aggregato.
        $score = $this->aggregate_score($checks);

        return array(
            'score'       => $score,
            'metrics'     => array(
                'word_count'      => $word_count,
                'sentence_count'  => $sentence_count,
                'title_length'    => mb_strlen($seo_title !== '' ? $seo_title : $title),
                'desc_length'     => mb_strlen($seo_desc),
                'headings'        => $headings,
                'links'           => $links,
                'images'          => $images,
                'keyword_density' => round($density, 2),
                'readability'     => $readability,
            ),
            'checks'      => $checks,
        );
    }

    /* =====================================================================
     * Estrattori
     * ================================================================== */

    private function count_words($plain) {
        if ($plain === '') return 0;
        // str_word_count non gestisce bene UTF-8: split per spazi.
        $words = preg_split('/\s+/u', trim($plain), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($words) ? count($words) : 0;
    }

    private function count_sentences($plain) {
        if ($plain === '') return 0;
        // Approssimazione: ., !, ? (ripetuti contano come 1).
        $sentences = preg_split('/[.!?]+\s+/u', trim($plain), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($sentences) ? max(1, count($sentences)) : 1;
    }

    private function first_paragraph($plain) {
        $words = preg_split('/\s+/u', $plain, 81, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words)) return '';
        return implode(' ', array_slice($words, 0, 80));
    }

    private function extract_headings($content) {
        $h1 = $h2 = $h3 = 0;
        $h1_text = array();

        if (preg_match_all('/<h1[^>]*>(.*?)<\/h1>/i', $content, $m)) {
            $h1 = count($m[0]);
            $h1_text = array_map('wp_strip_all_tags', $m[1]);
        }
        if (preg_match_all('/<h2[^>]*>/i', $content, $m)) $h2 = count($m[0]);
        if (preg_match_all('/<h[3-6][^>]*>/i', $content, $m)) $h3 = count($m[0]);

        return array(
            'h1'      => $h1,
            'h2'      => $h2,
            'h3'      => $h3,
            'h1_text' => $h1_text,
        );
    }

    private function extract_links($content, $post) {
        $internal = $external = 0;
        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);

        if (preg_match_all('/<a\s[^>]*href=[\'"]([^\'"]+)[\'"]/i', $content, $m)) {
            foreach ($m[1] as $url) {
                $url = trim($url);
                if ($url === '' || strpos($url, '#') === 0 || strpos($url, 'mailto:') === 0 || strpos($url, 'tel:') === 0) {
                    continue;
                }
                $host = wp_parse_url($url, PHP_URL_HOST);
                if (!$host || strcasecmp($host, $home_host) === 0) {
                    $internal++;
                } else {
                    $external++;
                }
            }
        }

        return array('internal' => $internal, 'external' => $external);
    }

    private function extract_images($content) {
        $total = $with_alt = 0;
        if (preg_match_all('/<img\b[^>]*>/i', $content, $m)) {
            foreach ($m[0] as $tag) {
                $total++;
                if (preg_match('/\salt=[\'"]([^\'"]*)[\'"]/i', $tag, $am)) {
                    if (trim($am[1]) !== '') $with_alt++;
                }
            }
        }
        return array('total' => $total, 'with_alt' => $with_alt);
    }

    private function keyword_density($plain, $focus, $word_count) {
        if ($word_count === 0 || $focus === '') return 0.0;
        // Conta occorrenze case-insensitive con word boundary unicode.
        $count = preg_match_all('/\b' . preg_quote($focus, '/') . '\b/iu', $plain);
        if (!is_int($count) || $count === 0) return 0.0;
        // La keyword può essere multi-word: occorrenze * parole_keyword / totale * 100.
        $kw_words = max(1, count(preg_split('/\s+/u', $focus)));
        return ($count * $kw_words) / $word_count * 100;
    }

    /**
     * Indice di leggibilità.
     * Per locale italiano: indice Gulpease (sviluppato per l'italiano).
     * Per altri: Flesch Reading Ease.
     */
    private function readability($plain, $word_count, $sentence_count) {
        if ($word_count === 0) {
            return array('score' => 0, 'formula' => 'none', 'label' => __('Nessun contenuto', 'db-seo-manager'));
        }

        $locale = determine_locale();
        $is_italian = (strpos($locale, 'it') === 0);

        if ($is_italian) {
            $letters = preg_match_all('/\p{L}/u', $plain) ?: 0;
            // Gulpease = 89 + (300*frasi - 10*lettere) / parole
            $score = 89 + (300 * $sentence_count - 10 * $letters) / max(1, $word_count);
            $score = max(0, min(100, $score));

            $label = $score >= 80 ? __('Molto facile (livello elementare)', 'db-seo-manager')
                : ($score >= 60 ? __('Facile (livello medio)', 'db-seo-manager')
                : ($score >= 40 ? __('Medio (livello superiore)', 'db-seo-manager')
                : __('Difficile (livello universitario)', 'db-seo-manager')));

            return array('score' => round($score, 1), 'formula' => 'gulpease', 'label' => $label);
        }

        // Flesch Reading Ease (inglese e simili).
        $syllables = $this->approx_syllables($plain);
        $score = 206.835 - 1.015 * ($word_count / max(1, $sentence_count)) - 84.6 * ($syllables / max(1, $word_count));
        $score = max(0, min(100, $score));

        $label = $score >= 70 ? __('Facile', 'db-seo-manager')
            : ($score >= 50 ? __('Medio', 'db-seo-manager')
            : __('Difficile', 'db-seo-manager'));

        return array('score' => round($score, 1), 'formula' => 'flesch', 'label' => $label);
    }

    private function approx_syllables($text) {
        // Stima molto approssimativa: conta vocali consecutive come una sillaba.
        $count = preg_match_all('/[aeiouyàèéìòù]+/iu', $text);
        return is_int($count) ? max(1, $count) : 1;
    }

    /* =====================================================================
     * Helpers check
     * ================================================================== */

    private function status_check($id, $label, $value, $status, $message) {
        return compact('id', 'label', 'value', 'status', 'message');
    }

    private function presence_check($id, $label, $present) {
        if ($present === null) {
            return $this->status_check($id, $label, '', 'warn', __('Non determinabile.', 'db-seo-manager'));
        }
        return $this->status_check(
            $id,
            $label,
            $present ? 1 : 0,
            $present ? 'good' : 'warn',
            $present ? __('Trovata.', 'db-seo-manager') : __('Non trovata.', 'db-seo-manager')
        );
    }

    private function readability_check($r) {
        $is_gulpease = ($r['formula'] === 'gulpease');
        $score = (float) $r['score'];

        if ($is_gulpease) {
            $status = $score >= 60 ? 'good' : ($score >= 40 ? 'warn' : 'bad');
        } else {
            $status = $score >= 60 ? 'good' : ($score >= 40 ? 'warn' : 'bad');
        }

        return $this->status_check(
            'readability',
            __('Leggibilità', 'db-seo-manager'),
            $score,
            $status,
            sprintf('%s — %s (%s)',
                $r['label'],
                strtoupper($r['formula']),
                $score
            )
        );
    }

    private function aggregate_score($checks) {
        $total = count($checks);
        if ($total === 0) return 0;
        $weights = array('good' => 1.0, 'warn' => 0.5, 'bad' => 0.0);
        $sum = 0.0;
        foreach ($checks as $c) {
            $sum += $weights[$c['status']] ?? 0;
        }
        return (int) round(($sum / $total) * 100);
    }

    /* =====================================================================
     * Utility string
     * ================================================================== */

    private function contains($haystack, $needle) {
        if ($haystack === '' || $needle === '') return false;
        return mb_strpos($haystack, $needle) !== false;
    }

    private function slugify($s) {
        $s = remove_accents($s);
        $s = preg_replace('/[^a-z0-9]+/i', '-', strtolower($s));
        return trim($s, '-');
    }
}
