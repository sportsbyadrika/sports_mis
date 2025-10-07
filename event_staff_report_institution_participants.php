<?php
$page_title = 'Institution Wise Approved Participants';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
require_role(['event_staff']);

$user = current_user();
$db = get_db_connection();

if (!$user['event_id']) {
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-warning">No event assigned to your account. Please contact the event administrator.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$assigned_event_id = (int) $user['event_id'];
$report_type = trim((string) get_param('report', ''));
$institution_id = (int) get_param('institution_id', 0);

$valid_report_types = ['individual', 'team'];

if ($report_type && in_array($report_type, $valid_report_types, true)) {
    if ($institution_id <= 0) {
        http_response_code(400);
        echo '<p>Invalid institution selection.</p>';
        return;
    }

    $stmt = $db->prepare('SELECT id, name, affiliation_number, spoc_name FROM institutions WHERE id = ? AND event_id = ? LIMIT 1');
    $stmt->bind_param('ii', $institution_id, $assigned_event_id);
    $stmt->execute();
    $institution = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$institution) {
        http_response_code(404);
        echo '<p>The requested institution could not be found.</p>';
        return;
    }

    if ($report_type === 'individual') {
        $stmt = $db->prepare('SELECT id, name, gender, date_of_birth, chest_number, photo_path
            FROM participants
            WHERE institution_id = ? AND event_id = ? AND status = "approved"
            ORDER BY COALESCE(chest_number, 999999), name');
        $stmt->bind_param('ii', $institution_id, $assigned_event_id);
        $stmt->execute();
        $participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $participant_ids = array_column($participants, 'id');
        $participant_events = [];

        if ($participant_ids) {
            $placeholders = implode(',', array_fill(0, count($participant_ids), '?'));
            $types = str_repeat('i', count($participant_ids));
            $query = "SELECT pe.participant_id, COALESCE(em.label, em.name) AS event_label
                FROM participant_events pe
                INNER JOIN event_master em ON em.id = pe.event_master_id
                WHERE pe.participant_id IN ($placeholders) AND em.event_type = 'Individual'
                ORDER BY COALESCE(em.label, em.name), em.name";
            $stmt = $db->prepare($query);
            $stmt->bind_param($types, ...$participant_ids);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $participant_events[(int) $row['participant_id']][] = $row['event_label'];
            }
            $stmt->close();
        }

        foreach ($participants as &$participant) {
            $participant['age'] = calculate_age($participant['date_of_birth']);
            $participant['events'] = $participant_events[(int) $participant['id']] ?? [];
        }
        unset($participant);

        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo sanitize($institution['name']); ?> - Approved Participants</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { background-color: #ffffff; }
                .participant-photo {
                    width: 70px;
                    height: 90px;
                    object-fit: cover;
                    border-radius: 0.5rem;
                    border: 1px solid #dee2e6;
                }
                .participant-photo-placeholder {
                    width: 70px;
                    height: 90px;
                    border-radius: 0.5rem;
                    border: 1px dashed #ced4da;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 0.75rem;
                    color: #6c757d;
                    background-color: #f8f9fa;
                }
                @media print {
                    .no-print { display: none !important; }
                    body { margin: 0; }
                }
            </style>
        </head>
        <body class="bg-white">
        <main class="container-fluid my-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h4 mb-1">Approved Individual Participants</h1>
                    <div class="small text-muted">
                        <?php echo sanitize($institution['name']); ?>
                        <?php if (!empty($institution['affiliation_number'])): ?>
                            &middot; Affiliation: <?php echo sanitize($institution['affiliation_number']); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="button" class="btn btn-outline-secondary no-print" onclick="window.print();">Print</button>
            </div>
            <?php if (!$participants): ?>
                <div class="alert alert-info">No approved participants found for this institution.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" class="text-center" style="width: 60px;">#</th>
                                <th scope="col" style="width: 120px;">Chest No.</th>
                                <th scope="col" style="width: 120px;">Photo</th>
                                <th scope="col">Participant</th>
                                <th scope="col" style="width: 30%;">Participating Events</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($participants as $index => $participant): ?>
                                <tr>
                                    <td class="text-center"><?php echo (int) ($index + 1); ?></td>
                                    <td>
                                        <?php if ($participant['chest_number'] !== null): ?>
                                            <?php echo sanitize($participant['chest_number']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($participant['photo_path'])): ?>
                                            <img src="<?php echo sanitize($participant['photo_path']); ?>" alt="Participant photo" class="participant-photo">
                                        <?php else: ?>
                                            <div class="participant-photo-placeholder">No Photo</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo sanitize($participant['name']); ?></div>
                                        <div class="text-muted small">
                                            <?php echo sanitize($participant['gender']); ?>
                                            <?php if ($participant['age'] !== null): ?>
                                                &middot; <?php echo (int) $participant['age']; ?> yrs
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!$participant['events']): ?>
                                            <span class="text-muted">No individual events mapped</span>
                                        <?php else: ?>
                                            <ul class="mb-0 ps-3">
                                                <?php foreach ($participant['events'] as $event_label): ?>
                                                    <li><?php echo sanitize($event_label); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
        </body>
        </html>
        <?php
        return;
    }

    if ($report_type === 'team') {
        $stmt = $db->prepare("SELECT te.id, te.team_name, COALESCE(em.label, em.name) AS event_label
            FROM team_entries te
            INNER JOIN event_master em ON em.id = te.event_master_id
            WHERE te.institution_id = ? AND em.event_id = ? AND em.event_type = 'Team' AND te.status = 'approved'
            ORDER BY COALESCE(em.label, em.name), te.team_name");
        $stmt->bind_param('ii', $institution_id, $assigned_event_id);
        $stmt->execute();
        $team_entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $team_entry_ids = array_column($team_entries, 'id');
        $team_members = [];

        if ($team_entry_ids) {
            $placeholders = implode(',', array_fill(0, count($team_entry_ids), '?'));
            $types = str_repeat('i', count($team_entry_ids));
            $query = "SELECT tem.team_entry_id, p.name, p.chest_number
                FROM team_entry_members tem
                INNER JOIN participants p ON p.id = tem.participant_id
                WHERE tem.team_entry_id IN ($placeholders)
                ORDER BY p.name";
            $stmt = $db->prepare($query);
            $stmt->bind_param($types, ...$team_entry_ids);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $team_members[(int) $row['team_entry_id']][] = $row;
            }
            $stmt->close();
        }

        $stmt = $db->prepare("SELECT COALESCE(em.label, em.name) AS event_label
            FROM institution_event_registrations ier
            INNER JOIN event_master em ON em.id = ier.event_master_id
            WHERE ier.institution_id = ? AND em.event_id = ? AND em.event_type = 'Institution' AND ier.status = 'approved'
            ORDER BY COALESCE(em.label, em.name), em.name");
        $stmt->bind_param('ii', $institution_id, $assigned_event_id);
        $stmt->execute();
        $institution_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo sanitize($institution['name']); ?> - Team & Institution Events</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { background-color: #ffffff; }
                @media print {
                    .no-print { display: none !important; }
                    body { margin: 0; }
                }
            </style>
        </head>
        <body class="bg-white">
        <main class="container-fluid my-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h4 mb-1">Approved Team Entries</h1>
                    <div class="small text-muted">
                        <?php echo sanitize($institution['name']); ?>
                        <?php if (!empty($institution['affiliation_number'])): ?>
                            &middot; Affiliation: <?php echo sanitize($institution['affiliation_number']); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="button" class="btn btn-outline-secondary no-print" onclick="window.print();">Print</button>
            </div>
            <?php if (!$team_entries): ?>
                <div class="alert alert-info">No approved team entries found for this institution.</div>
            <?php else: ?>
                <div class="table-responsive mb-5">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" class="text-center" style="width: 60px;">#</th>
                                <th scope="col">Team Name</th>
                                <th scope="col">Event</th>
                                <th scope="col" style="width: 35%;">Members</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($team_entries as $index => $team_entry): ?>
                                <tr>
                                    <td class="text-center"><?php echo (int) ($index + 1); ?></td>
                                    <td><?php echo sanitize($team_entry['team_name']); ?></td>
                                    <td><?php echo sanitize($team_entry['event_label']); ?></td>
                                    <td>
                                        <?php $members = $team_members[(int) $team_entry['id']] ?? []; ?>
                                        <?php if (!$members): ?>
                                            <span class="text-muted">No members recorded</span>
                                        <?php else: ?>
                                            <ul class="mb-0 ps-3">
                                                <?php foreach ($members as $member): ?>
                                                    <li>
                                                        <?php echo sanitize($member['name']); ?>
                                                        <?php if ($member['chest_number'] !== null): ?>
                                                            (Chest: <?php echo sanitize($member['chest_number']); ?>)
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h2 class="h5 mb-3">Approved Institution Events</h2>
            <?php if (!$institution_events): ?>
                <div class="alert alert-secondary">No approved institution events found for this institution.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" class="text-center" style="width: 60px;">#</th>
                                <th scope="col">Event</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($institution_events as $index => $event): ?>
                                <tr>
                                    <td class="text-center"><?php echo (int) ($index + 1); ?></td>
                                    <td><?php echo sanitize($event['event_label']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
        </body>
        </html>
        <?php
        return;
    }
}

require_once __DIR__ . '/includes/header.php';

$institutions = [];
$stmt = $db->prepare('SELECT id, name, affiliation_number, spoc_name FROM institutions WHERE event_id = ? ORDER BY name');
$stmt->bind_param('i', $assigned_event_id);
$stmt->execute();
$institutions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$participant_counts = [];
$stmt = $db->prepare("SELECT institution_id, COUNT(*) AS approved_count
    FROM participants
    WHERE event_id = ? AND status = 'approved'
    GROUP BY institution_id");
$stmt->bind_param('i', $assigned_event_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $participant_counts[(int) $row['institution_id']] = (int) $row['approved_count'];
}
$stmt->close();

$team_counts = [];
$stmt = $db->prepare("SELECT te.institution_id, COUNT(*) AS approved_count
    FROM team_entries te
    INNER JOIN event_master em ON em.id = te.event_master_id AND em.event_type = 'Team'
    WHERE em.event_id = ? AND te.status = 'approved'
    GROUP BY te.institution_id");
$stmt->bind_param('i', $assigned_event_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $team_counts[(int) $row['institution_id']] = (int) $row['approved_count'];
}
$stmt->close();

$institution_event_counts = [];
$stmt = $db->prepare("SELECT ier.institution_id, COUNT(*) AS approved_count
    FROM institution_event_registrations ier
    INNER JOIN event_master em ON em.id = ier.event_master_id AND em.event_type = 'Institution'
    WHERE em.event_id = ? AND ier.status = 'approved'
    GROUP BY ier.institution_id");
$stmt->bind_param('i', $assigned_event_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $institution_event_counts[(int) $row['institution_id']] = (int) $row['approved_count'];
}
$stmt->close();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Institution Wise Approved Participants</h1>
        <p class="text-muted mb-0">View approved participant, team, and institution event counts with quick printable reports.</p>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <?php if (!$institutions): ?>
            <div class="alert alert-info mb-0">No institutions found for your event.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th scope="col" class="text-center" style="width: 60px;">#</th>
                            <th scope="col">Institution</th>
                            <th scope="col">Affiliation No.</th>
                            <th scope="col">SPOC</th>
                            <th scope="col" class="text-center" style="width: 160px;">Approved Participants</th>
                            <th scope="col" class="text-center" style="width: 160px;">Approved Teams</th>
                            <th scope="col" class="text-center" style="width: 200px;">Approved Institution Events</th>
                            <th scope="col" class="text-end" style="width: 160px;">Reports</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($institutions as $index => $institution): ?>
                            <?php
                                $inst_id = (int) $institution['id'];
                                $approved_participants = $participant_counts[$inst_id] ?? 0;
                                $approved_teams = $team_counts[$inst_id] ?? 0;
                                $approved_institution_events = $institution_event_counts[$inst_id] ?? 0;
                            ?>
                            <tr>
                                <td class="text-center"><?php echo (int) ($index + 1); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo sanitize($institution['name']); ?></div>
                                    <div class="small text-muted">ID: <?php echo (int) $inst_id; ?></div>
                                </td>
                                <td><?php echo sanitize($institution['affiliation_number'] ?? ''); ?></td>
                                <td><?php echo sanitize($institution['spoc_name']); ?></td>
                                <td class="text-center fw-semibold"><?php echo $approved_participants; ?></td>
                                <td class="text-center fw-semibold"><?php echo $approved_teams; ?></td>
                                <td class="text-center fw-semibold"><?php echo $approved_institution_events; ?></td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-2">
                                        <a href="event_staff_report_institution_participants.php?institution_id=<?php echo $inst_id; ?>&report=individual" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary" title="Print individual participants">
                                            <i class="bi bi-person-badge"></i>
                                        </a>
                                        <a href="event_staff_report_institution_participants.php?institution_id=<?php echo $inst_id; ?>&report=team" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success" title="Print team and institution events">
                                            <i class="bi bi-people-fill"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
