/* Invoice image export shared by /tao-don/ and the order board.
 * Source: hithean orders/assets/invoice-image-export.js; keep Theanmarket byte-identical. */
(function () {
    'use strict';
    var libraryReady = null;
    function loadLibrary(url) {
        if (window.html2canvas) return Promise.resolve();
        if (libraryReady) return libraryReady;
        libraryReady = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = url;
            script.onload = function () {
                if (window.html2canvas) resolve();
                else { libraryReady = null; reject(new Error('Thư viện tạo ảnh chưa tải được.')); }
            };
            script.onerror = function () { libraryReady = null; script.remove(); reject(new Error('Thư viện tạo ảnh chưa tải được.')); };
            document.head.appendChild(script);
        });
        return libraryReady;
    }
    function errorText(error) { return error && error.message ? error.message : String(error); }

    /** Bind image export to an invoice iframe and its own status message. */
    window.TheanInvoiceImage = function (options) {
        function message(text) { options.message.textContent = text; }
        function canvas() {
            return Promise.resolve().then(function () {
                var frame = options.frame;
                var doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
                if (!doc || !doc.querySelector('.oc-invoice') || doc.readyState !== 'complete') {
                    throw new Error('Hóa đơn chưa tải xong. Vui lòng thử lại sau khi hóa đơn hiển thị.');
                }
                var ready = Array.prototype.map.call(doc.images || [], function (img) {
                    if (img.complete) return Promise.resolve();
                    return new Promise(function (resolve) {
                        img.addEventListener('load', resolve, { once: true });
                        img.addEventListener('error', resolve, { once: true });
                    });
                });
                return Promise.all(ready.concat(doc.fonts ? doc.fonts.ready : Promise.resolve(), loadLibrary(options.libraryUrl))).then(function () {
                    if (frame.contentDocument !== doc) throw new Error('Hóa đơn đã thay đổi. Vui lòng thử lại.');
                    return window.html2canvas(doc.body, { scale: 2, useCORS: true, backgroundColor: '#ffffff' });
                });
            });
        }
        return {
            preload: function () { message(''); loadLibrary(options.libraryUrl).catch(function () {}); },
            download: function () {
                message('Đang tạo ảnh...');
                return canvas().then(function (image) {
                    var link = document.createElement('a');
                    link.href = image.toDataURL('image/jpeg', 0.92);
                    link.download = options.fileName();
                    link.click();
                    message('');
                }).catch(function (error) { message('Lỗi tạo ảnh: ' + errorText(error)); });
            },
            copy: function () {
                if (!navigator.clipboard || !window.ClipboardItem) {
                    message('Trình duyệt không hỗ trợ copy ảnh — hãy dùng Xuất JPG.');
                    return Promise.resolve();
                }
                message('Đang chuẩn bị ảnh...');
                var blob = canvas().then(function (image) {
                    return new Promise(function (resolve, reject) {
                        image.toBlob(function (value) { value ? resolve(value) : reject(new Error('Không tạo được ảnh.')); }, 'image/png');
                    });
                });
                // Keep clipboard.write in the click gesture, including Safari's async ClipboardItem support.
                blob.catch(function () {});
                try {
                    return navigator.clipboard.write([new window.ClipboardItem({ 'image/png': blob })]).then(function () {
                        message('✅ Đã copy ảnh hóa đơn — dán (Ctrl/Cmd+V) vào chat để gửi khách.');
                    }).catch(function (error) { message('Không copy được ảnh (' + errorText(error) + ') — hãy dùng Xuất JPG.'); });
                } catch (error) {
                    message('Không copy được ảnh (' + errorText(error) + ') — hãy dùng Xuất JPG.');
                    return Promise.resolve();
                }
            }
        };
    };
}());
