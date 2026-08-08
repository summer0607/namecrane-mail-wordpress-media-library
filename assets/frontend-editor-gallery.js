(function ($, window) {
    'use strict';

    var config = window.YXFGalleryReplacement || (typeof YXFGalleryReplacement !== 'undefined' ? YXFGalleryReplacement : null);
    if (!config || !config.enabled) return;
    var callbacks = (window.YXFGalleryMediaCallbacks = window.YXFGalleryMediaCallbacks || {});
    var activeThemeModal = null;
    var activeMediaType = 'all';

    function galleryUrl(callbackKey, type, multiple) {
        return config.iframeUrl + '?' + $.param({
            yxf_gallery_frame: 1,
            yxf_gallery_callback: callbackKey,
            yxf_gallery_type: type || 'all',
            yxf_gallery_multiple: multiple || 1,
            yxf_gallery_tab: 'upload',
            yxf_gallery_theme: $('body').hasClass('dark-theme') ? 'dark' : 'light'
        });
    }

    function closeGalleryOverlay() {
        $('#yxf-gallery-overlay').remove();
    }
    window.YXFGalleryClose = closeGalleryOverlay;

    function openGallery($themeModal, type, multiple, onChoose) {
        var key = 'yxf_gallery_' + Date.now() + '_' + Math.random().toString(36).slice(2);
        activeThemeModal = $themeModal;
        activeMediaType = type || 'all';
        callbacks[key] = function (items) {
            if (typeof onChoose === 'function') {
                onChoose(items || []);
                closeGalleryOverlay();
                window.setTimeout(function () { delete callbacks[key]; }, 0);
                return;
            }
            closeGalleryOverlay();
            // 让主题原有的媒体窗口接收选择结果。它自身知道应插入到哪一个编辑器，
            // 也会处理图片、附件等各自不同的插入格式。
            window.setTimeout(function () { insertItemsIntoActiveEditor(items || []); }, 30);
            window.setTimeout(function () { delete callbacks[key]; }, 0);
        };
        closeGalleryOverlay();
        var $overlay = $('<div id="yxf-gallery-overlay" role="dialog" aria-modal="true"><div class="yxf-gallery-overlay-panel"><button type="button" class="yxf-gallery-overlay-close" aria-label="关闭">×</button><iframe title="游先锋图库"></iframe></div></div>');
        $overlay.find('iframe').attr('src', galleryUrl(key, activeMediaType, multiple || 1));
        $overlay.on('click', '.yxf-gallery-overlay-close', function () { closeGalleryOverlay(); delete callbacks[key]; });
        $('body').append($overlay);
    }

    function itemData(item) {
        var mime = item.mime || 'application/octet-stream';
        var kind = item.kind || mime.split('/')[0];
        return {
            id: Number(item.attachmentId || item.id || 0),
            url: String(item.url || ''),
            large_url: String(item.url || ''),
            thumbnail_url: kind === 'image' ? String(item.url || '') : '',
            filename: item.name || '媒体文件',
            name: item.name || '媒体文件',
            title: item.name || '媒体文件',
            mime: mime,
            type: kind === 'application' ? 'file' : kind,
            filesizeInBytes: 0
        };
    }

    function appendGalleryItems($list, items) {
        if (!$list || !$list.length || !items.length) return;
        items.forEach(function (item) {
            var data = itemData(item);
            if (!data.id || !data.url || $list.find('.list-item[data-file-id="' + data.id + '"]').length) return;
            $list.find('.null-box').remove();
            var html;
            if (data.type === 'image') {
                html = '<div class="list-item" data-file-id="' + data.id + '" data-file-type="image"><div class="list-box"><img src="' + data.thumbnail_url.replace(/\"/g, '&quot;') + '" data-full-url="' + data.url.replace(/\"/g, '&quot;') + '" alt="' + data.title.replace(/\"/g, '&quot;') + '"></div></div>';
            } else {
                html = '<div class="list-item" data-file-id="' + data.id + '" data-file-type="file"><div class="list-box"><div class="px12 flex1 flex xx padding-6 padding-h6"><div class="flex ac"><div class="mr6 flex0"><div style="width:33px"><div class="list-item-icon-box flex jc"><i class="fa fa-file-text-o em12"></i></div></div></div><div class="text-ellipsis-2">' + data.filename.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div></div></div></div></div>';
            }
            $list.prepend(html);
        });
    }

    // 图库内上传完成后，立即回写到仍打开的主题“我的图片 / 我的文件”列表。
    window.YXFGalleryInsertIntoEditor = function (items) {
        closeGalleryOverlay();
        window.setTimeout(function () { insertItemsIntoActiveEditor(items || []); }, 30);
    };
    window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin || !event.data) return;
        if (event.data.type === 'yxf_gallery_uploaded' && activeThemeModal && activeThemeModal.length) {
            appendGalleryItems(activeThemeModal.find('.mini-media-my-lists.type-' + activeMediaType), [event.data.item || {}]);
        } else if (event.data.type === 'yxf_gallery_insert') {
            var callback = callbacks[event.data.callbackKey];
            if (typeof callback === 'function') callback(event.data.items || []);
            else window.YXFGalleryInsertIntoEditor(event.data.items || []);
        }
    });

    function isThemeEditorUploadButton(target) {
        return $(target).closest('.mini-media-modal .mini-media-my-box .upload-btn').length;
    }

    // 只截取前端主题编辑器“我的图片 / 我的文件”里的上传按钮。
    // 弹窗本身、外链标签和其他任何上传区域仍由主题原逻辑处理。
    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('.mini-media-modal .mini-media-my-box .upload-btn');
        if (!button || !isThemeEditorUploadButton(button)) return;
        var $modal = $(button).closest('.mini-media-modal');
        var $list = $modal.find('.mini-media-my-lists').first();
        var type = ($list.attr('class').match(/type-([a-z]+)/) || [])[1] || 'all';
        event.preventDefault();
        event.stopImmediatePropagation();
        openGallery($modal, type, 99);
    }, true);

    function insertItemsIntoActiveEditor(items) {
        if (!items.length) return;
        var selected = items.map(itemData).filter(function (item) { return item.url; });
        // 前台投稿和发帖均由子比主题自己的媒体窗口负责插入。向原窗口派发它
        // 正常“插入”按钮使用的事件，避免不同编辑器实例之间出现插入错位或丢失。
        if (activeThemeModal && activeThemeModal.length && selected.length) {
            // 没有图库编号的是用户手动粘贴的外部链接。交给主题原有的“输入外链”
            // 流程，图片、附件等都会被插到正确的编辑器，而不是被误认为图库文件。
            if (selected.every(function (item) { return !item.id; })) {
                var urls = selected.map(function (item) { return item.url; });
                var $urlInput = activeThemeModal.find('.input-input').first();
                var $urlSubmit = activeThemeModal.find('.input-submit').first();
                // 模拟主题自身的“外链地址确认”操作，而不是只派发事件；不同的前台编辑器
                // 会在该操作中保存当前光标位置，才能把外链准确插入正文。
                if ($urlInput.length && $urlSubmit.length) {
                    $urlInput.val(urls.join('\n'));
                    $urlSubmit.trigger('click');
                    return;
                }
                activeThemeModal.trigger('select_submit').trigger('input_submit', { vals: urls });
            } else {
                activeThemeModal.trigger('lists_submit', {
                    data: selected,
                    ids: selected.map(function (item) { return item.id; })
                });
            }
            activeThemeModal.modal('hide');
            return;
        }
        var html = items.map(function (item) {
            var url = String(item.url || '').replace(/\"/g, '&quot;');
            var name = String(item.name || item.url || '媒体文件').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            var kind = String(item.kind || (item.mime || '').split('/')[0]).toLowerCase();
            return kind === 'image'
                ? '<p><img src="' + url + '" data-full-url="' + url + '" data-edit-file-id="' + Number(item.attachmentId || 0) + '" alt="' + name + '"></p>'
                : '<p><a href="' + url + '" data-mce-href="' + url + '" data-download-file="' + url + '" class="but c-blue file-download-btn">' + name + '</a></p>';
        }).join('') + '<p></p>';
        var iframe = document.getElementById('post_content_ifr');
        var frameWindow = iframe && iframe.contentWindow;
        var body = iframe && iframe.contentDocument && iframe.contentDocument.body;
        var editor = (window.tinymce && window.tinymce.activeEditor) || (frameWindow && frameWindow.tinymce && frameWindow.tinymce.activeEditor);
        if (editor && editor.insertContent) {
            // 前端主题弹窗关闭时会丢失 TinyMCE 光标；直接追加正文，避免 insertContent 因无焦点而静默失败。
            if (editor.setContent && editor.getContent) editor.setContent((editor.getContent() || '') + html);
            else editor.insertContent(html);
            if (editor.undoManager && editor.undoManager.add) editor.undoManager.add();
            if (editor.fire) editor.fire('change');
            if (body && body.innerHTML.indexOf('data-edit-file-id') === -1) {
                body.insertAdjacentHTML('beforeend', html);
                body.dispatchEvent(new Event('input', {bubbles: true}));
            }
            return;
        }
        // 子比前台编辑器未向页面公开 TinyMCE 实例时，直接写入它的编辑区，
        // 并触发输入事件让主题同步正文内容。
        if (!body) return;
        body.insertAdjacentHTML('beforeend', html);
        body.dispatchEvent(new Event('input', {bubbles: true}));
        body.dispatchEvent(new Event('change', {bubbles: true}));
    }

    $('<style id="yxf-gallery-overlay-style">#yxf-gallery-overlay{position:fixed;z-index:999999;inset:0;background:rgba(0,0,0,.48);display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box}#yxf-gallery-overlay .yxf-gallery-overlay-panel{position:relative;width:min(800px,calc(100vw - 48px));height:min(580px,calc(100vh - 72px));background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 16px 42px rgba(0,0,0,.38)}#yxf-gallery-overlay iframe{display:block;width:100%;height:100%;border:0}.yxf-gallery-overlay-close{position:absolute;z-index:1;right:10px;top:8px;display:grid;place-items:center;margin:0;padding:0;border:0;background:rgba(0,0,0,.45);color:#fff;width:32px;height:32px;border-radius:50%;font-family:Arial,sans-serif;font-size:25px;font-weight:300;line-height:1;cursor:pointer}@media(max-width:782px){#yxf-gallery-overlay{padding:0}#yxf-gallery-overlay .yxf-gallery-overlay-panel{width:100%;height:100%;border-radius:0}}</style>').appendTo('head');

}(jQuery, window));
