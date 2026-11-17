<?php
/**
 * functions.php
 * ---------------------------------------------------------------
 * Small reusable helpers used across all pages.
 * ---------------------------------------------------------------
 */

/** Escape a value for safe HTML output. */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Redirect to a relative URL and stop execution. */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Store a one-time flash message shown as a toast on the next page.
 * $type: success | danger | warning | info
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Get and clear all pending flash messages. */
function get_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/** Format a number as Philippine Peso. */
function peso($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

/** Format a date (Y-m-d) nicely, or a dash when empty. */
function nice_date(?string $date, string $format = 'M d, Y'): string
{
    return $date ? date($format, strtotime($date)) : '—';
}

/**
 * Determine the stock status of a product.
 * Returns [label, variant] where variant is success | warning | danger | neutral.
 */
function stock_status(array $p): array
{
    if (!empty($p['expiry_date']) && strtotime($p['expiry_date']) < strtotime('today')) {
        return ['Expired', 'neutral'];
    }
    if ((int) $p['quantity'] <= 0) {
        return ['Out of stock', 'danger'];
    }
    if ((int) $p['quantity'] <= (int) $p['reorder_level']) {
        return ['Low stock', 'warning'];
    }
    return ['In stock', 'success'];
}

/** Render the stock status badge HTML (shadcn-style pill with dot). */
function stock_badge(array $p): string
{
    [$label, $variant] = stock_status($p);
    return '<span class="s-badge s-badge-' . $variant . '"><span class="dot"></span>' . $label . '</span>';
}

/** Days until a date (negative when in the past). */
function days_until(?string $date): ?int
{
    if (!$date) {
        return null;
    }
    return (int) floor((strtotime($date) - strtotime('today')) / 86400);
}

/** Product avatar: neutral tile with the product's initials. */
function product_thumb(string $name, int $seed = 0): string
{
    $words    = preg_split('/\s+/', trim(preg_replace('/[^A-Za-z0-9\s]/', '', $name)));
    $initials = strtoupper(substr($words[0] ?? '', 0, 1) . substr($words[1] ?? '', 0, 1));
    return '<span class="s-avatar">' . e($initials ?: '?') . '</span>';
}

/** Render a shadcn-style empty state block. */
function empty_state(string $icon, string $title, string $text = '', string $actionHtml = ''): string
{
    return '<div class="empty-state"><div class="empty-icon"><i class="bi ' . $icon . '"></i></div>'
        . '<h3>' . e($title) . '</h3>' . ($text ? '<p class="mb-3 text-sm">' . e($text) . '</p>' : '')
        . $actionHtml . '</div>';
}

/** Read a trimmed POST string. */
function post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}
