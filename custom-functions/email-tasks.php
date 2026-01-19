<?php

/*
add_filter('wp_mail', 'prevent_emails');

function prevent_emails($args)
{
    //$blocked_domain = '@zalo.me';
    $blocked_domains = [
        '@zalo.me',
        '@sms.theanorganics.com',
        '@sms.hithean.com'
    ];

    // Extract the domain from the recipient's email
    if (strpos($args['to'], $blocked_domain) !== false) {
        // Optionally, you can log or handle the block in some way
        // error_log('Email blocked to domain: ' . $args['to']);
        // Return an empty array to stop the email from being sent
        return array();
    }
    return $args;
}
*/

add_filter('wp_mail', 'prevent_invalid_or_blocked_emails');

function prevent_invalid_or_blocked_emails($args)
{
    $blocked_domains = [
        '@zalo.me',
        '@sms.theanorganics.com',
        '@sms.hithean.com'
    ];

    // Ensure 'to' is set and not empty // Dảm bảo có địa chỉ người nhận
    if (empty($args['to'])) {
        return array(); // Block sending
    }

    // Handle multiple recipients // Xử lý trường hợp có nhiều người nhận
    $recipients = is_array($args['to']) ? $args['to'] : [$args['to']];

    foreach ($recipients as $email) {
        foreach ($blocked_domains as $domain) {
            if (stripos($email, $domain) !== false) {
                return array(); // Block sending
            }
        }
    }

    return $args;
}

