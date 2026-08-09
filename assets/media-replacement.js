(function ($, window) {
    'use strict';

    if (!window.YXFGalleryReplacement || !window.YXFGalleryReplacement.enabled) return;

    var config = window.YXFGalleryReplacement;
    var callbacks = (window.YXFGalleryMediaCallbacks = window.YXFGalleryMediaCallbacks || {});

    function galleryUrl(options, callbackKey) {
        return config.iframeUrl + '?' + $.param({
            yxf_gallery_frame: 1,
            type: 'yxf_gallery',
            TB_iframe: 1,
            width: 900,
            height: 620,
            yxf_gallery_callback: callbackKey,
            yxf_gallery_type: options.type || 'all',
            yxf_gallery_multiple: options.multiple || 1,
            yxf_gallery_tab: 'upload'
        });
    }

    function closeGalleryOverlay() {
        $('#yxf-gallery-overlay').remove();
    }
    window.YXFGalleryClose = closeGalleryOverlay;

    window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin || !event.data || event.data.type !== 'yxf_gallery_insert') return;
        var callback = callbacks[event.data.callbackKey];
        if (typeof callback === 'function') callback(event.data.items || []);
    });

    function openGallery(options, callback) {
        var key = 'yxf_gallery_' + Date.now() + '_' + Math.random().toString(36).slice(2);
        callbacks[key] = function (items) {
            try {
                callback(items || []);
            } finally {
                closeGalleryOverlay();
                window.setTimeout(function () { delete callbacks[key]; }, 0);
            }
        };
        closeGalleryOverlay();
        var $overlay = $('<div id="yxf-gallery-overlay" role="dialog" aria-modal="true"><div class="yxf-gallery-overlay-panel"><button type="button" class="yxf-gallery-overlay-close" aria-label="关闭">×</button><iframe title="NameCrane媒体库" src="' + galleryUrl(options || {}, key) + '"></iframe></div></div>');
        $overlay.on('click', '.yxf-gallery-overlay-close', function () {
            delete callbacks[key];
            closeGalleryOverlay();
        });
        $('body').append($overlay);
    }

    function mediaTypeFromOptions(options) {
        options = options || {};
        var library = options.library || {};
        var type = library.type || options.type || (options.state === 'featured-image' ? 'image' : 'all');
        if ($.isArray(type)) type = type[0] || 'all';
        return type || 'all';
    }

    function selectionMultiple(frame, options) {
        var state = frame && frame.state && frame.state();
        var selection = state && state.get && state.get('selection');
        if (selection && selection.multiple) return 99;
        return options && options.multiple ? 99 : 1;
    }

    function selectInWordPressFrame(items, frame) {
        if (!frame || !items.length || !window.wp || !window.wp.media) return;
        var models = items.filter(function (item) { return item.attachmentId; }).map(function (item) {
            var attachment = window.wp.media.attachment(item.attachmentId);
            attachment.set({
                id: item.attachmentId,
                url: item.url,
                type: item.kind || (item.mime || '').split('/')[0],
                subtype: item.mime,
                filename: item.name,
                title: item.name
            }, { silent: true });
            return attachment;
        });
        var state = frame.state && frame.state();
        var selection = state && state.get && state.get('selection');
        if (selection && selection.reset) selection.reset(models);
        if (frame.trigger) frame.trigger('select');
    }

    function wrapFrame(frame, options) {
        if (!frame || frame.__yxfGalleryFrame) return frame;
        frame.__yxfGalleryFrame = true;
        frame.__yxfGalleryOriginalOpen = frame.open;
        frame.open = function () {
            var that = this;
            if (window.wp && window.wp.media) window.wp.media.frame = that;
            openGallery({
                type: mediaTypeFromOptions(options),
                multiple: selectionMultiple(that, options)
            }, function (items) {
                selectInWordPressFrame(items, that);
            });
            return that;
        };
        return frame;
    }

    function patchWordPressMedia() {
        if (!window.wp || !window.wp.media) return;
        var media = window.wp.media;
        if (!media.__yxfGalleryWrapped) {
            // 闭包必须始终指向替换前的原始工厂函数。若随后改用 WrappedWordPressMedia，
            // 这里再调用 media.apply() 就会递归调用自己，部分后台上传按钮会因此完全无响应。
            var originalMedia = media;
            function WrappedWordPressMedia(options) {
                return wrapFrame(originalMedia.apply(window.wp, arguments), options);
            }
            $.each(originalMedia, function (key, value) { WrappedWordPressMedia[key] = value; });
            // WordPress 将这些接口挂在 media 函数自身；显式保留以兼容不同版本的属性定义方式。
            ['attachment', 'editor', 'view', 'model', 'frames', 'frame', 'query'].forEach(function (key) {
                if (originalMedia[key] !== undefined) WrappedWordPressMedia[key] = originalMedia[key];
            });
            WrappedWordPressMedia.__yxfGalleryWrapped = true;
            WrappedWordPressMedia.__yxfGalleryOriginal = originalMedia;
            window.wp.media = WrappedWordPressMedia;
            media = WrappedWordPressMedia;
        }

        // 部分插件直接创建 MediaFrame.Select，不经 wp.media()；同样接管其打开动作。
        var SelectFrame = media.view && media.view.MediaFrame && media.view.MediaFrame.Select;
        if (SelectFrame && SelectFrame.prototype && !SelectFrame.prototype.__yxfGalleryOpenWrapped) {
            var originalOpen = SelectFrame.prototype.open;
            SelectFrame.prototype.__yxfGalleryOpenWrapped = true;
            SelectFrame.prototype.__yxfGalleryOriginalOpen = originalOpen;
            SelectFrame.prototype.open = function () {
                return wrapFrame(this, this.options || {}).open();
            };
        }
    }

    function editorHtml(items) {
        return items.map(function (item) {
            var url = String(item.url || '').replace(/"/g, '&quot;');
            var name = String(item.name || item.url || '媒体文件').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            return (item.kind || '').toLowerCase() === 'image' ? '<img src="' + url + '" alt="">' : '<a href="' + url + '">' + name + '</a>';
        }).join('');
    }

    function patchWordPressEditorOpen() {
        if (!window.wp || !window.wp.media || !window.wp.media.editor || !window.wp.media.editor.open || window.wp.media.editor.open.__yxfGalleryWrapped) return;
        var originalOpen = window.wp.media.editor.open;
        function openFromGallery(editorId, options) {
            options = options || {};
            openGallery({ type: mediaTypeFromOptions(options), multiple: options.multiple ? 99 : 1 }, function (items) {
                if (!items.length) return;
                if (typeof window.send_to_editor === 'function') {
                    window.send_to_editor(editorHtml(items));
                    return;
                }
                var editor = window.tinymce && window.tinymce.get && window.tinymce.get(editorId || window.wpActiveEditor);
                if (editor && editor.insertContent) editor.insertContent(editorHtml(items));
            });
            return false;
        }
        openFromGallery.__yxfGalleryWrapped = true;
        openFromGallery.__yxfGalleryOriginal = originalOpen;
        window.wp.media.editor.open = openFromGallery;
    }

    function isVisible(element) {
        return !!(element && (element.offsetWidth || element.offsetHeight || element.getClientRects().length));
    }

    function fileInputFromUploadClick(target) {
        if (!target || !target.closest) return null;
        if (target.matches && target.matches('input[type="file"]')) return target;
        var label = target.closest('label');
        if (label) {
            var labelledInput = label.querySelector('input[type="file"]');
            if (labelledInput) return labelledInput;
        }
        return null;
    }

    function mediaTypeFromFileInput(input) {
        var accept = String((input && input.getAttribute('accept')) || '').toLowerCase();
        if (accept.indexOf('image') !== -1) return 'image';
        if (accept.indexOf('video') !== -1) return 'video';
        if (accept.indexOf('audio') !== -1) return 'audio';
        return 'all';
    }

    function nearestMediaDialog(input) {
        var dialog = input && input.closest && input.closest('[role="dialog"], .media-modal, .modal, .mce-window');
        return isVisible(dialog) ? dialog : null;
    }

    function insertIntoActiveEditor(items) {
        if (!items || !items.length) return;
        var editor = window.tinymce && window.tinymce.activeEditor;
        if (editor && editor.insertContent) {
            editor.insertContent(editorHtml(items));
            if (editor.fire) editor.fire('change');
            return;
        }
        if (typeof window.send_to_editor === 'function') window.send_to_editor(editorHtml(items));
    }

    // 有些前端编辑器不会调用 wp.media()，而是在自己的“选择文件”窗口内放一个原生
    // file input。这里仅接管“可见媒体弹窗”内的文件选择，不碰设置页、导入页等普通表单。
    // 因此不依赖任何主题名称或固定 class，也不会把单纯包含“上传”字样的设置项误当成上传入口。
    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || $(event.target).closest('#yxf-gallery-overlay').length) return;
        var input = fileInputFromUploadClick(event.target);
        var dialog = nearestMediaDialog(input);
        if (!input || !dialog || input.dataset.yxfGalleryKeepNative === '1') return;

        event.preventDefault();
        event.stopImmediatePropagation();
        openGallery({ type: mediaTypeFromFileInput(input), multiple: input.multiple ? 99 : 1 }, function (items) {
            var detail = { items: items || [], input: input };
            dialog.dispatchEvent(new CustomEvent('yxf-gallery-selected', { bubbles: true, detail: detail }));
            insertIntoActiveEditor(detail.items);
        });
    }, true);

    $('<style id="yxf-gallery-overlay-style">#yxf-gallery-overlay{position:fixed;z-index:999999;inset:0;background:rgba(0,0,0,.48);display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box}#yxf-gallery-overlay .yxf-gallery-overlay-panel{position:relative;width:min(960px,100%);height:min(700px,100%);background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 12px 36px rgba(0,0,0,.32)}#yxf-gallery-overlay iframe{display:block;width:100%;height:100%;border:0}.yxf-gallery-overlay-close{position:absolute;z-index:1;right:10px;top:8px;display:grid;place-items:center;margin:0;padding:0;border:0;background:rgba(0,0,0,.45);color:#fff;width:32px;height:32px;border-radius:50%;font-family:Arial,sans-serif;font-size:25px;font-weight:300;line-height:1;cursor:pointer}@media(max-width:782px){#yxf-gallery-overlay{padding:0}#yxf-gallery-overlay .yxf-gallery-overlay-panel{width:100%;height:100%;border-radius:0}}</style>').appendTo('head');

    patchWordPressMedia();
    patchWordPressEditorOpen();
    var attempts = 0;
    var timer = window.setInterval(function () {
        patchWordPressMedia();
        patchWordPressEditorOpen();
        attempts++;
        if (attempts > 240) window.clearInterval(timer);
    }, 250);
}(jQuery, window));
