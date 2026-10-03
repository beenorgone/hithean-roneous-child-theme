jQuery(function ($) {
    if (!$(".shortcode-product-search-widget").length) return;

    const $input = $("#input-product-info-search");
    const $btn = $("#btn-product-info-search");
    const $dropdown = $("#product-info-dropdown");

    const $cardsArea = $("#product-info-cards-area");
    const $defaultArea = $("#product-info-default-area");

    // ===== Utils =====
    function escapeHtml(s) {
        return String(s || "").replace(/[&<>"']/g, (m) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[m]));
    }
    function escapeAttr(s) {
        return String(s || "")
            .replace(/&/g, "&amp;")
            .replace(/"/g, "&quot;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;");
    }

    let lastResults = [];
    let defaultResults = [];

    // Dropdown
    function openDropdownLoading() {
        $dropdown.html(`<div class="product-info-dd-loading">${product_info_params.i18n.loading}</div>`).show();
    }
    function openDropdownEmpty() {
        $dropdown.html(`<div class="product-info-dd-empty">${product_info_params.i18n.noResult}</div>`).show();
    }
    function renderDropdown(items) {
        if (!items || !items.length) {
            openDropdownEmpty();
            return;
        }
        const lis = items
            .map(
                (it, idx) => `
                <li class="product-info-dd-item" data-idx="${idx}">
                    ${it.thumb ? `<img class="product-info-dd-thumb" src="${escapeAttr(it.thumb)}" alt="">` : `<span class="product-info-dd-thumb"></span>`}
                    <div class="product-info-dd-title">${escapeHtml(it.label)}</div>
                </li>`
            )
            .join("");
        $dropdown.html(`<ul class="product-info-dd-list">${lis}</ul>`).show();
    }
    function closeDropdown() {
        $dropdown.hide().empty();
    }

    $(document).on("mousedown", (e) => {
        if (!$(e.target).closest(".shortcode-product-search-widget").length) closeDropdown();
    });

    /**
     * Render Default List
     */
    function renderDefaultList(items) {
        $defaultArea.empty();
        if (!items || !items.length) return;

        defaultResults = items;

        const title = `<div class="product-info-default-title">${product_info_params.i18n.defaultTitle || "Sản phẩm gợi ý:"}</div>`;
        const lis = items
            .map(
                (it, idx) => `
            <li class="product-info-default-item" data-idx="${idx}">
                ${it.thumb ? `<img class="product-info-default-thumb" src="${escapeAttr(it.thumb)}" alt="">` : ""}
                <div class="product-info-default-label">${escapeHtml(it.label)}</div>
            </li>
        `
            )
            .join("");

        $defaultArea.html(title + `<ul class="product-info-default-list">${lis}</ul>`);
    }

    function searchProducts(isDefault = false) {
        const term = $input.val().trim();

        if (!isDefault && !term) {
            closeDropdown();
            return;
        }

        if (!isDefault) openDropdownLoading();

        $.ajax({
            url: product_info_params.ajax_url,
            method: "GET",
            dataType: "json",
            data: {
                action: "product_info_search",
                term: term,
                is_default: isDefault,
                exclude_private: $input.data("exclude-private"),
                default_ids: $input.data("default-ids") || "",
            },
            success: function (res) {
                const results = Array.isArray(res) ? res : [];

                if (isDefault) {
                    renderDefaultList(results);
                } else {
                    lastResults = results;
                    renderDropdown(lastResults);
                }
            },
            error: function () {
                if (!isDefault) openDropdownEmpty();
            },
        });
    }

    $btn.on("click", function () {
        searchProducts(false);
    });

    $input.on("keypress", function (e) {
        if (e.which === 13) {
            searchProducts(false);
            return false;
        }
    });

    // Select from Search Dropdown
    $dropdown.on("click", ".product-info-dd-item", function () {
        const idx = Number($(this).data("idx"));
        const item = lastResults[idx];
        if (item) {
            renderCard(item);
            closeDropdown();
            $input.val("");
        }
    });

    // Select from Default List
    $defaultArea.on("click", ".product-info-default-item", function () {
        const idx = Number($(this).data("idx"));
        const item = defaultResults[idx];
        if (item) {
            renderCard(item);
            $("html, body").animate({ scrollTop: $cardsArea.offset().top - 20 }, 500);
        }
    });

    // ===== Render Full Card =====
    function renderCard(item) {
        const $card = $('<div class="product-info-card" />');
        const url = item.url || "";
        const sep = `<div style="color:#ccc; margin: 5px 0;">----------------------------------------</div>`;

        const head = $(`
            <div class="product-info-meta">
                ${item.image ? `<img src="${escapeAttr(item.image)}" alt="">` : ""}
                <div style="flex:1">
                    <h3><a href="${escapeAttr(url)}" target="_blank">${escapeHtml(item.label)}</a></h3>
                    <div class="product-info-actions">
                        <button type="button" class="btn-copy-info btn--pink">${product_info_params.i18n.copyBtn}</button>
                        <button type="button" class="btn-copy-manual btn--blue">${product_info_params.i18n.copyManualBtn || "Copy HDSD"}</button>
                        <button type="button" class="btn-more btn--green">Tìm tiếp</button>
                        <button type="button" class="btn-remove btn--black">Đóng</button>
                        <span class="product-info-copied" style="display:none;">${product_info_params.i18n.copied}</span>
                    </div>
                </div>
            </div>
        `);

        let variantHTML = "";
        if (Array.isArray(item.variants) && item.variants.length) {
            variantHTML = `
                <div class="product-info-variants">
                    <table>
                        <thead><tr><th>Biến thể</th><th>Tồn</th><th>HSD</th></tr></thead>
                        <tbody>
                        ${item.variants
                            .map(
                                (v) => `
                            <tr>
                                <td>${escapeHtml(v.name)}</td>
                                <td style="text-align:center;">${escapeHtml(v.stock)}</td>
                                <td style="text-align:center;">${escapeHtml(v.expiry)}</td>
                            </tr>`
                            )
                            .join("")}
                        </tbody>
                    </table>
                </div>
            `;
        }

        let priceHTML = `<div class="pis-price-line">-</div>`;
        if (Array.isArray(item.price_lines) && item.price_lines.length) {
            priceHTML = item.price_lines
                .map((line) => {
                    const parts = String(line || "").split("\n").map(escapeHtml);
                    return `<div class="pis-price-line">${parts.join("<br>")}</div>`;
                })
                .join("");
        } else if (item.price) {
            // Back-compat: allow WooCommerce price HTML.
            priceHTML = `<div class="pis-price-line">${item.price}</div>`;
        }

        const info = $(`
            <div class="product-info-content" contenteditable="true">
                ${variantHTML}
                
                <div style="margin-top: 20px; margin-bottom: 5px;"><strong>${escapeHtml(item.label)}</strong></div>

                ${sep}
                <div class="product-info-section-title">GIÁ BÁN</div>
                <div class="pis-price-lines">${priceHTML}</div>
                ${sep}

                <div class="product-info-section-title">THÔNG TIN NỔI BẬT</div>
                <div class="product-info-desc">${item.short || "<em>Chưa có mô tả ngắn.</em>"}</div>
                ${sep}
                
                <div class="product-info-section-title">HƯỚNG DẪN SỬ DỤNG</div>
                <div class="product-info-manual-box">${item.manual || "<em>Chưa có HDSD nhập liệu.</em>"}</div>
                ${sep}

                <p style="margin-top:15px; font-size:13px; color:#666;">
                    Chi tiết sản phẩm (thành phần, dinh dưỡng, ...) & ưu đãi xem tại link: <a href="${escapeAttr(url)}" target="_blank">${escapeHtml(url)}</a>
                </p>
            </div>
        `);

        $card.append(head).append(info);
        $cardsArea.prepend($card);
    }

    // ===== Actions =====
    const $container = $(".shortcode-product-search-widget");

    // Helper: Hàm Copy dùng chung
    function copyTextToClipboard(text, $card) {
        const $copied = $card.find(".product-info-copied");
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard
                .writeText(text)
                .then(() => $copied.fadeIn(150).delay(1000).fadeOut(300))
                .catch(() => {});
        } else {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                document.execCommand("copy");
                $copied.fadeIn(150).delay(1000).fadeOut(300);
            } catch (err) {}
            document.body.removeChild(textArea);
        }
    }

    // 1. Logic Copy TOÀN BỘ (Copy Info)
    $container.on("click", ".btn-copy-info", function () {
        const $card = $(this).closest(".product-info-card");
        const $content = $card.find(".product-info-content").clone();

        // Xóa phần tồn kho
        $content.find(".product-info-variants").remove();

        // Tiêu đề
        $content.find(".product-info-section-title").each(function () {
            const title = $(this).text().toUpperCase();
            $(this).replaceWith(`\n${title}`);
        });

        // Xử lý dòng
        $content.find("br").replaceWith("\n");
        $content.find("p, div").after("\n");

        $content.find("li").each(function () {
            $(this).replaceWith("\n- " + $(this).text().trim());
        });

        let text = $content
            .text()
            .replace(/[ \t]+/g, " ")
            .replace(/[ \t]*\n[ \t]*/g, "\n")
            .replace(/\n+/g, "\n") // Gộp dòng trống
            .trim();

        copyTextToClipboard(text, $card);
    });

    // 2. Logic Copy RIÊNG HDSD (Mới)
    $container.on("click", ".btn-copy-manual", function () {
        const $card = $(this).closest(".product-info-card");
        // Chỉ lấy nội dung trong box HDSD
        const $manualBox = $card.find(".product-info-manual-box").clone();

        // Xử lý xuống dòng để text đẹp
        $manualBox.find("br").replaceWith("\n");
        $manualBox.find("p, div").after("\n");
        $manualBox.find("li").each(function () {
            $(this).replaceWith("\n- " + $(this).text().trim());
        });

        let text = $manualBox
            .text()
            .replace(/[ \t]+/g, " ")
            .replace(/[ \t]*\n[ \t]*/g, "\n")
            .replace(/\n+/g, "\n")
            .trim();

        copyTextToClipboard(text, $card);
    });

    $container.on("click", ".btn-remove", function () {
        $(this).closest(".product-info-card").remove();
    });

    $container.on("click", ".btn-more", function () {
        $input.val("").focus();
        $("html, body").animate({ scrollTop: $input.offset().top - 50 }, 300);
    });

    searchProducts(true);
});
