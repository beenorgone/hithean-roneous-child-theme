/* Shared by Hithean and Theanmarket. Only warehouse evidence uploads use this helper. */
(function () {
    'use strict';
    if (window.TheanExportUpload) return;

    // Sequential decoding avoids retaining several full-resolution phone photos in memory.
    async function normalize(file) {
        if (!file.size || file.size > 32 * 1024 * 1024) {
            throw new Error('Ảnh “' + file.name + '” rỗng hoặc lớn hơn 32 MB. Hãy chọn ảnh nhỏ hơn.');
        }
        var url = URL.createObjectURL(file), img = new Image(), canvas;
        try {
            await new Promise(function (resolve, reject) {
                var timer = setTimeout(function () { img.src = ''; reject(new Error('Đọc ảnh quá lâu. Hãy xuất ảnh thành JPG rồi thử lại.')); }, 20000);
                img.onload = function () { clearTimeout(timer); resolve(); };
                img.onerror = function () { clearTimeout(timer); reject(new Error('Không đọc được ảnh “' + file.name + '”. Hãy xuất ảnh thành JPG/PNG rồi chọn lại.')); };
                img.src = url;
            });
            var width = img.naturalWidth, height = img.naturalHeight;
            if (!width || !height || width * height > 60000000) {
                throw new Error('Kích thước ảnh quá lớn. Hãy chọn ảnh không quá 60 megapixel.');
            }
            var scale = Math.min(1, 1600 / Math.max(width, height));
            canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(width * scale));
            canvas.height = Math.max(1, Math.round(height * scale));
            var ctx = canvas.getContext('2d');
            if (!ctx) throw new Error('Trình duyệt không xử lý được ảnh. Hãy thử trình duyệt khác.');
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            var blob = await new Promise(function (resolve) { canvas.toBlob(resolve, 'image/jpeg', 0.9); });
            if (!blob || blob.type !== 'image/jpeg') throw new Error('Không chuyển được ảnh sang JPG. Hãy xuất ảnh thành JPG rồi thử lại.');
            // Canvas writes fresh image bytes without copying EXIF or other source metadata.
            return new File([blob], (file.name.replace(/\.[^.]+$/, '') || 'export-image') + '.jpg', { type: 'image/jpeg' });
        } finally {
            img.src = '';
            URL.revokeObjectURL(url);
            if (canvas) { canvas.width = 0; canvas.height = 0; }
        }
    }

    async function prepare(formData) {
        var prepared = new FormData(), bytes = 0;
        for (var entry of formData.entries()) {
            var key = entry[0], value = entry[1];
            if (value instanceof Blob && (key === 'ueif_images[]' || key === 'images[]')) {
                value = await normalize(value);
                bytes += value.size;
                if (bytes > 20 * 1024 * 1024) throw new Error('Tổng ảnh sau xử lý vượt 20 MB. Hãy chọn ít ảnh hơn mỗi lần.');
            }
            prepared.append(key, value);
        }
        return prepared;
    }

    async function readResponse(response) {
        if (response.status === 413) throw new Error('Ảnh vượt giới hạn tải của máy chủ (HTTP 413). Hãy chọn ít ảnh hơn mỗi lần.');
        var text = await response.text(), json;
        if (text.trim() === '-1') throw new Error('Phiên upload đã hết hạn. Hãy tải lại trang rồi chọn ảnh để gửi lại.');
        try { json = JSON.parse(text); } catch (ignore) { /* Never show raw HTML/security responses. */ }
        if (response.status === 403) throw new Error('Máy chủ từ chối tải ảnh (HTTP 403). Hãy tải lại trang; nếu vẫn lỗi, gửi ảnh gốc cho quản trị viên kiểm tra.');
        if (response.status === 401 || response.redirected) throw new Error('Phiên đăng nhập có thể đã hết hạn. Hãy đăng nhập lại rồi tải ảnh.');
        if (!response.ok) throw new Error('Máy chủ gặp lỗi (HTTP ' + response.status + '). Kiểm tra ảnh đã lưu trước khi thử lại.');
        if (!json || typeof json.success !== 'boolean') throw new Error('Máy chủ trả phản hồi không hợp lệ. Kiểm tra ảnh đã lưu và tải lại trang trước khi thử lại.');
        return json;
    }

    async function upload(url, formData) {
        // Do not mutate the original form or fall back to uploading unprocessed bytes.
        var body = await prepare(formData), response;
        try { response = await fetch(url, { method: 'POST', body: body, credentials: 'same-origin' }); }
        catch (error) { throw new Error('Mất kết nối khi tải ảnh. Kiểm tra ảnh đã lưu trước khi thử lại.'); }
        return readResponse(response);
    }
    window.TheanExportUpload = { prepare: prepare, upload: upload, readResponse: readResponse };
})();
