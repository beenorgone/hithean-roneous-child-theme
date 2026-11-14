(function ($) {
    'use strict';
    /*
     * "Ưu đãi đang áp dụng": gom bậc giá mua nhiều + thanh ưu đãi của Discount Rules (freeship…) và khối "Ưu đãi"
     * của plugin product-addon (chỉ khi mọi dòng là "tự động áp dụng") vào 1 nút → popup mỗi chương trình 1 thẻ,
     * cùng kiểu popup trang /uu-dai/. Nguồn gốc vẫn nằm trong DOM (ẩn bằng CSS) để plugin tiếp tục đồng bộ trạng
     * thái / input ẩn của form; popup đọc lại nguồn mỗi lần mở nên luôn khớp nội dung plugin đang hiển thị.
     * Thứ tự thẻ: theo nhóm config.offerOrder (marketing/offers/order.php — mặc định giảm giá → mua nhiều → quà tặng / add-on
     * → vòng quay → khác → vận chuyển), cùng nhóm giữ thứ tự gom.
     * Bấm thẻ không đổi gì: thẻ add-on có nút "Mua theo ưu đãi này" đặt thẳng số lượng; thẻ bậc giá mở popup nhỏ
     * chọn số lượng (bậc nhanh + ô − N +, hiện bậc đang áp dụng) rồi mới đặt.
     * Đặt số lượng xong thì thêm vào giỏ luôn (popup giỏ hàng của add-to-cart-popup.js).
     * Có vòng quay may mắn → thêm thẻ "Quay ngay"; nút sticky "Nhận ưu đãi" của vòng quay mở popup này.
     * Khối add-on có lựa chọn (khách phải chọn) giữ nguyên tại chỗ.
     */
    function setupOffers(summary, config) {
        var external = !!config.productUrl;
        var text = function (node) { return node ? node.textContent.replace(/\s+/g, ' ').trim() : ''; };
        var isAutoOffer = function (box) {
            var heading = box.querySelector(':scope > h4');
            var rows = box.querySelectorAll('[data-choice-row]');
            return /^ưu đãi/i.test(text(heading)) && rows.length > 0
                && Array.prototype.every.call(rows, function (row) { return row.getAttribute('data-mode') === 'auto'; });
        };
        summary.querySelectorAll('[data-product-addon-container]').forEach(function (box) {
            if (isAutoOffer(box)) box.setAttribute('data-hithean-offer-source', '');
        });

        if (config.offersAvailable === false) return null;

        var form = summary.querySelector('form.cart');
        var quantity = form && form.querySelector('input.qty[name="quantity"]');
        // Lý do không đặt được số lượng `minimum` (rỗng = đặt được) — cùng điều kiện với bậc giá ở trang.
        function blockedReason(minimum, row) {
            if (!quantity || quantity.type === 'hidden' || quantity.disabled || quantity.readOnly) return 'Không đổi được số lượng cho sản phẩm này';
            var variation = form.querySelector('[name="variation_id"]');
            if (variation && !Number(variation.value)) return 'Chọn phiên bản trước khi áp dụng';
            if (row && row.getAttribute('aria-disabled') === 'true') return 'Không áp dụng được với lựa chọn hiện tại';
            if ((quantity.max && minimum > Number(quantity.max)) || (quantity.min && minimum < Number(quantity.min))) return 'Không áp dụng được với lựa chọn hiện tại';
            return '';
        }
        function currentQuantity() { return quantity ? Number(quantity.value) || 0 : 0; }

        // "Mã này": tên sản phẩm + phiên bản đang chọn + SKU (WooCommerce cập nhật .sku theo phiên bản).
        function skuLabel() {
            var name = text(summary.querySelector('.product_title'));
            var picked = [];
            if (form) form.querySelectorAll('select[name^="attribute_"]').forEach(function (select) {
                if (select.value && select.selectedOptions[0]) picked.push(text(select.selectedOptions[0]));
            });
            var sku = text(summary.querySelector('.product_meta .sku'));
            return { name: name + (picked.length ? ' — ' + picked.join(', ') : ''), sku: sku && !/^(n\/a|không có)$/i.test(sku) ? sku : '' };
        }
        function tierFor(tiers, value) {
            return tiers.filter(function (tier) { return value >= tier.min && value <= tier.max; })[0] || null;
        }

        // Ảnh / icon góc phải thẻ: sản phẩm đang xem, ảnh add-on (theo tên dòng), icon cho vận chuyển & vòng quay.
        var media = config.offerMedia || {};
        function productMedia() { return media.product ? { src: media.product } : null; }
        function addonMedia(name) { return media.addons && media.addons[name] ? { src: media.addons[name] } : productMedia(); }

        // Hạn ưu đãi add-on: plugin product-addon ghi data-end (timestamp) lên dòng; giờ VN, cùng cách ghi trang /uu-dai/.
        function endLabel(row) {
            var end = Number(row.getAttribute('data-end'));
            if (!end) return '';
            try {
                var parts = {};
                new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Ho_Chi_Minh', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' })
                    .formatToParts(new Date(end * 1000)).forEach(function (p) { parts[p.type] = p.value; });
                var day = parts.day + '/' + parts.month + '/' + parts.year;
                return parts.hour + ':' + parts.minute + ':' + parts.second === '23:59:59' ? 'Đến hết ' + day : 'Đến ' + parts.hour + ':' + parts.minute + ' ' + day;
            } catch (e) { return ''; }
        }

        function collect() {
            var cards = [];
            // Add-on lên đầu.
            summary.querySelectorAll('[data-hithean-offer-source]').forEach(function (box) {
                var message = box.querySelector('.product_addon-sale-message');
                box.querySelectorAll('[data-choice-row]').forEach(function (row) {
                    var title = row.querySelector('.product_addon-title');
                    var name = title ? text(title.firstChild) || text(title) : '';
                    if (!name) return;
                    var minimum = Number(row.getAttribute('data-min-qty')) || 1;
                    var price = row.querySelector('.product_addon-price');
                    var notes = ['Tự động áp dụng khi mua từ ' + minimum + ' sản phẩm'];
                    var until = endLabel(row);
                    if (until) notes.push('⏳ ' + until);
                    cards.push({
                        tone: 'addon', media: addonMedia(name), badge: 'Ưu đãi kèm', title: name, notes: notes, price: price, extra: message,
                        active: row.classList.contains('is-active'), qty: minimum > 1 ? minimum : 0, blocked: minimum > 1 ? blockedReason(minimum, null) : ''
                    });
                });
            });
            // Bậc giá Discount Rules chỉ tính cho đúng mã sản phẩm này → gộp 1 thẻ.
            var tiers = [];
            summary.querySelectorAll('.awdr-bulk-customizable-table tr.bulk_table_row').forEach(function (row) {
                var range = text(row.querySelector('.wdr_bulk_range'));
                var match = range.match(/^([1-9]\d{0,3})(?:\s*(\+)|\s*[-–]\s*([1-9]\d{0,3}))?$/);
                if (!match) return;
                var minimum = Number(match[1]);
                var maximum = match[3] ? Number(match[3]) : (match[2] ? Infinity : minimum);
                if (maximum < minimum) return;
                tiers.push({
                    min: minimum, max: maximum, row: row,
                    label: text(row.querySelector('.wdr_bulk_title')) || ('Mua ' + range),
                    range: match[2] ? 'từ ' + minimum : (match[3] ? minimum + '–' + maximum : String(minimum)),
                    price: row.querySelector('.wdr_table_discounted_price') || row.querySelector('.wdr_bulk_table_discount')
                });
            });
            if (tiers.length) {
                tiers.sort(function (a, b) { return a.min - b.min; });
                var current = tierFor(tiers, currentQuantity());
                cards.push({
                    kind: 'tiers', media: productMedia(), badge: 'Mua nhiều giảm giá', title: 'Mua nhiều giảm giá', tiers: tiers, current: current,
                    sku: skuLabel(), notes: [], active: !!current, blocked: blockedReason(tiers[0].min, tiers[0].row)
                });
            }
            summary.querySelectorAll('.awdr_discount_bar').forEach(function (bar) {
                var label = text(bar);
                if (!label) return;
                var content = bar.querySelector('.awdr_discount_bar_content') || bar;
                var shipping = /free ?ship|miễn phí (vận chuyển|giao)|phí (ship|vận chuyển)/i.test(label);
                cards.push({
                    tone: shipping ? 'shipping' : 'discount', media: shipping ? { icon: 'ship' } : productMedia(),
                    badge: shipping ? 'Ưu đãi vận chuyển' : 'Ưu đãi',
                    html: content, label: label, notes: [], active: true
                });
            });
            // Vòng quay may mắn (lucky-wheel.php) đang bật trên trang này.
            var wheel = (config.sourceDocument || document).querySelector('#thean-lw-root .thean-lw-trigger');
            if (wheel) {
                var state = text(wheel.querySelector('.thean-lw-trigger__text'));
                cards.push({
                    kind: 'wheel', media: { icon: 'wheel' }, badge: 'Vòng quay may mắn', title: 'Quay vòng quay may mắn — nhận mã giảm giá / quà tặng',
                    notes: state && !/^nhận ưu đãi$/i.test(state) ? [state] : ['Mỗi lượt quay có thể trúng mã giảm giá hoặc quà tặng'], active: false
                });
            }
            return sortCards(cards);
        }
        // Nhóm của thẻ → vị trí trong config.offerOrder; sort ổn định nên cùng nhóm giữ thứ tự gom.
        function sortCards(cards) {
            var order = Array.isArray(config.offerOrder) ? config.offerOrder : [];
            var alias = { addon: 'gift', tiers: 'tiers', discount: 'discount', shipping: 'shipping', wheel: 'wheel' };
            var rank = function (card) {
                var index = order.indexOf(alias[card.tone || card.kind] || 'other');
                return index < 0 ? 99 : index;
            };
            return order.length ? cards.slice().sort(function (a, b) { return rank(a) - rank(b); }) : cards;
        }

        var initial = collect();
        if (!initial.length) return null;
        document.body.classList.add('hithean-purchase-offers');
        if (summary.querySelector('.awdr-bulk-customizable-table tr.bulk_table_row')) document.body.classList.add('hithean-purchase-offers-tiers');

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'hithean-offers-trigger';
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.innerHTML = '<span class="hithean-offers-trigger__icon" aria-hidden="true">✳</span>'
            + '<span class="hithean-offers-trigger__text"><strong>Ưu đãi đang áp dụng</strong><small data-offers-summary></small></span>'
            + '<span class="hithean-offers-trigger__count" data-offers-count></span>'
            + '<span class="hithean-offers-trigger__more" aria-hidden="true">Xem ›</span>';
        var anchor = summary.querySelector('.awdr-bulk-customizable-table, [data-hithean-offer-source], .awdr_discount_bar');
        if (anchor && anchor.parentNode === summary) summary.insertBefore(trigger, anchor);
        else summary.appendChild(trigger);

        function makeDialog(className, titleId, title, body) {
            var node = document.createElement('dialog');
            node.className = 'hithean-offers-dialog ' + className;
            node.setAttribute('aria-labelledby', titleId);
            node.innerHTML = '<div class="hithean-offers-dialog__head"><div><h3 id="' + titleId + '">' + title + '</h3><p data-offers-meta></p></div>'
                + '<button type="button" class="hithean-offers-dialog__close" data-offers-close aria-label="Đóng">×</button></div>' + body;
            document.body.appendChild(node);
            // Bấm nền mờ ngoài popup = đóng.
            node.addEventListener('click', function (event) {
                if (event.target.closest('[data-offers-close]')) { node.close(); return; }
                if (event.target !== node) return;
                var rect = node.getBoundingClientRect();
                if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) node.close();
            });
            return node;
        }
        function openDialog(node) {
            if (typeof node.showModal === 'function') node.showModal();
            else node.setAttribute('open', '');
            document.documentElement.classList.add('hithean-offers-open');
        }

        var dialog = makeDialog('', external ? 'hlo-product-offers-title' : 'hithean-offers-dialog-title', 'Ưu đãi đang áp dụng',
            '<ul class="hithean-offers-dialog__list" data-offers-list></ul>'
            + '<div class="hithean-offers-dialog__foot"><p>Mỗi chương trình có điều kiện riêng. Giá trị cuối cùng được xác nhận trong giỏ hàng.</p></div>');
        if (config.offersUrl) {
            var all = document.createElement('a');
            all.className = 'hithean-offers-dialog__all';
            all.href = config.offersUrl;
            all.textContent = 'Xem mọi ưu đãi trên website ↗';
            dialog.querySelector('.hithean-offers-dialog__foot').appendChild(all);
        }
        var list = dialog.querySelector('[data-offers-list]');

        // Popup nhỏ chọn số lượng cho thẻ bậc giá.
        var qtyDialog = makeDialog('hithean-offers-qty', external ? 'hlo-product-qty-title' : 'hithean-offers-qty-title', 'Chọn số lượng mua',
            '<div class="hithean-offers-qty__body"><div class="hithean-offers-qty__picks" data-qty-picks role="group" aria-label="Bậc ưu đãi"></div>'
            + '<div class="hithean-offers-qty__stepper"><button type="button" data-qty-step="-1" aria-label="Giảm">−</button>'
            + '<input type="number" inputmode="numeric" min="1" step="1" data-qty-input aria-label="Số lượng">'
            + '<button type="button" data-qty-step="1" aria-label="Tăng">+</button></div>'
            + '<p class="hithean-offers-qty__status" data-qty-status aria-live="polite"></p></div>'
            + '<div class="hithean-offers-dialog__foot"><button type="button" class="hithean-offer-card__action" data-qty-apply></button></div>');
        var qtyInput = qtyDialog.querySelector('[data-qty-input]');
        var qtyTiers = [];

        function el(tag, className, content) {
            var node = document.createElement(tag);
            if (className) node.className = className;
            if (content) node.textContent = content;
            return node;
        }
        function cloneInto(target, source) {
            // Nội dung đã hiển thị trên trang (do plugin render) — sao chép nguyên node, không qua chuỗi.
            Array.prototype.forEach.call(source.childNodes, function (node) { target.appendChild(node.cloneNode(true)); });
            return target;
        }
        function priceEl(source, suffix) {
            var price = cloneInto(el('span', 'hithean-offer-card__price'), source);
            if (suffix) price.appendChild(el('small', '', ' ' + suffix));
            return price;
        }
        var ICONS = {
            ship: '<path d="M3 6h11v9H3z"/><path d="M14 9h4l3 3v3h-7"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
            // Vòng quay 6 ô (3 ô tô màu xen kẽ) + kim chỉ phía trên.
            wheel: '<g fill="currentColor" fill-opacity=".28" stroke="none"><path d="M12 13V5a8 8 0 0 1 6.93 4z"/><path d="M12 13l6.93 4A8 8 0 0 1 12 21z"/><path d="M12 13v8a8 8 0 0 1-6.93-4z"/></g>'
                + '<circle cx="12" cy="13" r="8"/><path d="M12 5v16M5.07 9l13.86 8M18.93 9 5.07 17"/>'
                + '<circle cx="12" cy="13" r="1.8" fill="currentColor"/><path d="M10.3 1.6h3.4L12 4.6z" fill="currentColor"/>'
        };
        function mediaEl(item) {
            if (!item || (!item.src && !ICONS[item.icon])) return null;
            var box = el('span', 'hithean-offer-card__media' + (item.icon ? ' hithean-offer-card__media--icon is-' + item.icon : ''));
            box.setAttribute('aria-hidden', 'true');
            if (item.icon) {
                box.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' + ICONS[item.icon] + '</svg>';
            } else {
                var img = el('img');
                img.src = item.src; img.alt = ''; img.loading = 'lazy'; img.decoding = 'async';
                img.onerror = function () { box.remove(); };
                box.appendChild(img);
            }
            return box;
        }
        // Ảnh cao bằng dòng chip + 1 dòng tiêu đề (không theo tiêu đề nhiều dòng): đo rồi gán --ho-media.
        function mediaSize(main) {
            var top = main.querySelector('.hithean-offer-card__top');
            var title = main.querySelector('.hithean-offer-card__title');
            if (!top || !title) return 0;
            var style = getComputedStyle(title);
            var line = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.5;
            return top.offsetHeight + (parseFloat(getComputedStyle(main).rowGap) || 0) + line;
        }
        var mediaObserver = window.ResizeObserver ? new ResizeObserver(function (entries) {
            entries.forEach(function (entry) {
                var size = mediaSize(entry.target);
                if (size) entry.target.parentNode.style.setProperty('--ho-media', Math.round(size) + 'px');
            });
        }) : null;
        function cardLabel(card) {
            if (card.kind === 'tiers') return card.tiers.map(function (tier) { return tier.label; }).join(' / ');
            return card.title || card.label;
        }
        function renderTrigger(cards) {
            trigger.hidden = !cards.length;
            trigger.querySelector('[data-offers-summary]').textContent = cards.map(cardLabel).join(' · ');
            trigger.querySelector('[data-offers-count]').textContent = String(cards.length);
            trigger.setAttribute('aria-label', 'Ưu đãi đang áp dụng: ' + cards.length + ' chương trình. Xem chi tiết');
        }
        function actionButton(card, label, data) {
            var action = el('button', 'hithean-offer-card__action');
            action.type = 'button';
            if (card.blocked) { action.disabled = true; action.textContent = card.blocked; }
            else { action.textContent = label; Object.keys(data).forEach(function (key) { action.dataset[key] = data[key]; }); }
            return action;
        }
        function renderDialog(cards) {
            dialog.querySelector('[data-offers-meta]').textContent = cards.length + ' chương trình cho sản phẩm này';
            if (mediaObserver) mediaObserver.disconnect();
            list.textContent = '';
            cards.forEach(function (card) {
                // Màu theo loại ưu đãi — cùng lớp .is-tone-* với thẻ PHP (offer-card.php).
                var item = el('li', 'hithean-offer-card is-tone-' + (card.tone || card.kind || 'other') + (card.active ? ' is-active' : ''));
                var head = el('div', 'hithean-offer-card__head');
                var main = el('div', 'hithean-offer-card__main');
                var top = el('div', 'hithean-offer-card__top');
                top.appendChild(el('span', 'hithean-offer-card__badge', card.badge));
                if (card.price) top.appendChild(priceEl(card.price, ''));
                main.appendChild(top);
                var body = el('div', 'hithean-offer-card__title');
                if (card.html) cloneInto(body, card.html);
                else body.textContent = card.title;
                main.appendChild(body);
                head.appendChild(main);
                var thumb = mediaEl(card.media);
                if (thumb) {
                    head.classList.add('has-media');
                    head.appendChild(thumb);
                    if (mediaObserver) mediaObserver.observe(main);
                }
                item.appendChild(head);
                if (card.kind === 'tiers') {
                    var tierList = el('ul', 'hithean-offer-tiers');
                    card.tiers.forEach(function (tier) {
                        var li = el('li', tier === card.current ? 'is-current' : '');
                        var label = el('span', 'hithean-offer-tiers__label', (tier === card.current ? '✓ ' : '') + tier.label);
                        label.appendChild(el('small', '', 'Mua ' + tier.range + ' sản phẩm'));
                        li.appendChild(label);
                        if (tier.price) li.appendChild(priceEl(tier.price, '/ sp'));
                        tierList.appendChild(li);
                    });
                    item.appendChild(tierList);
                    item.appendChild(actionButton(card, 'Mua theo ưu đãi này', { offersTiers: '1' }));
                }
                if (card.extra && text(card.extra)) item.appendChild(cloneInto(el('div', 'hithean-offer-card__extra'), card.extra));
                card.notes.forEach(function (note) { item.appendChild(el('p', 'hithean-offer-card__note', note)); });
                if (card.kind === 'wheel') item.appendChild(actionButton(card, 'Quay ngay', { offersWheel: '1' }));
                if (card.qty) {
                    if (card.active) {
                        var done = el('button', 'hithean-offer-card__action', '✓ Đang áp dụng với ' + currentQuantity() + ' sản phẩm');
                        done.type = 'button'; done.disabled = true;
                        item.appendChild(done);
                    } else {
                        item.appendChild(actionButton(card, 'Mua theo ưu đãi này · ' + card.qty + ' sản phẩm', { offersQty: String(card.qty) }));
                    }
                }
                list.appendChild(item);
            });
        }

        function limits() {
            var minimum = Math.max(1, Number(quantity && quantity.min) || 1);
            var maximum = quantity && quantity.max ? Number(quantity.max) : 9999;
            return { min: minimum, max: maximum };
        }
        function renderQty() {
            var bounds = limits();
            var value = Math.min(bounds.max, Math.max(bounds.min, Math.floor(Number(qtyInput.value) || bounds.min)));
            var tier = tierFor(qtyTiers, value);
            qtyDialog.querySelectorAll('[data-qty-pick]').forEach(function (pick) {
                pick.setAttribute('aria-pressed', String(tier && Number(pick.dataset.qtyPick) === tier.min));
            });
            var status = qtyDialog.querySelector('[data-qty-status]');
            status.textContent = '';
            if (tier) {
                status.appendChild(document.createTextNode('Áp dụng: ' + tier.label));
                if (tier.price) { status.appendChild(document.createTextNode(' · ')); status.appendChild(priceEl(tier.price, '/ sp')); }
            } else {
                status.textContent = 'Chưa đạt bậc ưu đãi — mua từ ' + qtyTiers[0].min + ' sản phẩm để được giảm.';
            }
            qtyDialog.querySelector('[data-qty-step="-1"]').disabled = value <= bounds.min;
            qtyDialog.querySelector('[data-qty-step="1"]').disabled = value >= bounds.max;
            qtyDialog.querySelector('[data-qty-apply]').textContent = external ? 'Tiếp tục mua ' + value + ' sản phẩm' : 'Thêm ' + value + ' sản phẩm vào giỏ';
            return value;
        }
        function openQty(card) {
            qtyTiers = card.tiers;
            var bounds = limits();
            qtyInput.min = String(bounds.min);
            qtyInput.max = String(bounds.max);
            var meta = card.sku.name + (card.sku.sku ? ' · SKU ' + card.sku.sku : '');
            qtyDialog.querySelector('[data-offers-meta]').textContent = meta;
            var picks = qtyDialog.querySelector('[data-qty-picks]');
            picks.textContent = '';
            qtyTiers.forEach(function (tier) {
                if (tier.min > bounds.max) return;
                var pick = el('button', 'hithean-offers-qty__pick');
                pick.type = 'button';
                pick.dataset.qtyPick = String(tier.min);
                pick.appendChild(el('strong', '', tier.label));
                if (tier.price) pick.appendChild(priceEl(tier.price, '/ sp'));
                picks.appendChild(pick);
            });
            // Mặc định: bậc kế tiếp trên số lượng đang chọn, hết bậc thì giữ số lượng hiện tại.
            var next = qtyTiers.filter(function (tier) { return tier.min > currentQuantity(); })[0];
            qtyInput.value = String(next ? next.min : Math.max(currentQuantity(), qtyTiers[0].min));
            renderQty();
            openDialog(qtyDialog);
        }
        function applyQuantity(value) {
            if (external) {
                var target = new URL(config.productUrl);
                target.searchParams.set('quantity', String(value));
                target.hash = 'uu-dai';
                window.location.assign(target.href);
                return;
            }
            if (!quantity) return;
            // Sự kiện gốc: jQuery (bảng giá, tạm tính, thanh sticky) lẫn plugin add-on đều nhận.
            quantity.value = String(value);
            quantity.dispatchEvent(new Event('input', { bubbles: true }));
            quantity.dispatchEvent(new Event('change', { bubbles: true }));
            if (qtyDialog.open) qtyDialog.close();
            if (dialog.open) dialog.close();
            refresh();
            // Thêm vào giỏ ngay như nút "Thêm vào giỏ": submit gốc → add-to-cart-popup.js bắt submit, thêm bằng AJAX
            // và hiện popup giỏ hàng (không có module popup thì form POST như thường). Chờ 1 nhịp để plugin
            // add-on / Discount Rules xử lý xong sự kiện đổi số lượng.
            window.setTimeout(function () {
                var button = form.querySelector('.single_add_to_cart_button');
                if (!button || button.disabled || button.classList.contains('disabled')) {
                    if (button) button.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }
                if (!form.reportValidity()) return;
                if (typeof form.requestSubmit === 'function') form.requestSubmit(button);
                else button.click();
            }, 0);
        }

        var lastCards = initial;
        var opener = config.opener || trigger;
        function refresh() { lastCards = collect(); renderTrigger(lastCards); }
        renderTrigger(initial);

        function showOffers() {
            lastCards = collect();
            renderDialog(lastCards);
            if (!dialog.open) openDialog(dialog);
        }
        trigger.addEventListener('click', function () { opener = trigger; showOffers(); });
        // Từ trang /uu-dai/ (chế độ sản phẩm) sang: link có #uu-dai → mở sẵn popup ưu đãi (cùng kiểu thẻ với trang đó).
        if (!external && window.location.hash === '#uu-dai') {
            window.setTimeout(function () {
                showOffers();
                try { window.history.replaceState(null, '', window.location.pathname + window.location.search); } catch (e) {}
            }, 400);
        }
        // Nút sticky "Nhận ưu đãi" của vòng quay mở popup ưu đãi chung; thẻ vòng quay trong popup mới mở vòng quay
        // (bấm hộ nút gốc với cờ bỏ qua). Tự mở vòng quay của plugin gọi thẳng openModal nên không bị chặn.
        var wheelPassThrough = false;
        if (!external) document.addEventListener('click', function (event) {
            if (wheelPassThrough || !event.target.closest || !event.target.closest('#thean-lw-root .thean-lw-trigger')) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            opener = event.target.closest('.thean-lw-trigger');
            showOffers();
        }, true);
        function openWheel() {
            var wheelTrigger = document.querySelector('#thean-lw-root .thean-lw-trigger');
            if (qtyDialog.open) qtyDialog.close();
            if (dialog.open) dialog.close();
            if (!wheelTrigger) { if (external) window.location.assign(config.productUrl + '#uu-dai'); return; }
            wheelPassThrough = true;
            try { wheelTrigger.click(); } finally { wheelPassThrough = false; }
        }
        function afterClose(other) {
            if (other.open) return;
            document.documentElement.classList.remove('hithean-offers-open');
            if (opener && document.contains(opener)) opener.focus({ preventScroll: true });
        }
        dialog.addEventListener('close', function () { afterClose(qtyDialog); });
        qtyDialog.addEventListener('close', function () { afterClose(dialog); });
        list.addEventListener('click', function (event) {
            var direct = event.target.closest('[data-offers-qty]');
            if (direct) { applyQuantity(Number(direct.dataset.offersQty)); return; }
            if (event.target.closest('[data-offers-wheel]')) { openWheel(); return; }
            if (event.target.closest('[data-offers-tiers]')) {
                var card = lastCards.filter(function (item) { return item.kind === 'tiers'; })[0];
                if (card) openQty(card);
            }
        });
        qtyDialog.addEventListener('click', function (event) {
            var pick = event.target.closest('[data-qty-pick]');
            var step = event.target.closest('[data-qty-step]');
            if (pick) { qtyInput.value = pick.dataset.qtyPick; renderQty(); }
            else if (step) { qtyInput.value = String((Number(qtyInput.value) || 0) + Number(step.dataset.qtyStep)); renderQty(); }
            else if (event.target.closest('[data-qty-apply]')) applyQuantity(renderQty());
        });
        qtyInput.addEventListener('input', renderQty);
        qtyInput.addEventListener('change', function () { qtyInput.value = String(renderQty()); });
        qtyInput.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); applyQuantity(renderQty()); } });
        // Discount Rules / addon cập nhật thanh ưu đãi theo phân loại & số lượng.
        if (!external) $(document).on('wdr_dynamic_price_update.hitheanOffers', refresh);
        if (!external) $(summary).on('found_variation.hitheanOffers reset_data.hitheanOffers change.hitheanOffers input.hitheanOffers', function () { window.setTimeout(refresh, 0); });
        return {
            open: showOffers,
            destroy: function () {
                if (mediaObserver) mediaObserver.disconnect();
                if (qtyDialog.open) qtyDialog.close();
                if (dialog.open) dialog.close();
                dialog.remove(); qtyDialog.remove(); trigger.remove();
            }
        };
    }

    window.hitheanSetupProductOffers = setupOffers;
})(jQuery);
