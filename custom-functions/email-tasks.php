<?php
if (!defined('ABSPATH')) {
    exit;
}

const HITHEAN_BLOCKED_EMAIL_DOMAINS = [
    'zalo.me',
    'sms.theanorganics.com',
    'sms.hithean.com',
];

function hithean_normalize_mail_recipients($recipients): array
{
    $recipients = is_array($recipients) ? $recipients : [$recipients];
    $normalized = [];

    foreach ($recipients as $recipient) {
        $recipient = trim((string) $recipient);
        if ($recipient === '') {
            continue;
        }

        $recipient_parts = preg_split('/\s*,\s*/', $recipient, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($recipient_parts as $recipient_part) {
            $recipient_part = trim((string) $recipient_part);
            if (preg_match('/<([^<>]+)>/', $recipient_part, $matches)) {
                $recipient_part = trim($matches[1]);
            }

            $email = strtolower(sanitize_email($recipient_part));
            if ($email !== '') {
                $normalized[] = $email;
            }
        }
    }

    return $normalized;
}

function hithean_mail_targets_blocked_domain($recipients): bool
{
    $emails = hithean_normalize_mail_recipients($recipients);
    if ($emails === []) {
        return true;
    }

    foreach ($emails as $email) {
        $email_domain = substr(strrchr($email, '@') ?: '', 1);
        if ($email_domain === '') {
            return true;
        }

        foreach (HITHEAN_BLOCKED_EMAIL_DOMAINS as $blocked_domain) {
            if ($email_domain === $blocked_domain) {
                return true;
            }
        }
    }

    return false;
}

add_filter('pre_wp_mail', 'hithean_prevent_blocked_domain_mail', 10, 2);
function hithean_prevent_blocked_domain_mail($return, $atts)
{
    if (isset($atts['to']) && hithean_mail_targets_blocked_domain($atts['to'])) {
        return false;
    }

    return $return;
}

add_filter('wp_mail', 'prevent_invalid_or_blocked_emails');
function prevent_invalid_or_blocked_emails($args)
{
    if (empty($args['to']) || hithean_mail_targets_blocked_domain($args['to'])) {
        $args['to'] = [];
    }

    return $args;
}
