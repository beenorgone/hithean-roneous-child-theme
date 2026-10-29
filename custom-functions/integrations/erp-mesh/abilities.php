<?php
if (!defined('ABSPATH')) exit;

function erp_mesh_order_schema(): array {
    $properties = [];
    foreach (['site', 'number', 'kind', 'status', 'currency', 'created_at', 'modified_at', 'total',
        'shipping_total', 'tax_total', 'refunded_total', 'return_status', 'fingerprint'] as $field) $properties[$field] = ['type' => 'string'];
    $properties['id'] = ['type' => 'integer'];
    $properties['export_confirmed'] = ['type' => 'boolean'];
    $properties['internal'] = ['type' => 'boolean'];
    $line = [];
    foreach (['line_id', 'product_id', 'variation_id'] as $field) $line[$field] = ['type' => 'integer'];
    foreach (['sku', 'name', 'subtotal', 'total', 'tax'] as $field) $line[$field] = ['type' => 'string'];
    foreach (['quantity', 'restocked_quantity'] as $field) $line[$field] = ['type' => 'number'];
    $properties['items'] = ['type' => 'array', 'maxItems' => 200, 'items' => ['type' => 'object',
        'properties' => $line, 'required' => array_keys($line), 'additionalProperties' => false]];
    return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
}

function erp_mesh_ability_definitions(): array {
    $pagination = [
        'basis' => ['type' => 'string', 'enum' => ['created', 'activity']],
        'ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'uniqueItems' => true,
            'items' => ['type' => 'integer', 'minimum' => 1]],
        'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000],
        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
        'date_from' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
        'date_to' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
    ];
    $definitions = [];
    foreach ([
        'orders-list' => ['List operational orders', $pagination, [], 'erp_mesh_orders_list', 'erp_mesh_can_read'],
        'orders-get' => ['Read one operational order', ['id' => ['type' => 'integer', 'minimum' => 1]], ['id'], 'erp_mesh_orders_get', 'erp_mesh_can_read'],
        'peer-orders' => ['Read orders from an approved peer', $pagination + [
            'site' => ['type' => 'string', 'enum' => ['ivar', 'hithean', 'theanmarket']],
            'id' => ['type' => 'integer', 'minimum' => 1],
        ], ['site'], 'erp_mesh_peer_orders', 'erp_mesh_can_call_peers'],
    ] as $name => $args) {
        $definitions['erp-mesh/' . $name] = [
            'label' => $args[0], 'description' => $args[0] . '. No customer identity, addresses, notes or arbitrary metadata. Sparse pages are possible; follow has_more.',
            'category' => 'erp-mesh',
            'input_schema' => ['type' => 'object', 'properties' => $args[1], 'required' => $args[2], 'additionalProperties' => false],
            'output_schema' => $name === 'orders-get' ? erp_mesh_order_schema() : ($name === 'orders-list' ? [
                'type' => 'object', 'properties' => ['site' => ['type' => 'string'],
                    'orders' => ['type' => 'array', 'items' => erp_mesh_order_schema()],
                    'page' => ['type' => 'integer'], 'has_more' => ['type' => 'boolean'], 'omitted' => ['type' => 'integer']],
                'required' => ['site', 'orders', 'page', 'has_more', 'omitted'], 'additionalProperties' => false,
            ] : ['type' => 'object', 'additionalProperties' => true]),
            'execute_callback' => $args[3], 'permission_callback' => $args[4],
            'meta' => ['mcp' => ['public' => true], 'show_in_rest' => false, 'annotations' => ['readonly' => true]],
        ];
    }
    return (array) apply_filters('erp_mesh_abilities', $definitions);
}

add_filter('ivar_erp_ai_ability_groups', static function (array $groups): array {
    $groups['erp-mesh'] = ['label' => 'Cross-site ERP', 'description' => 'Permissioned operational orders and peer reads.',
        'mcp_server' => true, 'server' => 'ivar-erp-records', 'abilities' => erp_mesh_ability_definitions()];
    return $groups;
});

add_action('wp_abilities_api_categories_init', static function (): void {
    if (erp_mesh_site() === 'ivar' || !function_exists('wp_register_ability_category')) return;
    wp_register_ability_category('erp-mesh', ['label' => 'Cross-site ERP', 'description' => 'Permissioned operational orders.']);
});
add_action('wp_abilities_api_init', static function (): void {
    if (erp_mesh_site() === 'ivar' || !function_exists('wp_register_ability')) return;
    foreach (erp_mesh_ability_definitions() as $name => $definition) wp_register_ability($name, $definition);
});
add_action('mcp_adapter_init', static function ($adapter): void {
    if (erp_mesh_site() === 'ivar') return; // Its existing guarded records server owns exposure.
    $slug = erp_mesh_site() . '-erp';
    $adapter->create_server($slug, 'mcp', $slug, strtoupper(erp_mesh_site()) . ' ERP',
        'Operational orders and reviewed cross-site workflows.', '1.0.0',
        [\WP\MCP\Transport\HttpTransport::class], null, null, array_keys(erp_mesh_ability_definitions()));
});
