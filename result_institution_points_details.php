<?php
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    return;
}

if (!in_array($user['role'], ['event_staff'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    return;
}

$event_id = (int) ($user['event_id'] ?? 0);
if ($event_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'No event assigned to your account.']);
    return;
}

$institution_id = filter_input(INPUT_GET, 'institution_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$institution_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid institution ID.']);
    return;
}

$db = get_db_connection();

$institution_stmt = $db->prepare('SELECT id, name, affiliation_number FROM institutions WHERE id = ? AND event_id = ? LIMIT 1');
if (!$institution_stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to prepare institution lookup.']);
    return;
}

$institution_stmt->bind_param('ii', $institution_id, $event_id);
$institution_stmt->execute();
$institution = $institution_stmt->get_result()->fetch_assoc();
$institution_stmt->close();

if (!$institution) {
    http_response_code(404);
    echo json_encode(['error' => 'Institution not found.']);
    return;
}

$result_labels = get_result_label_map($db, $event_id);

$participants = [];
$participant_stmt = $db->prepare(
    "SELECT p.name AS participant_name,
            COALESCE(NULLIF(em.label, ''), em.name) AS event_label,
            COALESCE(ier.result, 'participant') AS result_key,
            COALESCE(ier.individual_score, '') AS score,
            COALESCE(ier.individual_points, 0) AS points
       FROM individual_event_results ier
       INNER JOIN participants p ON p.id = ier.participant_id
       INNER JOIN event_master em ON em.id = ier.event_master_id
      WHERE p.institution_id = ?
        AND p.event_id = ?
        AND em.event_id = ?
        AND em.event_type = 'Individual'
   ORDER BY p.name ASC, em.name ASC"
);

if ($participant_stmt) {
    $participant_stmt->bind_param('iii', $institution_id, $event_id, $event_id);
    $participant_stmt->execute();
    $participant_result = $participant_stmt->get_result();

    while ($row = $participant_result->fetch_assoc()) {
        $key = strtolower(trim((string) ($row['result_key'] ?? 'participant')));
        if ($key === '') {
            $key = 'participant';
        }

        $position = $result_labels[$key] ?? ucwords(str_replace('_', ' ', $key));
        $event_label = trim((string) ($row['event_label'] ?? ''));
        if ($event_label === '') {
            $event_label = 'Unnamed Event';
        }

        $participants[] = [
            'name' => (string) ($row['participant_name'] ?? ''),
            'eventLabel' => $event_label,
            'position' => $position,
            'score' => trim((string) ($row['score'] ?? '')),
            'points' => number_format((float) ($row['points'] ?? 0), 2),
        ];
    }

    $participant_stmt->close();
}

$teams = [];
$team_stmt = $db->prepare(
    "SELECT te.team_name,
            COALESCE(NULLIF(em.label, ''), em.name) AS event_label,
            COALESCE(ter.result, 'participant') AS result_key,
            COALESCE(ter.team_score, '') AS score,
            COALESCE(ter.team_points, 0) AS points
       FROM team_event_results ter
       INNER JOIN team_entries te ON te.id = ter.team_entry_id
       INNER JOIN event_master em ON em.id = ter.event_master_id
      WHERE te.institution_id = ?
        AND em.event_id = ?
   ORDER BY te.team_name ASC, em.name ASC"
);

if ($team_stmt) {
    $team_stmt->bind_param('ii', $institution_id, $event_id);
    $team_stmt->execute();
    $team_result = $team_stmt->get_result();

    while ($row = $team_result->fetch_assoc()) {
        $key = strtolower(trim((string) ($row['result_key'] ?? 'participant')));
        if ($key === '') {
            $key = 'participant';
        }

        $position = $result_labels[$key] ?? ucwords(str_replace('_', ' ', $key));
        $event_label = trim((string) ($row['event_label'] ?? ''));
        if ($event_label === '') {
            $event_label = 'Unnamed Event';
        }

        $teams[] = [
            'name' => (string) ($row['team_name'] ?? ''),
            'eventLabel' => $event_label,
            'position' => $position,
            'score' => trim((string) ($row['score'] ?? '')),
            'points' => number_format((float) ($row['points'] ?? 0), 2),
        ];
    }

    $team_stmt->close();
}

$response = [
    'institution' => [
        'id' => (int) $institution['id'],
        'name' => (string) ($institution['name'] ?? ''),
        'affiliation_number' => (string) ($institution['affiliation_number'] ?? ''),
    ],
    'participants' => $participants,
    'teams' => $teams,
];

echo json_encode($response);
