<?php
/**
 * Helper functions shared across the Sports MIS application.
 */

function sanitize(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function is_get(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'GET';
}

function get_param(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

function post_param(string $key, $default = null)
{
    return $_POST[$key] ?? $default;
}

function validate_required(array $fields, array &$errors, array $data): void
{
    foreach ($fields as $field => $label) {
        if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
            $errors[$field] = $label . ' is required';
        }
    }
}

function format_date(?string $date): string
{
    if (!$date) {
        return '';
    }

    return date('d M Y', strtotime($date));
}

function set_flash(string $key, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'][$key] = $message;
}

function get_flash(string $key): ?string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }
    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);

    return $message;
}
