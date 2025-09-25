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

function calculate_age(?string $date_of_birth): ?int
{
    if (!$date_of_birth) {
        return null;
    }

    try {
        $dob = new DateTime($date_of_birth);
        $today = new DateTime('today');
    } catch (Exception $e) {
        return null;
    }

    $diff = $dob->diff($today);

    return $diff ? (int) $diff->y : null;
}

function fetch_age_categories(mysqli $db): array
{
    $result = $db->query('SELECT name, min_age, max_age FROM age_categories ORDER BY COALESCE(min_age, 0), COALESCE(max_age, 9999), name');
    if (!$result) {
        return [];
    }

    $categories = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();

    return $categories;
}

function determine_age_category_label(?int $age, array $categories): ?string
{
    if ($age === null) {
        return null;
    }

    foreach ($categories as $category) {
        $min = array_key_exists('min_age', $category) && $category['min_age'] !== null ? (int) $category['min_age'] : null;
        $max = array_key_exists('max_age', $category) && $category['max_age'] !== null ? (int) $category['max_age'] : null;

        if (($min === null || $age >= $min) && ($max === null || $age <= $max)) {
            return (string) $category['name'];
        }
    }

    return null;
}
