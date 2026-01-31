(function () {
    // Patch axios responses used by phone-orders plugin
    const originalAxiosPost = window.axios?.post;

    if (!originalAxiosPost) return;

    window.axios.post = function (url, data, ...rest) {
        return originalAxiosPost.call(this, url, data, ...rest).then((response) => {
            // Nếu có data.state thì giữ nguyên
            if (response?.data?.state) return response;

            // Nếu thiếu, thêm object trống để tránh lỗi
            response.data.state = {
                default_customer: {},
                default_order_custom_field_values: {},
                default_order_status: null,
                default_order_currency: null,
                log_row_id: null,
            };

            console.warn("📦 Đã patch phản hồi thiếu state từ phone-orders", response);
            return response;
        });
    };
})();
