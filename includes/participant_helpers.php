<?php
require_once __DIR__ . '/functions.php';

/**
 * Load a participant with access restrictions based on the current user's role.
 */
function load_participant_with_access(mysqli $db, int $participant_id, ?int $institution_id, ?int $event_id, string $role): ?array
{
    $sql = 'SELECT p.*, i.name AS institution_name, e.name AS event_name
            FROM participants p
            LEFT JOIN institutions i ON i.id = p.institution_id
            LEFT JOIN events e ON e.id = p.event_id
            WHERE p.id = ?';
    $params = [$participant_id];
    $types = 'i';

    if ($role === 'institution_admin') {
        $sql .= ' AND p.institution_id = ?';
        $params[] = $institution_id;
        $types .= 'i';
    } elseif ($role === 'event_admin' || $role === 'event_staff') {
        $sql .= ' AND p.event_id = ?';
        $params[] = $event_id;
        $types .= 'i';
        if ($institution_id) {
            $sql .= ' AND p.institution_id = ?';
            $params[] = $institution_id;
            $types .= 'i';
        }
    } elseif ($role === 'super_admin') {
        if ($event_id) {
            $sql .= ' AND p.event_id = ?';
            $params[] = $event_id;
            $types .= 'i';
        }
        if ($institution_id) {
            $sql .= ' AND p.institution_id = ?';
            $params[] = $institution_id;
            $types .= 'i';
        }
    }

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $participant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $participant ?: null;
}
