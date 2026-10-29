<?php
if (!defined('ABSPATH')) exit;

/** Secrets are configured server-side, never accepted from tool arguments. */
function erp_mesh_peers(): array {
    $peers = defined('ERP_MESH_PEERS') && is_array(ERP_MESH_PEERS) ? ERP_MESH_PEERS : [];
    return array_intersect_key($peers, array_flip(['ivar', 'hithean', 'theanmarket']));
}

function erp_mesh_peer_rpc(array $peer, string $method, array $params, &$session, &$protocol) {
    $endpoint = (string) ($peer['endpoint'] ?? '');
    $parts = wp_parse_url($endpoint);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !preg_match('~/mcp/[a-z0-9-]+/?$~', $parts['path'] ?? '')
        || empty($peer['username']) || empty($peer['password'])) {
        return new WP_Error('mesh_config', 'Peer chưa có endpoint HTTPS hoặc credential hợp lệ.');
    }
    $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Basic ' . base64_encode($peer['username'] . ':' . $peer['password']),
        'MCP-Protocol-Version' => $protocol];
    if ($session !== '') $headers['Mcp-Session-Id'] = $session;
    $payload = ['jsonrpc' => '2.0', 'method' => $method];
    if ($method !== 'notifications/initialized') $payload['id'] = wp_generate_uuid4();
    if ($params) $payload['params'] = $params;
    $response = wp_safe_remote_post($endpoint, ['timeout' => 20, 'redirection' => 0,
        'limit_response_size' => 2097152, 'headers' => $headers, 'body' => wp_json_encode($payload)]);
    if (is_wp_error($response)) return new WP_Error('mesh_connection', 'Không kết nối được MCP peer. Kiểm tra kết nối và cấu hình máy chủ.');
    $status = wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) return new WP_Error('mesh_http', 'MCP peer trả HTTP ' . $status . '.');
    $new_session = (string) wp_remote_retrieve_header($response, 'mcp-session-id');
    if ($new_session !== '') $session = $new_session;
    if ($method === 'notifications/initialized') return [];
    $body = wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        // SSE response: accept only the JSON-RPC response matching our request.
        foreach (preg_split('/\r\n|\n|\r/', $body) as $line) {
            if (strpos($line, 'data:') !== 0) continue;
            $event = json_decode(trim(substr($line, 5)), true);
            if (is_array($event) && ($event['id'] ?? null) === $payload['id']) $decoded = $event;
        }
    }
    if (!is_array($decoded) || ($decoded['id'] ?? null) !== $payload['id'] || isset($decoded['error'])) {
        return new WP_Error('mesh_protocol', 'MCP peer trả phản hồi không hợp lệ.');
    }
    return $decoded['result'] ?? new WP_Error('mesh_protocol', 'MCP peer thiếu kết quả.');
}

function erp_mesh_peer_call(string $site, string $operation, array $arguments) {
    $peers = erp_mesh_peers();
    if (!isset($peers[$site]) || $site === erp_mesh_site() || !in_array($operation, ['orders-list', 'orders-get'], true)) {
        return new WP_Error('mesh_peer', 'Peer hoặc thao tác chưa được cho phép.');
    }
    $session = ''; $protocol = '2025-03-26';
    $init = erp_mesh_peer_rpc($peers[$site], 'initialize', [
        'protocolVersion' => $protocol, 'capabilities' => (object) [],
        'clientInfo' => ['name' => 'erp-mesh-' . erp_mesh_site(), 'version' => '1.0.0'],
    ], $session, $protocol);
    if (is_wp_error($init)) return $init;
    $protocol = (string) ($init['protocolVersion'] ?? $protocol);
    $ready = erp_mesh_peer_rpc($peers[$site], 'notifications/initialized', [], $session, $protocol);
    if (is_wp_error($ready)) return $ready;
    $result = erp_mesh_peer_rpc($peers[$site], 'tools/call', ['name' => 'erp-mesh-' . $operation,
        'arguments' => (object) $arguments], $session, $protocol);
    if (is_wp_error($result)) return $result;
    if (!empty($result['isError'])) return new WP_Error('mesh_tool', 'Peer từ chối thao tác; kiểm tra quyền của tài khoản tích hợp.');
    if (isset($result['structuredContent']) && is_array($result['structuredContent'])) return $result['structuredContent'];
    foreach (($result['content'] ?? []) as $content) {
        if (($content['type'] ?? '') !== 'text') continue;
        $data = json_decode((string) ($content['text'] ?? ''), true);
        if (is_array($data)) return $data;
    }
    return new WP_Error('mesh_tool', 'Peer không trả dữ liệu có cấu trúc.');
}

function erp_mesh_peer_orders($input = []) {
    if (!erp_mesh_can_call_peers()) return new WP_Error('mesh_denied', 'Không có quyền gọi peer.', ['status' => 403]);
    $site = (string) ($input['site'] ?? '');
    unset($input['site']);
    return erp_mesh_peer_call($site, isset($input['id']) ? 'orders-get' : 'orders-list', $input);
}
