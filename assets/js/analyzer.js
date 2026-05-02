/* DB SEO Manager — Analyzer JS
 * Chiama l'endpoint dbseo_analyze e renderizza i risultati nel pannello "Analisi".
 *
 * Privacy: la chiamata è interna al sito WP (admin-ajax). Nessun dato esce.
 */
(function ($) {
    'use strict';

    if (typeof DBSEO === 'undefined') return;

    window.DBSEOAnalyzer = {
        run: function (postId) {
            var $btn  = $('#dbseo-run-analysis');
            var $out  = $('#dbseo-analysis-results');

            if (!postId) return;

            $btn.prop('disabled', true).text('…');
            $out.html('<p class="dbseo-loading">Analisi in corso…</p>');

            // Recupera contenuto e titolo dal block editor o dal classic editor.
            var content   = getEditorContent();
            var title     = getEditorTitle();
            var seoTitle  = $('#dbseo_title').val() || '';
            var seoDesc   = $('#dbseo_description').val() || '';
            var focus     = $('#dbseo_focus_keyword').val() || '';

            $.ajax({
                url:    DBSEO.ajaxUrl,
                method: 'POST',
                data: {
                    action:    'dbseo_analyze',
                    nonce:     DBSEO.nonce,
                    post_id:   postId,
                    content:   content,
                    title:     title,
                    seo_title: seoTitle,
                    seo_desc:  seoDesc,
                    focus:     focus
                }
            })
            .done(function (resp) {
                if (resp && resp.success) {
                    renderResults(resp.data, $out);
                } else {
                    $out.html('<p class="dbseo-error">Errore durante l\'analisi.</p>');
                }
            })
            .fail(function () {
                $out.html('<p class="dbseo-error">Errore di rete.</p>');
            })
            .always(function () {
                $btn.prop('disabled', false).text('Esegui analisi');
            });
        }
    };

    function getEditorContent() {
        // Block editor.
        if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
            try {
                return wp.data.select('core/editor').getEditedPostContent() || '';
            } catch (e) { /* fallthrough */ }
        }
        // Classic editor TinyMCE.
        if (typeof tinymce !== 'undefined') {
            var ed = tinymce.get('content');
            if (ed && !ed.isHidden()) return ed.getContent();
        }
        // Fallback textarea.
        return $('#content').val() || '';
    }

    function getEditorTitle() {
        if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
            try {
                return wp.data.select('core/editor').getEditedPostAttribute('title') || '';
            } catch (e) { /* fallthrough */ }
        }
        return $('#title').val() || '';
    }

    function renderResults(data, $out) {
        var html = '';

        // Score.
        var score = data.score || 0;
        var scoreClass = score >= 80 ? 'good' : (score >= 50 ? 'warn' : 'bad');
        html += '<div class="dbseo-score is-' + scoreClass + '">';
        html += '<span class="dbseo-score__value">' + score + '</span>';
        html += '<span class="dbseo-score__label">/100</span>';
        html += '</div>';

        // Metrics summary.
        var m = data.metrics || {};
        html += '<ul class="dbseo-metrics">';
        html += metricRow('Parole', m.word_count);
        html += metricRow('Frasi', m.sentence_count);
        html += metricRow('H2 / H3+', (m.headings ? m.headings.h2 : 0) + ' / ' + (m.headings ? m.headings.h3 : 0));
        html += metricRow('Link interni / esterni', (m.links ? m.links.internal : 0) + ' / ' + (m.links ? m.links.external : 0));
        html += metricRow('Immagini con alt', (m.images ? m.images.with_alt : 0) + ' / ' + (m.images ? m.images.total : 0));
        if (m.keyword_density !== undefined && m.keyword_density > 0) {
            html += metricRow('Densità focus keyword', m.keyword_density + '%');
        }
        if (m.readability) {
            html += metricRow('Leggibilità', m.readability.score + ' (' + (m.readability.formula || '').toUpperCase() + ')');
        }
        html += '</ul>';

        // Checks.
        html += '<ul class="dbseo-checks">';
        (data.checks || []).forEach(function (c) {
            html += '<li class="dbseo-check is-' + c.status + '">';
            html += '<span class="dbseo-check__icon" aria-hidden="true">' + iconFor(c.status) + '</span>';
            html += '<span class="dbseo-check__label">' + escape(c.label) + '</span>';
            html += '<span class="dbseo-check__msg">' + escape(c.message) + '</span>';
            html += '</li>';
        });
        html += '</ul>';

        $out.html(html);
    }

    function metricRow(label, value) {
        return '<li><strong>' + escape(label) + ':</strong> ' + escape(String(value)) + '</li>';
    }

    function iconFor(status) {
        if (status === 'good') return '✓';
        if (status === 'warn') return '!';
        return '×';
    }

    function escape(str) {
        return $('<div>').text(str == null ? '' : str).html();
    }

})(jQuery);
