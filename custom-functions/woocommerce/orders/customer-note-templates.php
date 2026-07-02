<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('thean_customer_note_templates_option_name')) {
    function thean_customer_note_templates_option_name(): string
    {
        return 'thean_customer_note_templates';
    }
}

if (!function_exists('thean_customer_note_templates_user_can_manage')) {
    function thean_customer_note_templates_user_can_manage(): bool
    {
        return current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce');
    }
}

if (!function_exists('thean_customer_note_templates_defaults')) {
    function thean_customer_note_templates_defaults(): array
    {
        return [
            [
                'id' => 'pickup-ready',
                'title' => 'Khách tự đặt ship đến lấy hàng',
                'content' => 'Đơn hàng của anh/chị đã chuẩn bị xong. Anh/chị có thể đặt ship đến lấy hàng giúp shop nhé.',
                'updated_at' => 0,
            ],
            [
                'id' => 'payment-reminder',
                'title' => 'Nhắc thanh toán chuyển khoản',
                'content' => 'Shop chưa ghi nhận thanh toán cho đơn hàng này. Anh/chị kiểm tra và chuyển khoản giúp shop để đơn được xử lý tiếp nhé.',
                'updated_at' => 0,
            ],
            [
                'id' => 'delivery-delay',
                'title' => 'Báo giao hàng chậm',
                'content' => 'Đơn hàng của anh/chị đang cần thêm thời gian xử lý/giao hàng. Shop sẽ cập nhật sớm nhất khi có thông tin mới.',
                'updated_at' => 0,
            ],
        ];
    }
}

if (!function_exists('thean_customer_note_templates_normalize')) {
    function thean_customer_note_templates_normalize($templates): array
    {
        if (!is_array($templates)) {
            $templates = [];
        }

        $normalized = [];
        foreach ($templates as $template) {
            if (!is_array($template)) {
                continue;
            }

            $id = isset($template['id']) ? sanitize_key((string) $template['id']) : '';
            $title = isset($template['title']) ? sanitize_text_field((string) $template['title']) : '';
            $content = isset($template['content']) ? sanitize_textarea_field((string) $template['content']) : '';
            $updated_at = isset($template['updated_at']) ? absint($template['updated_at']) : 0;

            if ($title === '' || $content === '') {
                continue;
            }

            $normalized[] = [
                'id' => $id !== '' ? $id : sanitize_key(wp_generate_uuid4()),
                'title' => $title,
                'content' => $content,
                'updated_at' => $updated_at,
            ];
        }

        return array_values($normalized);
    }
}

if (!function_exists('thean_customer_note_templates_get')) {
    function thean_customer_note_templates_get(): array
    {
        $option_name = thean_customer_note_templates_option_name();
        $templates = get_option($option_name, null);

        if ($templates === null || $templates === false || !is_array($templates)) {
            $templates = thean_customer_note_templates_defaults();
            add_option($option_name, $templates, '', false);
        }

        return thean_customer_note_templates_normalize($templates);
    }
}

if (!function_exists('thean_customer_note_templates_save')) {
    function thean_customer_note_templates_save(array $templates): void
    {
        update_option(thean_customer_note_templates_option_name(), thean_customer_note_templates_normalize($templates), false);
    }
}

if (!function_exists('thean_customer_note_templates_is_order_admin_screen')) {
    function thean_customer_note_templates_is_order_admin_screen(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return false;
        }

        $screen_id = isset($screen->id) ? (string) $screen->id : '';
        $post_type = isset($screen->post_type) ? (string) $screen->post_type : '';

        return $post_type === 'shop_order'
            || $screen_id === 'shop_order'
            || $screen_id === 'woocommerce_page_wc-orders';
    }
}

if (!function_exists('thean_customer_note_templates_ajax_response')) {
    function thean_customer_note_templates_ajax_response(): void
    {
        if (!thean_customer_note_templates_user_can_manage()) {
            wp_send_json_error(['message' => 'Bạn không có quyền quản lý mẫu ghi chú.'], 403);
        }

        check_ajax_referer('thean_customer_note_templates', 'nonce');

        $mode = isset($_POST['mode']) ? sanitize_key((string) wp_unslash($_POST['mode'])) : '';
        $templates = thean_customer_note_templates_get();

        if ($mode === 'delete') {
            $delete_id = isset($_POST['id']) ? sanitize_key((string) wp_unslash($_POST['id'])) : '';
            $templates = array_values(array_filter($templates, static function (array $template) use ($delete_id): bool {
                return $template['id'] !== $delete_id;
            }));
            thean_customer_note_templates_save($templates);
            wp_send_json_success(['templates' => $templates]);
        }

        if ($mode === 'save') {
            $id = isset($_POST['id']) ? sanitize_key((string) wp_unslash($_POST['id'])) : '';
            $title = isset($_POST['title']) ? sanitize_text_field((string) wp_unslash($_POST['title'])) : '';
            $content = isset($_POST['content']) ? sanitize_textarea_field((string) wp_unslash($_POST['content'])) : '';

            if ($title === '' || $content === '') {
                wp_send_json_error(['message' => 'Vui lòng nhập tên mẫu và nội dung ghi chú.'], 400);
            }

            $entry = [
                'id' => $id !== '' ? $id : sanitize_key(wp_generate_uuid4()),
                'title' => $title,
                'content' => $content,
                'updated_at' => time(),
            ];

            $found = false;
            foreach ($templates as $index => $template) {
                if ($template['id'] === $entry['id']) {
                    $templates[$index] = $entry;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                array_unshift($templates, $entry);
            }

            thean_customer_note_templates_save($templates);
            wp_send_json_success(['templates' => $templates, 'selected' => $entry['id']]);
        }

        wp_send_json_error(['message' => 'Thao tác không hợp lệ.'], 400);
    }
}
add_action('wp_ajax_thean_customer_note_templates', 'thean_customer_note_templates_ajax_response');

if (!function_exists('thean_customer_note_templates_enqueue')) {
    function thean_customer_note_templates_enqueue(): void
    {
        if (!thean_customer_note_templates_user_can_manage() || !thean_customer_note_templates_is_order_admin_screen()) {
            return;
        }

        wp_register_style('thean-customer-note-templates', false, [], '1.0.0');
        wp_enqueue_style('thean-customer-note-templates');
        wp_add_inline_style('thean-customer-note-templates', '
            #thean-customer-note-templates{margin:0 0 12px;padding:12px;border:1px solid #dcdcde;border-radius:6px;background:#f6f7f7}
            #thean-customer-note-templates .thean-cnt-row{display:flex;gap:8px;align-items:center;margin-bottom:8px}
            #thean-customer-note-templates select,#thean-customer-note-templates input,#thean-customer-note-templates textarea{width:100%;max-width:100%}
            #thean-customer-note-templates textarea{min-height:82px;resize:vertical}
            #thean-customer-note-templates .thean-cnt-actions{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px;margin-top:8px}
            #thean-customer-note-templates .button{min-height:32px;text-align:center}
            #thean-customer-note-templates .thean-cnt-status{min-height:18px;margin-top:6px;color:#646970}
            #thean-customer-note-templates .thean-cnt-danger{color:#b32d2e}
            @media (max-width:782px){#thean-customer-note-templates .thean-cnt-row{display:block}#thean-customer-note-templates .thean-cnt-actions{grid-template-columns:1fr 1fr}}
        ');

        wp_register_script('thean-customer-note-templates', false, ['jquery'], '1.0.0', true);
        wp_enqueue_script('thean-customer-note-templates');
        wp_localize_script('thean-customer-note-templates', 'theanCustomerNoteTemplates', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('thean_customer_note_templates'),
            'templates' => thean_customer_note_templates_get(),
        ]);

        wp_add_inline_script('thean-customer-note-templates', <<<'JS'
(function($){
    'use strict';

    const state = {
        templates: Array.isArray(window.theanCustomerNoteTemplates.templates) ? window.theanCustomerNoteTemplates.templates : [],
        selected: ''
    };

    function findNoteBox() {
        const $textarea = $('#add_order_note');
        return $textarea.length ? $textarea : $('textarea[name="order_note"]').first();
    }

    function selectedTemplate() {
        return state.templates.find(function(template){ return template.id === state.selected; }) || null;
    }

    function setStatus($box, message, isError) {
        $box.find('.thean-cnt-status').text(message || '').toggleClass('thean-cnt-danger', !!isError);
    }

    function renderOptions($box) {
        const $select = $box.find('.thean-cnt-select');
        $select.empty().append($('<option>', {value: '', text: 'Chọn mẫu ghi chú...'}));
        state.templates.forEach(function(template){
            $select.append($('<option>', {value: template.id, text: template.title}));
        });
        $select.val(state.selected);
    }

    function fillEditor($box, template) {
        $box.find('.thean-cnt-id').val(template ? template.id : '');
        $box.find('.thean-cnt-title').val(template ? template.title : '');
        $box.find('.thean-cnt-content').val(template ? template.content : '');
    }

    function ajaxSave($box, payload) {
        setStatus($box, 'Đang lưu...', false);
        return $.post(window.theanCustomerNoteTemplates.ajaxUrl, Object.assign({
            action: 'thean_customer_note_templates',
            nonce: window.theanCustomerNoteTemplates.nonce
        }, payload)).done(function(response){
            if (!response || !response.success) {
                setStatus($box, (response && response.data && response.data.message) || 'Không lưu được mẫu.', true);
                return;
            }
            state.templates = response.data.templates || [];
            state.selected = response.data.selected || state.selected;
            renderOptions($box);
            fillEditor($box, selectedTemplate());
            setStatus($box, 'Đã lưu mẫu.', false);
        }).fail(function(xhr){
            const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'Không lưu được mẫu.';
            setStatus($box, message, true);
        });
    }

    function init() {
        const $textarea = findNoteBox();
        if (!$textarea.length || $('#thean-customer-note-templates').length) {
            return;
        }

        const $box = $([
            '<div id="thean-customer-note-templates">',
                '<div class="thean-cnt-row">',
                    '<select class="thean-cnt-select" aria-label="Chọn mẫu ghi chú"></select>',
                '</div>',
                '<input type="hidden" class="thean-cnt-id">',
                '<div class="thean-cnt-row"><input type="text" class="thean-cnt-title" placeholder="Tên mẫu ghi chú"></div>',
                '<div class="thean-cnt-row"><textarea class="thean-cnt-content" placeholder="Nội dung gửi cho khách"></textarea></div>',
                '<div class="thean-cnt-actions">',
                    '<button type="button" class="button button-primary thean-cnt-use">Chọn</button>',
                    '<button type="button" class="button thean-cnt-new">Tạo mới</button>',
                    '<button type="button" class="button thean-cnt-save">Lưu</button>',
                    '<button type="button" class="button thean-cnt-delete">Xóa</button>',
                '</div>',
                '<div class="thean-cnt-status" aria-live="polite"></div>',
            '</div>'
        ].join(''));

        $textarea.before($box);
        renderOptions($box);

        $box.on('change', '.thean-cnt-select', function(){
            state.selected = $(this).val();
            fillEditor($box, selectedTemplate());
            setStatus($box, state.selected ? 'Đã tải mẫu. Bấm Chọn để đưa vào ghi chú.' : '', false);
        });

        $box.on('click', '.thean-cnt-use', function(){
            const content = $box.find('.thean-cnt-content').val().trim();
            if (!content) {
                setStatus($box, 'Chưa có nội dung để chọn.', true);
                return;
            }
            $textarea.val(content).trigger('input').trigger('change').focus();
            $('#order_note_type').val('customer').trigger('change');
            setStatus($box, 'Đã đưa mẫu vào ghi chú gửi khách.', false);
        });

        $box.on('click', '.thean-cnt-new', function(){
            state.selected = '';
            renderOptions($box);
            fillEditor($box, null);
            $box.find('.thean-cnt-title').focus();
            setStatus($box, 'Nhập nội dung rồi bấm Lưu để tạo mẫu mới.', false);
        });

        $box.on('click', '.thean-cnt-save', function(){
            ajaxSave($box, {
                mode: 'save',
                id: $box.find('.thean-cnt-id').val(),
                title: $box.find('.thean-cnt-title').val(),
                content: $box.find('.thean-cnt-content').val()
            });
        });

        $box.on('click', '.thean-cnt-delete', function(){
            const id = $box.find('.thean-cnt-id').val();
            if (!id) {
                setStatus($box, 'Chưa chọn mẫu để xóa.', true);
                return;
            }
            if (!window.confirm('Xóa mẫu ghi chú này?')) {
                return;
            }
            ajaxSave($box, {mode: 'delete', id: id}).done(function(response){
                if (response && response.success) {
                    state.selected = '';
                    renderOptions($box);
                    fillEditor($box, null);
                    setStatus($box, 'Đã xóa mẫu.', false);
                }
            });
        });
    }

    $(init);
})(jQuery);
JS);
    }
}
add_action('admin_enqueue_scripts', 'thean_customer_note_templates_enqueue', 20);
