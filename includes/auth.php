<?php
/**
 * auth.php
 * ---------------------------------------------------------------
 * Bootstraps every page: session, DB connection, helpers,
 * login guard and CSRF protection.
 * ---------------------------------------------------------------
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/functions.php';

/** Is a user currently logged in? */
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/** Block access to a page unless logged in. */
function require_login(): void
{
    if (!is_logged_in()) {
        flash('warning', 'Please log in to continue.');
        redirect('login.php');
    }
}

/** Get (or create) the CSRF token for this session. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input containing the CSRF token, for use inside forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/** Validate the CSRF token of the current POST request. */
function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        flash('danger', 'Invalid or expired form. Please try again.');
        redirect('index.php');
    }
}
