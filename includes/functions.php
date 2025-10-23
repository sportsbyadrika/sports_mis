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
    if (!headers_sent()) {
        header('Location: ' . $path);
        exit;
    }

    echo '<script>window.location.href = ' . json_encode($path) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '"></noscript>';
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

function fetch_event_news(mysqli $db, int $event_id, int $limit = 5): array
{
    $stmt = $db->prepare("SELECT id, title, content, url, created_at FROM event_news WHERE event_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT ?");
    $stmt->bind_param('ii', $event_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $news = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $news;
}

function get_result_label_map(mysqli $db, int $event_id): array
{
    $labels = [
        'participant' => 'Participant',
        'first_place' => 'First Place',
        'second_place' => 'Second Place',
        'third_place' => 'Third Place',
        'fourth_place' => 'Fourth Place',
        'fifth_place' => 'Fifth Place',
        'sixth_place' => 'Sixth Place',
        'seventh_place' => 'Seventh Place',
        'eighth_place' => 'Eighth Place',
        'absent' => 'Absent',
        'withheld' => 'Withheld',
    ];

    $stmt = $db->prepare(
        "SELECT result_key, result_label FROM result_master_settings WHERE event_id = ? ORDER BY sort_order ASC, id ASC"
    );

    if ($stmt) {
        $stmt->bind_param('i', $event_id);
        $stmt->execute();
        $overrides = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($overrides as $row) {
            $key = strtolower(trim((string) ($row['result_key'] ?? '')));
            if ($key === '' || !array_key_exists($key, $labels)) {
                continue;
            }

            $label = trim((string) ($row['result_label'] ?? ''));
            if ($label !== '') {
                $labels[$key] = $label;
            }
        }
    }

    return $labels;
}
