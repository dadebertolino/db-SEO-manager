/* DB SEO Manager — Metabox JS
 * Tab switching, anteprima SERP live, counter caratteri, image picker,
 * trigger analyzer.
 *
 * Privacy: tutto avviene nel browser dell'autore. Nessuna chiamata esterna.
 */
(function ($) {
    'use strict';

    if (typeof DBSEO === 'undefined') return;

    var $box;
    var $title, $desc, $titleCounter, $descCounter;
    var $serpUrl, $serpTitle, $serpDesc;

    $(function () {
        $box = $('#dbseo-metabox');
        if ($box.length === 0) return;

        $title       = $('#dbseo_title');
        $desc        = $('#dbseo_description');
        $titleCounter = $('#dbseo-counter-title');
        $descCounter  = $('#dbseo-counter-desc');
        $serpUrl     = $('#dbseo-serp-url');
        $serpTitle   = $('#dbseo-serp-title');
        $serpDesc    = $('#dbseo-serp-desc');

        bindTabs();
        bindCounters();
        bindMediaPicker();
        bindAnalyzer();
        updatePreview();
    });

    /* ---------------- Tabs ---------------- */
    function bindTabs() {
        $box.on('click', '.dbseo-tab', function () {
            var $btn   = $(this);
            var target = $btn.data('tab');

            $box.find('.dbseo-tab').removeClass('is-active').attr('aria-selected', 'false');
            $btn.addClass('is-active').attr('aria-selected', 'true');

            $box.find('.dbseo-tab-panel').removeClass('is-active');
            $box.find('.dbseo-tab-panel[data-panel="' + target + '"]').addClass('is-active');
        });
    }

    /* ---------------- Counter ---------------- */
    function bindCounters() {
        $title.on('input', function () {
            renderCounter($titleCounter, $title.val(), DBSEO.maxTitle);
            updatePreview();
        });
        $desc.on('input', function () {
            renderCounter($descCounter, $desc.val(), DBSEO.maxDesc);
            updatePreview();
        });

        renderCounter($titleCounter, $title.val(), DBSEO.maxTitle);
        renderCounter($descCounter,  $desc.val(),  DBSEO.maxDesc);
    }

    function renderCounter($target, value, max) {
        var len = (value || '').length;
        var status, label;

        if (len === 0) {
            status = 'neutral';
            label  = '0 / ' + max;
        } else if (len < max * 0.5) {
            status = 'short';
            label  = len + ' / ' + max + ' — ' + DBSEO.i18n.tooShort;
        } else if (len <= max) {
            status = 'good';
            label  = len + ' / ' + max + ' — ' + DBSEO.i18n.good;
        } else {
            status = 'long';
            label  = len + ' / ' + max + ' — ' + DBSEO.i18n.tooLong;
        }

        $target.removeClass('is-good is-short is-long is-neutral')
               .addClass('is-' + status)
               .text(label);
    }

    /* ---------------- SERP preview ---------------- */
    function updatePreview() {
        var title = $title.val() || resolveTemplateFallback();
        var desc  = $desc.val()  || ($('#excerpt').val() || '');

        // Tronchiamo lato client a fini estetici.
        if (title.length > DBSEO.maxTitle) title = title.substring(0, DBSEO.maxTitle - 1) + '…';
        if (desc.length  > DBSEO.maxDesc)  desc  = desc.substring(0, DBSEO.maxDesc  - 1) + '…';

        $serpUrl.text(DBSEO.permalink || '');
        $serpTitle.text(title || DBSEO.siteName);
        $serpDesc.text(desc);
    }

    function resolveTemplateFallback() {
        // Usa il titolo del post + " - " + sitename come fallback visivo.
        var $postTitle = $('#title');
        var t = $postTitle.length ? $postTitle.val() : '';
        if (!t) {
            // Block editor: il titolo è in un'altra posizione.
            var $blockTitle = $('.editor-post-title__input, .wp-block-post-title');
            if ($blockTitle.length) t = $blockTitle.text() || $blockTitle.val();
        }
        return (t || '') + (t && DBSEO.siteName ? ' - ' + DBSEO.siteName : DBSEO.siteName);
    }

    /* ---------------- Media picker ---------------- */
    function bindMediaPicker() {
        var frames = {};

        $box.on('click', '.dbseo-image-select', function (e) {
            e.preventDefault();
            var $picker = $(this).closest('.dbseo-image-picker');
            var field   = $picker.data('field');

            if (!frames[field]) {
                frames[field] = wp.media({
                    title:    DBSEO.i18n.mediaTitle,
                    button:   { text: DBSEO.i18n.mediaButton },
                    multiple: false,
                    library:  { type: 'image' }
                });
                frames[field].on('select', function () {
                    var att = frames[field].state().get('selection').first().toJSON();
                    $picker.find('.dbseo-image-id').val(att.id);
                    var preview = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
                    $picker.find('.dbseo-image-preview').html('<img src="' + preview + '" alt="">');
                    if ($picker.find('.dbseo-image-remove').length === 0) {
                        $picker.find('.dbseo-image-select').after(
                            ' <button type="button" class="button-link dbseo-image-remove">' + DBSEO.i18n.remove + '</button>'
                        );
                    }
                });
            }
            frames[field].open();
        });

        $box.on('click', '.dbseo-image-remove', function (e) {
            e.preventDefault();
            var $picker = $(this).closest('.dbseo-image-picker');
            $picker.find('.dbseo-image-id').val('');
            $picker.find('.dbseo-image-preview').empty();
            $(this).remove();
        });
    }

    /* ---------------- Analyzer trigger ---------------- */
    function bindAnalyzer() {
        $('#dbseo-run-analysis').on('click', function () {
            if (typeof DBSEOAnalyzer !== 'undefined' && typeof DBSEOAnalyzer.run === 'function') {
                DBSEOAnalyzer.run($box.data('post-id'));
            }
        });
    }

})(jQuery);
