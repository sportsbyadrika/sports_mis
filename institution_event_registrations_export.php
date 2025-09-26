<?php
require_once __DIR__ . '/includes/auth.php';

require_login();
require_role(['super_admin', 'event_admin']);

$user = current_user();
$db = get_db_connection();

$event_id = null;

if ($user['role'] === 'event_admin') {
    if (!$user['event_id']) {
        http_response_code(403);
        echo 'Event context missing for export.';
        return;
    }
    $event_id = (int) $user['event_id'];
} else {
    $event_id = (int) (get_param('event_id') ?? 0);
    if (!$event_id) {
        http_response_code(400);
        echo 'event_id parameter is required.';
        return;
    }
}

$stmt = $db->prepare('SELECT e.name AS event_name FROM events e WHERE e.id = ? LIMIT 1');
$stmt->bind_param('i', $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    http_response_code(404);
    echo 'Event not found.';
    return;
}

$stmt = $db->prepare('SELECT i.name AS institution_name, em.code, em.name AS event_name, ier.status, ier.submitted_at, ier.reviewed_at,
        u1.name AS submitted_by_name, u2.name AS reviewed_by_name
    FROM institution_event_registrations ier
    JOIN event_master em ON em.id = ier.event_master_id
    JOIN institutions i ON i.id = ier.institution_id
    LEFT JOIN users u1 ON u1.id = ier.submitted_by
    LEFT JOIN users u2 ON u2.id = ier.reviewed_by
    WHERE em.event_id = ? AND em.event_type = "Institution"
    ORDER BY i.name, em.name');
$stmt->bind_param('i', $event_id);
$stmt->execute();
$result = $stmt->get_result();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="institution-event-registrations-' . $event_id . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Event', 'Institution', 'Event Code', 'Event Name', 'Status', 'Submitted At', 'Submitted By', 'Reviewed At', 'Reviewed By']);

while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $event['name'],
        $row['institution_name'],
        $row['code'],
        $row['event_name'],
        ucfirst($row['status']),
        $row['submitted_at'],
        $row['submitted_by_name'],
        $row['reviewed_at'],
        $row['reviewed_by_name'],
    ]);
}

fclose($output);
$stmt->close();
exit;
