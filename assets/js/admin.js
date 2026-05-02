/* DB SEO Manager — Admin JS
 * Image picker per i campi settings + conferma di eliminazione
 * sui form con attributo data-confirm-delete.
 */
(function ($) {
    'use strict';

    if (typeof DBSEOAdmin === 'undefined') return;

    $(function () {
        bindImagePickers();
        bindDeleteConfirmation();
    });

    function bindImagePickers() {
        var frames = {};

        $(document).on('click', '.dbseo-image-select', function (e) {
            e.preventDefault();
            var $field = $(this).closest('.dbseo-image-field, .dbseo-image-picker');
            var key    = $field.data('field');

            if (!frames[key]) {
                frames[key] = wp.media({
                    title:    DBSEOAdmin.i18n.mediaTitle,
                    button:   { text: DBSEOAdmin.i18n.mediaButton },
                    multiple: false,
                    library:  { type: 'image' }
                });
                frames[key].on('select', function () {
                    var att = frames[key].state().get('selection').first().toJSON();
                    var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
                    $field.find('.dbseo-image-id').val(att.id);
                    $field.find('.dbseo-image-preview').html(
                        '<img src="' + url + '" alt="" style="max-width:200px;border-radius:4px;border:1px solid var(--db-border)">'
                    );
                    if ($field.find('.dbseo-image-remove').length === 0) {
                        $field.find('.dbseo-image-select').after(
                            ' <button type="button" class="db-ui-btn dbseo-image-remove">' + DBSEOAdmin.i18n.remove + '</button>'
                        );
                    }
                });
            }
            frames[key].open();
        });

        $(document).on('click', '.dbseo-image-remove', function (e) {
            e.preventDefault();
            var $field = $(this).closest('.dbseo-image-field, .dbseo-image-picker');
            $field.find('.dbseo-image-id').val('');
            $field.find('.dbseo-image-preview').empty();
            $(this).remove();
        });
    }

    function bindDeleteConfirmation() {
        $('form[data-confirm-delete]').on('submit', function (e) {
            if (!window.confirm(DBSEOAdmin.i18n.confirmDelete)) {
                e.preventDefault();
                return false;
            }
        });
    }

})(jQuery);
