<?php
/** Site-owned incident fields and shortage tag catalogue; shared incident code reads this adapter. */
if (!defined('ABSPATH')) exit;

/** Canonical metadata keys in precedence order (historical fallbacks remain readable). */
function thean_issue_site_fields(): array
{
    return ['handling' => ['order_handling_status', 'handling_status'], 'shipper' => ['order_shipper', 'shipper'], 'dispatch' => ['order_export_date', 'export_date']];
}

/** Shortage values are shared by the metabox and incident rules. */
function thean_issue_shortage_options(): array
{
    return ['Thiếu SP' => 'Thiếu SP'];
}
