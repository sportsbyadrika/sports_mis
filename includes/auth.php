<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function login_user(string $email, string $password): bool
{
    $db = get_db_connection();
    $stmt = $db->prepare('SELECT id, name, email, password_hash, role, event_id, institution_id, contact_number FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    unset($user['password_hash']);
    $_SESSION['user'] = $user;

    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('index.php');
    }
}

function require_role(array $roles): void
{
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        redirect('dashboard.php');
    }
}

function refresh_current_user(): void
{
    $user = current_user();
    if (!$user) {
        return;
    }

    $db = get_db_connection();
    $stmt = $db->prepare('SELECT id, name, email, role, event_id, institution_id, contact_number FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $updated = $result->fetch_assoc();
    $stmt->close();

    if ($updated) {
        $_SESSION['user'] = $updated;
    }
}
