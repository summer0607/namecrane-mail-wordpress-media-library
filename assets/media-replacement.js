(function ($, window) {
    'use strict';

    if (!window.YXFGalleryReplacement || !window.YXFGalleryReplacement.enabled) return;

    var config = window.YXFGalleryReplacement;
    var callbacks = (window.YXFGalleryMediaCallbacks = window.YXFGalleryMediaCallbacks || {});

    function galleryUrl(options, callbackKey) {
        var query = {
            type: 'yxf_gallery',
            TB_iframe: 1,
            width: 900,
            height: 620,
            yxf_gallery_callback: callbackKey,
            yxf_gallery_type: options.type || 'all',
            yxf_gallery_multiple: options.multiple || 1,
            // 上传是所有主题上传入口的首要操作；媒体列表仅在用户主动切换后显示。
            yxf_gallery_tab: options.tab || 'upload'
        };
        return config.iframeUrl + '?' + $.param(query);
    }

    function closeGalleryOverlay() {
        $('#yxf-gallery-overlay').remove();
    }
    window.YXFGalleryClose = function () {
        if ($('#TB_window').length && typeof window.tb_remove === 'function') window.tb_remove();
        else closeGalleryOverlay();
    };

    function openGallery(options, callback) {
        var key = 'yxf_gallery_' + Date.now() + '_' + Math.random().toString(36).slice(2);
        callbacks[key] = function (items) {
            try { callback(items || []); } finally {
                closeGalleryOverlay();
                window.setTimeout(function () { delete callbacks[key]; }, 0);
            }
        };
        if (typeof window.tb_show === 'function') {
            window.tb_show('游先锋图库', galleryUrl(options || {}, key));
        } else {
            closeGalleryOverlay();
            var $overlay = $('<div id="yxf-gallery-overlay" role="dialog" aria-modal="true"><div class="yxf-gallery-overlay-panel"><button type="button" class="yxf-gallery-overlay-close" aria-label="关闭">×</button><iframe title="游先锋图库" src="' + galleryUrl(options || {}, key) + '"></iframe></div></div>');
            $overlay.on('click', '.yxf-gallery-overlay-close', function () { closeGalleryOverlay(); delete callbacks[key]; });
            $('body').append($overlay);
        }
    }

    function itemData(item) {
        var mime = item.mime || 'application/octet-stream';
        var kind = item.kind || mime.split('/')[0];
        return {
            id: item.attachmentId || 0,
            url: item.url,
            large_url: item.url,
            thumbnail_url: kind === 'image' ? item.url : '',
            filename: item.name || '媒体文件',
            name: item.name || '媒体文件',
            title: item.name || '媒体文件',
            mime: mime,
            type: kind === 'application' ? 'file' : kind,
            filesizeInBytes: 0
        };
    }

    function GalleryThemeMedia(args) {
        this.option = $.extend({ type: 'image', multiple: 1 }, args || {});
        this.type = this.option.type || 'image';
        this.active_lists = [];
        this.input_lists = [];
        this.$el = $({});
        this.open = function () {
            var that = this;
            openGallery({ type: that.type, multiple: that.option.multiple || 1 }, function (items) {
                var data = items.map(itemData);
                if (data.length) that.$el.trigger('select_submit').trigger('lists_submit', { data: data, ids: data.map(function (item) { return item.id; }) });
            });
            return that;
        };
        this.close = function () { if (typeof window.tb_remove === 'function') window.tb_remove(); };
        this.resetActiveLists = function () { this.active_lists = []; return this; };
        this.setActiveLists = function (ids) { this.active_lists = ids || []; return this; };
        this.resetInputVals = function () { this.input_lists = []; return this; };
        this.setInputVals = function (vals) { this.input_lists = vals || []; return this; };
        this.formatSize = function () { return ''; };
    }

    function patchZibMedia() {
        if (!window.zib || !window.zib.media || window.zib.media.__yxfGalleryWrapped) return;
        var original = window.zib.media;
        function WrappedMedia(args) {
            // 子比和日主题的前台编辑器都可复用同一个 zib.media 接口；所有类型均转到图库。
            return new GalleryThemeMedia(args);
        }
        WrappedMedia.__yxfGalleryWrapped = true;
        WrappedMedia.__yxfOriginalMedia = original;
        window.zib.media = WrappedMedia;
    }

    function selectInWordPressFrame(items, frame) {
        frame = frame || (window.wp && window.wp.media && window.wp.media.frame);
        if (!frame || !items.length) return;
        var models = items.filter(function (item) { return item.attachmentId; }).map(function (item) {
            var attachment = window.wp.media.attachment(item.attachmentId);
            attachment.set({ id: item.attachmentId, url: item.url, type: item.kind || (item.mime || '').split('/')[0], subtype: item.mime, filename: item.name, title: item.name }, { silent: true });
            return attachment;
        });
        if (!models.length) return;
        var state = frame.state && frame.state();
        var selection = state && state.get && state.get('selection');
        if (selection && selection.reset) selection.reset(models);
        if (frame.trigger) frame.trigger('select');
    }

    function mediaTypeFromOptions(options) {
        options = options || {};
        var library = options.library || {};
        var type = library.type || options.type || (options.state === 'featured-image' ? 'image' : 'all');
        if ($.isArray(type)) type = type[0] || 'all';
        return type || 'all';
    }

    function patchWordPressMedia() {
        if (!window.wp || !window.wp.media || window.wp.media.__yxfGalleryWrapped) return;
        var original = window.wp.media;
        function WrappedWordPressMedia(options) {
            var frame = original.apply(window.wp, arguments);
            if (!frame || frame.__yxfGalleryFrame) return frame;
            frame.__yxfGalleryFrame = true;
            frame.__yxfGalleryOriginalOpen = frame.open;
            frame.open = function () {
                var that = this;
                var state = that.state && that.state();
                var selection = state && state.get && state.get('selection');
                var multiple = selection && selection.multiple ? 99 : (options && options.multiple ? 99 : 1);
                window.wp.media.frame = that;
                openGallery({ type: mediaTypeFromOptions(options), multiple: multiple }, function (items) {
                    selectInWordPressFrame(items, that);
                });
                return that;
            };
            return frame;
        }
        $.each(original, function (key, value) { WrappedWordPressMedia[key] = value; });
        WrappedWordPressMedia.__yxfGalleryWrapped = true;
        WrappedWordPressMedia.__yxfGalleryOriginal = original;
        window.wp.media = WrappedWordPressMedia;
    }

    function editorHtml(items) {
        return items.map(function (item) {
            var kind = item.kind || (item.mime || '').split('/')[0];
            if (kind === 'image') return '<img src="' + String(item.url || '').replace(/"/g, '&quot;') + '" alt="">';
            return '<a href="' + String(item.url || '').replace(/"/g, '&quot;') + '">' + String(item.name || item.url || '媒体文件').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</a>';
        }).join('');
    }

    function patchWordPressEditorOpen() {
        if (!window.wp || !window.wp.media || !window.wp.media.editor || !window.wp.media.editor.open || window.wp.media.editor.open.__yxfGalleryWrapped) return;
        var originalOpen = window.wp.media.editor.open;
        function openFromGallery(editorId, options) {
            options = options || {};
            var multiple = options.multiple ? 99 : 1;
            var type = mediaTypeFromOptions(options);
            openGallery({ type: type, multiple: multiple }, function (items) {
                if (!items.length) return;
                var html = editorHtml(items);
                if (typeof window.send_to_editor === 'function') {
                    window.send_to_editor(html);
                    return;
                }
                var editor = window.tinymce && window.tinymce.get && window.tinymce.get(editorId || window.wpActiveEditor);
                if (editor && editor.insertContent) editor.insertContent(html);
            });
            return false;
        }
        openFromGallery.__yxfGalleryWrapped = true;
        openFromGallery.__yxfGalleryOriginal = originalOpen;
        window.wp.media.editor.open = openFromGallery;
    }

    function typeFromElement(element) {
        var accept = String($(element).attr('accept') || '').toLowerCase();
        if (accept.indexOf('video') !== -1) return 'video';
        if (accept.indexOf('audio') !== -1) return 'audio';
        if (accept.indexOf('image') !== -1 || $(element).hasClass('z_upload_image_button') || $(element).hasClass('ashu_upload_button')) return 'image';
        return 'all';
    }

    function selectionLimit(element) {
        return $(element).is('[multiple]') || $(element).attr('multiple_max') ? 99 : 1;
    }

    function updatePreview($scope, item) {
        if (!item) return;
        var kind = item.kind || (item.mime || '').split('/')[0];
        if (kind === 'image') {
            $scope.find('.csf--src,.taxonomy-image,.preview img,.upload-preview img').first().attr('src', item.url);
            $scope.find('.csf--preview').removeClass('hidden');
        }
    }

    /** 将图库选中的外链写回子比和设置组件常用的地址字段，并通知页面的自定义逻辑。 */
    function applyThemeSelection(element, items) {
        if (!items.length) return;
        var item = items[0];
        var $element = $(element);
        var $field = $element.closest('.csf-field,.csf-fieldset,.widget_ui_slider_g,td,form,label');
        if (!$field.length) $field = $element.parent();

        // 子比 Codestar 字段：地址、ID、缩略图和预览与其原本的选择逻辑保持同样的字段结构。
        $field.find('.csf--url,.csf--wrap input[type="text"]').first().val(item.url).trigger('change');
        $field.find('.csf--id').val(item.attachmentId || 0).trigger('change');
        $field.find('.csf--thumbnail').val(item.url).trigger('change');
        $field.find('.csf--title').val(item.name || '').trigger('change');

        // 子比小工具、分类封面及常规“选择图片”按钮。
        if ($element.hasClass('ashu_upload_button')) {
            $element.siblings('label').find('input[type="text"]').first().val(item.url).trigger('change');
            $element.siblings('div').first().html((item.kind || '').indexOf('image') === 0 ? '<img src="' + String(item.url).replace(/"/g, '&quot;') + '">' : '<a href="' + String(item.url).replace(/"/g, '&quot;') + '">媒体文件</a>');
        }
        if ($element.hasClass('z_upload_image_button')) {
            $element.closest('td').find('#taxonomy_image,input[type="text"]').first().val(item.url).trigger('change');
        }

        var $file = $element.is('input[type="file"]') ? $element : $element.closest('label').find('input[type="file"]').first();
        if ($file.length) {
            $file.attr('data-yxf-gallery-url', item.url).attr('data-yxf-gallery-attachment', item.attachmentId || 0).trigger('yxf_gallery_selected', [item, items]);
            // 表单若预留了地址字段，则直接填入，避免再写入网站 uploads。
            var $urlInput = $field.find('input[type="url"],input[type="text"][name*="url"],input[type="hidden"][name*="url"]').first();
            if ($urlInput.length) $urlInput.val(item.url).trigger('change');
        }
        updatePreview($field, item);
        $element.trigger('yxf_gallery_selected', [item, items]);
    }

    function galleryButtonTarget(target) {
        var $target = $(target);
        if ($target.is('input[type="file"]')) return target;
        var $file = $target.closest('label').find('input[type="file"]').first();
        if ($file.length) return $file[0];
        if ($target.closest('.preview').length) {
            $file = $target.closest('form,.form-upload,.mini-upload').find('[zibupload="image_upload"],input[type="file"]').first();
            if ($file.length) return $file[0];
        }
        return target;
    }

    /**
     * 主题存在直接 file input、组件“添加图像”、小工具“选择图片”等多种写法。
     * 在捕获阶段统一拦截，确保它们都不会再唤起原生文件选择器或 WordPress 媒体库。
     */
    function interceptThemeUploadButtons() {
        document.addEventListener('click', function (event) {
            // 图库弹窗自身也会加载本脚本。若不排除它，点击“上传文件”标签
            // 会被误当作主题上传入口拦截，造成标签无法切换、文件无法选择。
            if (event.target.closest && event.target.closest('#yxf-media-frame')) return;
            var raw = event.target.closest && event.target.closest('input[type="file"],[zibupload="image_upload"],.z_upload_image_button,.ashu_upload_button,.csf--button,.upload-btn,.preview .add,.preview.upload-preview');
            if (!raw && event.target.closest) {
                var control = event.target.closest('button,a');
                var label = control ? String(control.getAttribute('title') || control.getAttribute('aria-label') || control.textContent || '').replace(/\s+/g, '') : '';
                if (control && /上传|添加(?:图像|图片)|选择(?:图像|图片)/.test(label) && !/确认|提交|保存|删除|移除/.test(label) && !$(control).is('[type="submit"],[zibupload="submit"]')) raw = control;
            }
            if (!raw || raw.getAttribute('data-yxf-gallery-bypass') === '1') return;
            var $raw = $(raw);
            if ($raw.is('[zibupload="submit"],[type="submit"]') || $raw.closest('[zibupload="submit"]').length) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            var target = galleryButtonTarget(raw);
            openGallery({ type: typeFromElement(target), multiple: selectionLimit(target) }, function (items) {
                applyThemeSelection(raw, items);
                if (target !== raw) applyThemeSelection(target, items);
            });
        }, true);
    }

    function patchClassicButton() {
        $('#insert-media-button').hide();
        $('#insert-yxf-gallery-button.is-media-replacement').show();
    }

    function observeMediaFrames() {
        patchClassicButton();
        new MutationObserver(function () {
            patchClassicButton();
        }).observe(document.body, { childList: true, subtree: true });
    }

    $(observeMediaFrames);
    $('<style id="yxf-gallery-overlay-style">#yxf-gallery-overlay{position:fixed;z-index:999999;inset:0;background:rgba(0,0,0,.48);display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box}#yxf-gallery-overlay .yxf-gallery-overlay-panel{position:relative;width:min(960px,100%);height:min(700px,100%);background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 12px 36px rgba(0,0,0,.32)}#yxf-gallery-overlay iframe{display:block;width:100%;height:100%;border:0}.yxf-gallery-overlay-close{position:absolute;z-index:1;right:10px;top:8px;border:0;background:rgba(0,0,0,.45);color:#fff;width:30px;height:30px;border-radius:50%;font-size:22px;line-height:28px;cursor:pointer}@media(max-width:782px){#yxf-gallery-overlay{padding:0}#yxf-gallery-overlay .yxf-gallery-overlay-panel{width:100%;height:100%;border-radius:0}}</style>').appendTo('head');
    patchZibMedia();
    patchWordPressMedia();
    patchWordPressEditorOpen();
    interceptThemeUploadButtons();
    var attempts = 0;
    var timer = window.setInterval(function () {
        patchZibMedia();
        patchWordPressMedia();
        patchWordPressEditorOpen();
        attempts++;
        if (attempts > 240) window.clearInterval(timer);
    }, 250);
}(jQuery, window));
