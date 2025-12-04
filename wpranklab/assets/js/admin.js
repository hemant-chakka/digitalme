// WPRankLab admin scripts

(function (wp) {
    if (typeof wp === 'undefined' || !wp.data || !wp.data.select) {
        return;
    }

    var wasSaving = false;

    wp.data.subscribe(function () {
        var editor = wp.data.select('core/editor');
        if (!editor) {
            return;
        }

        var isSaving = editor.isSavingPost();
        var isAutosaving = editor.isAutosavingPost ? editor.isAutosavingPost() : false;

        // Detect transition: was saving, now not saving, and not an autosave.
        if (wasSaving && !isSaving && !isAutosaving) {
            // Post save just finished -> reload page so PHP metabox reflects latest meta.
            window.location.reload();
        }

        wasSaving = isSaving;
    });
})(window.wp);
