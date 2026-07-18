<?php
defined('ABSPATH') || exit;

/**
 * AI provider abstraction dùng chung cho các feature trong theme.
 * Hỗ trợ Gemini, OpenAI (Chat Completions / Responses), Claude (Anthropic).
 *
 * Cách dùng cho mỗi feature (quyết định gọi AI + cấp quyền tại thời điểm gọi):
 *   1. Feature tự khai báo provider/model riêng qua constant hoặc filter,
 *      VD: ORDER_CREATOR_AI_PROVIDER / ORDER_CREATOR_AI_MODEL.
 *   2. Feature gọi theme_ai_call_provider(...) hoặc
 *      theme_ai_call_provider_with_documents(...) và truyền model override.
 *   3. API key đặt trong wp-config.php: CLAUDE_API_KEY / GEMINI_API_KEY /
 *      OPENAI_API_KEY (hoặc biến môi trường cùng tên).
 *      Riêng Gemini có 2 bậc: GEMINI_API_KEY (free — tính năng đơn giản)
 *      và GEMINI_API_KEY_BILLING (đã bật billing — tính năng trả phí,
 *      provider 'gemini_billing', mặc định gemini-2.5-flash).
 *
 * File này chỉ được require khi feature cần AI thật sự chạy — không load global.
 *
 * Messages format: [{role: 'user'|'assistant', content: string}]
 */

function theme_ai_get_api_key(string $provider): string
{
    $provider = sanitize_key($provider);

    if ($provider === 'openai') {
        if (defined('OPENAI_API_KEY') && OPENAI_API_KEY) {
            return (string) OPENAI_API_KEY;
        }
        return (string) getenv('OPENAI_API_KEY');
    }

    if ($provider === 'gemini') {
        if (defined('GEMINI_API_KEY') && GEMINI_API_KEY) {
            return (string) GEMINI_API_KEY;
        }
        return (string) getenv('GEMINI_API_KEY');
    }

    // Gemini với key ĐÃ BẬT BILLING — dành cho tính năng xác định trả phí
    // (data không bị dùng để train, không giới hạn quota free).
    if ($provider === 'gemini_billing') {
        if (defined('GEMINI_API_KEY_BILLING') && GEMINI_API_KEY_BILLING) {
            return (string) GEMINI_API_KEY_BILLING;
        }
        $env = (string) getenv('GEMINI_API_KEY_BILLING');
        if ($env !== '') {
            return $env;
        }
        return theme_ai_get_api_key('gemini'); // chưa có key billing → tạm dùng key free
    }

    if ($provider === 'claude') {
        if (defined('CLAUDE_API_KEY') && CLAUDE_API_KEY) {
            return (string) CLAUDE_API_KEY;
        }
        return (string) getenv('CLAUDE_API_KEY');
    }

    return '';
}

/**
 * Model mặc định theo provider. Feature nên truyền $model riêng khi gọi;
 * đây chỉ là fallback khi feature không chỉ định.
 */
function theme_ai_get_model(string $provider): string
{
    $provider = sanitize_key($provider);

    if ($provider === 'gemini') {
        if (defined('GEMINI_CHAT_MODEL') && GEMINI_CHAT_MODEL) {
            return (string) GEMINI_CHAT_MODEL;
        }
        // 2.5-flash đã bị Google khóa với API key mới (07/2026).
        return 'gemini-3.1-flash-lite';
    }

    if ($provider === 'gemini_billing') {
        if (defined('GEMINI_BILLING_CHAT_MODEL') && GEMINI_BILLING_CHAT_MODEL) {
            return (string) GEMINI_BILLING_CHAT_MODEL;
        }
        return 'gemini-2.5-flash'; // key billing/tài khoản cũ vẫn dùng được — rẻ, ổn định
    }

    if ($provider === 'openai') {
        if (defined('OPENAI_CHAT_MODEL') && OPENAI_CHAT_MODEL) {
            return (string) OPENAI_CHAT_MODEL;
        }
        return 'gpt-4o-mini';
    }

    if ($provider === 'claude') {
        if (defined('CLAUDE_CHAT_MODEL') && CLAUDE_CHAT_MODEL) {
            return (string) CLAUDE_CHAT_MODEL;
        }
        return 'claude-opus-4-8';
    }

    return '';
}

/**
 * 'auto' → chọn provider đầu tiên đang có API key (ưu tiên claude → gemini → openai).
 */
function theme_ai_resolve_provider(string $requested): string
{
    $requested = sanitize_key($requested ?: 'auto');
    if (in_array($requested, ['openai', 'gemini', 'gemini_billing', 'claude'], true)) {
        return $requested;
    }

    foreach (['claude', 'gemini', 'openai'] as $p) {
        if (theme_ai_get_api_key($p) !== '') {
            return $p;
        }
    }

    return 'claude';
}

/**
 * Entry point chat text. Trả về text trả lời hoặc WP_Error.
 *
 * @param string $provider  gemini | openai | claude | auto
 * @param string $system    System instruction
 * @param array  $messages  [{role, content}, ...]
 * @param int    $max_tokens
 * @param string $model     Model override cho feature; '' = mặc định của provider
 * @return string|WP_Error
 */
function theme_ai_call_provider(string $provider, string $system, array $messages, int $max_tokens = 2000, string $model = '')
{
    $provider = theme_ai_resolve_provider($provider);
    $api_key  = theme_ai_get_api_key($provider);

    if ($api_key === '') {
        $key_hint = $provider === 'gemini_billing' ? 'GEMINI_API_KEY_BILLING' : strtoupper($provider) . '_API_KEY';
        return new WP_Error(
            'theme_ai_missing_key',
            sprintf('Chưa có API key cho %s. Thêm %s vào wp-config.php.', strtoupper($provider), $key_hint),
            ['provider' => $provider]
        );
    }

    if ($model === '') {
        $model = theme_ai_get_model($provider);
    }

    if ($provider === 'gemini' || $provider === 'gemini_billing') {
        return theme_ai_call_gemini($api_key, $model, $system, $messages, $max_tokens);
    }

    if ($provider === 'openai') {
        return theme_ai_call_openai($api_key, $model, $system, $messages, $max_tokens);
    }

    return theme_ai_call_claude($api_key, $model, $system, $messages, $max_tokens);
}

/**
 * thinkingConfig tương thích theo đời model Gemini:
 * 2.x nhận thinkingBudget (0 = tắt hẳn); 3.x nhận thinkingLevel
 * (không tắt hẳn được — dùng mức thấp nhất; bản pro không có 'minimal').
 */
function theme_ai_gemini_thinking_config(string $model): array
{
    if (strpos($model, 'gemini-2.') === 0) {
        return ['thinkingBudget' => 0];
    }

    return ['thinkingLevel' => strpos($model, 'pro') !== false ? 'low' : 'minimal'];
}

/**
 * @return string|WP_Error
 */
function theme_ai_call_gemini(string $api_key, string $model, string $system, array $messages, int $max_tokens = 2000)
{
    $contents = [];
    foreach ($messages as $msg) {
        $role = $msg['role'] === 'assistant' ? 'model' : 'user';
        $contents[] = [
            'role'  => $role,
            'parts' => [['text' => (string) $msg['content']]],
        ];
    }

    $payload = [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents'           => $contents,
        'generationConfig'   => [
            'temperature'     => 0,
            'maxOutputTokens' => $max_tokens,
            'thinkingConfig'  => theme_ai_gemini_thinking_config($model),
        ],
    ];

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $response = wp_remote_post($endpoint, [
        'timeout' => 60,
        'headers' => [
            'x-goog-api-key' => $api_key,
            'Content-Type'   => 'application/json',
        ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300) {
        $msg = is_array($data) && !empty($data['error']['message']) ? (string) $data['error']['message'] : 'Gemini request failed.';
        return new WP_Error('theme_ai_gemini_error', $msg, ['status' => $code, 'provider' => 'gemini']);
    }

    $parts = (array) ($data['candidates'][0]['content']['parts'] ?? []);
    $text  = '';
    foreach ($parts as $part) {
        if (!empty($part['thought'])) {
            continue;
        }
        if (isset($part['text']) && is_string($part['text'])) {
            $text .= $part['text'];
        }
    }

    $text = trim($text);
    if ($text === '') {
        return new WP_Error('theme_ai_gemini_empty', 'Gemini trả về nội dung rỗng.', ['provider' => 'gemini']);
    }

    return $text;
}

/**
 * @return string|WP_Error
 */
function theme_ai_call_openai(string $api_key, string $model, string $system, array $messages, int $max_tokens = 2000)
{
    $oai_messages = [['role' => 'system', 'content' => $system]];
    foreach ($messages as $msg) {
        $oai_messages[] = [
            'role'    => in_array($msg['role'], ['user', 'assistant'], true) ? $msg['role'] : 'user',
            'content' => (string) $msg['content'],
        ];
    }

    $payload = [
        'model'      => $model,
        'messages'   => $oai_messages,
        'max_tokens' => $max_tokens,
    ];

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'timeout' => 60,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300) {
        $msg = is_array($data) && !empty($data['error']['message']) ? (string) $data['error']['message'] : 'OpenAI request failed.';
        return new WP_Error('theme_ai_openai_error', $msg, ['status' => $code, 'provider' => 'openai']);
    }

    $text = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
    if ($text === '') {
        return new WP_Error('theme_ai_openai_empty', 'OpenAI trả về nội dung rỗng.', ['provider' => 'openai']);
    }

    return $text;
}

/**
 * @return string|WP_Error
 */
function theme_ai_call_claude(string $api_key, string $model, string $system, array $messages, int $max_tokens = 2000)
{
    $claude_messages = [];
    foreach ($messages as $msg) {
        $claude_messages[] = [
            'role'    => in_array($msg['role'], ['user', 'assistant'], true) ? $msg['role'] : 'user',
            'content' => (string) $msg['content'],
        ];
    }

    $payload = [
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'system'     => $system,
        'messages'   => $claude_messages,
    ];

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 60,
        'headers' => [
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300) {
        $msg = is_array($data) && !empty($data['error']['message']) ? (string) $data['error']['message'] : 'Claude request failed.';
        return new WP_Error('theme_ai_claude_error', $msg, ['status' => $code, 'provider' => 'claude']);
    }

    if (($data['stop_reason'] ?? '') === 'refusal') {
        return new WP_Error('theme_ai_claude_refusal', 'Claude từ chối xử lý nội dung này.', ['provider' => 'claude']);
    }

    $text = '';
    foreach ((array) ($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
            $text .= (string) $block['text'];
        }
    }

    $text = trim($text);
    if ($text === '') {
        return new WP_Error('theme_ai_claude_empty', 'Claude trả về nội dung rỗng.', ['provider' => 'claude']);
    }

    return $text;
}

/**
 * Entry point cho extraction từ tập tin (ảnh / PDF): tự chọn provider như
 * theme_ai_call_provider nhưng gửi kèm documents.
 *
 * @param string $provider  gemini | openai | claude | auto
 * @param array<int,array{path:string,mime_type?:string,title?:string}> $documents
 * @param string $model     Model override cho feature; '' = mặc định provider
 * @return string|WP_Error
 */
function theme_ai_call_provider_with_documents(string $provider, string $system, string $prompt, array $documents, int $max_tokens = 2000, int $timeout = 120, string $model = '')
{
    $provider = theme_ai_resolve_provider($provider);
    $api_key  = theme_ai_get_api_key($provider);

    if ($api_key === '') {
        $key_hint = $provider === 'gemini_billing' ? 'GEMINI_API_KEY_BILLING' : strtoupper($provider) . '_API_KEY';
        return new WP_Error(
            'theme_ai_missing_key',
            sprintf('Chưa có API key cho %s. Thêm %s vào wp-config.php.', strtoupper($provider), $key_hint),
            ['provider' => $provider]
        );
    }

    if ($model === '') {
        $model = theme_ai_get_model($provider);
    }

    if ($provider === 'gemini' || $provider === 'gemini_billing') {
        return theme_ai_call_gemini_with_documents($api_key, $model, $system, $prompt, $documents, $max_tokens, $timeout);
    }

    if ($provider === 'openai') {
        return theme_ai_call_openai_with_documents($api_key, $model, $system, $prompt, $documents, $max_tokens, $timeout);
    }

    return theme_ai_call_claude_with_documents($api_key, $model, $system, $prompt, $documents, $max_tokens, $timeout);
}

/**
 * @param array<int,array{path:string,mime_type?:string,title?:string}> $documents
 * @return array{0:string,1:string}|WP_Error [$base64, $mime]
 */
function theme_ai_read_document(array $document, string $provider)
{
    $path = isset($document['path']) ? (string) $document['path'] : '';
    if ($path === '' || !is_readable($path) || !is_file($path)) {
        return new WP_Error('theme_ai_document_unreadable', 'Không đọc được tập tin gửi cho AI.', ['provider' => $provider]);
    }

    $bytes = file_get_contents($path);
    if (!is_string($bytes) || $bytes === '') {
        return new WP_Error('theme_ai_document_empty', 'Tập tin gửi cho AI đang rỗng.', ['provider' => $provider]);
    }

    return [base64_encode($bytes), (string) ($document['mime_type'] ?? 'application/pdf')];
}

/**
 * @param array<int,array{path:string,mime_type?:string,title?:string}> $documents
 * @return string|WP_Error
 */
function theme_ai_call_claude_with_documents(string $api_key, string $model, string $system, string $prompt, array $documents, int $max_tokens = 2000, int $timeout = 120)
{
    $content = [];
    foreach ($documents as $document) {
        $read = theme_ai_read_document($document, 'claude');
        if (is_wp_error($read)) {
            return $read;
        }
        [$b64, $mime] = $read;
        // Claude phân biệt block ảnh và block document (PDF).
        $content[] = [
            'type'   => strpos($mime, 'image/') === 0 ? 'image' : 'document',
            'source' => [
                'type'       => 'base64',
                'media_type' => $mime,
                'data'       => $b64,
            ],
        ];
    }

    $content[] = ['type' => 'text', 'text' => $prompt];

    $payload = [
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $content]],
    ];

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => max(30, $timeout),
        'headers' => [
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300) {
        $msg = is_array($data) && !empty($data['error']['message']) ? (string) $data['error']['message'] : 'Claude document request failed.';
        return new WP_Error('theme_ai_claude_document_error', $msg, ['status' => $code, 'provider' => 'claude']);
    }

    if (($data['stop_reason'] ?? '') === 'refusal') {
        return new WP_Error('theme_ai_claude_refusal', 'Claude từ chối xử lý nội dung này.', ['provider' => 'claude']);
    }

    $text = '';
    foreach ((array) ($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
            $text .= (string) $block['text'];
        }
    }

    $text = trim($text);
    if ($text === '') {
        return new WP_Error('theme_ai_claude_document_empty', 'Claude trả về nội dung rỗng.', ['provider' => 'claude']);
    }

    return $text;
}

/**
 * @param array<int,array{path:string,mime_type?:string,title?:string}> $documents
 * @return string|WP_Error
 */
function theme_ai_call_gemini_with_documents(string $api_key, string $model, string $system, string $prompt, array $documents, int $max_tokens = 2000, int $timeout = 120)
{
    $parts = [];
    foreach ($documents as $document) {
        $read = theme_ai_read_document($document, 'gemini');
        if (is_wp_error($read)) {
            return $read;
        }
        [$b64, $mime] = $read;
        $parts[] = [
            'inlineData' => [
                'mimeType' => $mime,
                'data'     => $b64,
            ],
        ];
    }
    $parts[] = ['text' => $prompt];

    $payload = [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents'           => [['role' => 'user', 'parts' => $parts]],
        'generationConfig'   => [
            'temperature'     => 0,
            'maxOutputTokens' => $max_tokens,
            'thinkingConfig'  => theme_ai_gemini_thinking_config($model),
        ],
    ];

    $response = wp_remote_post('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent', [
        'timeout' => max(30, $timeout),
        'headers' => ['x-goog-api-key' => $api_key, 'Content-Type' => 'application/json'],
        'body'    => wp_json_encode($payload),
    ]);
    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300) {
        $message = is_array($data) && !empty($data['error']['message']) ? (string) $data['error']['message'] : 'Gemini document request failed.';
        return new WP_Error('theme_ai_gemini_document_error', $message, ['status' => $code, 'provider' => 'gemini']);
    }

    $text = '';
    foreach ((array) ($data['candidates'][0]['content']['parts'] ?? []) as $part) {
        if (empty($part['thought']) && isset($part['text']) && is_string($part['text'])) {
            $text .= $part['text'];
        }
    }

    return trim($text) !== '' ? trim($text) : new WP_Error('theme_ai_gemini_document_empty', 'Gemini trả về nội dung rỗng.', ['provider' => 'gemini']);
}

/**
 * @param array<int,array{path:string,mime_type?:string,title?:string}> $documents
 * @return string|WP_Error
 */
function theme_ai_call_openai_with_documents(string $api_key, string $model, string $system, string $prompt, array $documents, int $max_tokens = 2000, int $timeout = 120)
{
    $content = [];
    foreach ($documents as $document) {
        $read = theme_ai_read_document($document, 'openai');
        if (is_wp_error($read)) {
            return $read;
        }
        [$b64, $mime] = $read;
        if (strpos($mime, 'image/') === 0) {
            $content[] = [
                'type'      => 'input_image',
                'image_url' => 'data:' . $mime . ';base64,' . $b64,
            ];
        } else {
            $content[] = [
                'type'      => 'input_file',
                'filename'  => (string) ($document['title'] ?? basename((string) $document['path'])),
                'file_data' => 'data:' . $mime . ';base64,' . $b64,
            ];
        }
    }
    $content[] = ['type' => 'input_text', 'text' => $prompt];

    $payload = [
        'model'             => $model,
        'instructions'      => $system,
        'input'             => [['role' => 'user', 'content' => $content]],
        'max_output_tokens' => $max_tokens,
    ];

    $response = wp_remote_post('https://api.openai.com/v1/responses', [
        'timeout' => max(30, $timeout),
        'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
        'body'    => wp_json_encode($payload),
    ]);
    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300) {
        $message = is_array($data) && !empty($data['error']['message']) ? (string) $data['error']['message'] : 'OpenAI document request failed.';
        return new WP_Error('theme_ai_openai_document_error', $message, ['status' => $code, 'provider' => 'openai']);
    }

    $text = isset($data['output_text']) && is_string($data['output_text']) ? $data['output_text'] : '';
    if ($text === '') {
        foreach ((array) ($data['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $block) {
                if (($block['type'] ?? '') === 'output_text' && isset($block['text'])) {
                    $text .= (string) $block['text'];
                }
            }
        }
    }

    return trim($text) !== '' ? trim($text) : new WP_Error('theme_ai_openai_document_empty', 'OpenAI trả về nội dung rỗng.', ['provider' => 'openai']);
}

/**
 * Bóc JSON object đầu tiên từ text AI trả về (chịu được ```json fence, chú thích thừa).
 *
 * @return array|WP_Error
 */
function theme_ai_parse_json_object(string $text)
{
    $text = trim($text);
    // Bỏ code fence nếu có.
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);

    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return new WP_Error('theme_ai_bad_json', 'AI không trả về JSON hợp lệ.');
    }

    $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
    if (!is_array($decoded)) {
        return new WP_Error('theme_ai_bad_json', 'AI không trả về JSON hợp lệ.');
    }

    return $decoded;
}
