<?php
/** Site-owned checkout blocklist; canonical local phone digits and lowercase emails. */
if (!defined('ABSPATH')) exit;

/** Return the identities blocked from placing customer checkout orders on this site. */
function child_theme_checkout_blocklist_policy(): array
{
    return [
        'phones' => ['0797096899'],
        'emails' => ['quynhnguyen298@gmail.com'],
    ];
}
