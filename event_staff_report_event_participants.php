<?php
$page_title = 'Event Wise Approved Participants';
require_once __DIR__ . '/includes/auth.php';

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
$event_master_id = (int) get_param('event_master_id', 0);

if ($event_master_id > 0) {
    $stmt = $db->prepare('SELECT em.id, em.name, em.label, em.gender, ac.name AS age_category_name
        FROM event_master em
        INNER JOIN age_categories ac ON ac.id = em.age_category_id
        WHERE em.id = ? AND em.event_id = ?
        LIMIT 1');
    $stmt->bind_param('ii', $event_master_id, $assigned_event_id);
    $stmt->execute();
    $event_master = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$event_master) {
        http_response_code(404);
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Event Not Found - <?php echo APP_NAME; ?></title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <body class="bg-white">
            <main class="container my-5">
                <div class="alert alert-danger">The requested event could not be found or you do not have access to it.</div>
            </main>
        </body>
        </html>
        <?php
        return;
    }

    $stmt = $db->prepare('SELECT p.id, p.name, p.gender, p.date_of_birth, p.chest_number, p.photo_path, i.name AS institution_name
        FROM participant_events pe
        INNER JOIN participants p ON p.id = pe.participant_id
        LEFT JOIN institutions i ON i.id = p.institution_id
        WHERE pe.event_master_id = ? AND p.event_id = ? AND p.status = "approved"
        ORDER BY COALESCE(p.chest_number, 999999), p.name');
    $stmt->bind_param('ii', $event_master_id, $assigned_event_id);
    $stmt->execute();
    $participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($participants as &$participant) {
        $participant['age'] = calculate_age($participant['date_of_birth']);
    }
    unset($participant);

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo sanitize($event_master['label'] ?: $event_master['name']); ?> - Approved Participants</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background-color: #ffffff; }
            .participant-photo {
                width: 60px;
                height: 60px;
                object-fit: cover;
                border-radius: 0.5rem;
                border: 1px solid #dee2e6;
            }
            .participant-photo-placeholder {
                width: 60px;
                height: 60px;
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
                <h1 class="h4 mb-1">Approved Participants</h1>
                <div class="small text-muted">
                    <?php echo sanitize($event_master['label'] ?: $event_master['name']); ?>
                    &middot; <?php echo sanitize($event_master['age_category_name']); ?>
                    &middot; <?php echo sanitize($event_master['gender']); ?>
                </div>
            </div>
            <button type="button" class="btn btn-outline-secondary no-print" onclick="window.print();">Print</button>
        </div>
        <?php if (!$participants): ?>
            <div class="alert alert-info">No approved participants found for this event.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="text-center" style="width: 60px;">#</th>
                            <th scope="col" style="width: 120px;">Chest No.</th>
                            <th scope="col" style="width: 100px;">Photo</th>
                            <th scope="col">Participant</th>
                            <th scope="col">Institution</th>
                            <th scope="col" style="width: 20%;">Remarks</th>
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
                                <td><?php echo sanitize($participant['institution_name'] ?? ''); ?></td>
                                <td></td>
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

require_once __DIR__ . '/includes/header.php';

$selected_gender = trim((string) get_param('gender', ''));
$selected_age_category_id = (int) get_param('age_category_id', 0);
$valid_genders = ['Male', 'Female', 'Open'];
if ($selected_gender !== '' && !in_array($selected_gender, $valid_genders, true)) {
    $selected_gender = '';
}

$age_categories = [];
$stmt = $db->prepare('SELECT DISTINCT ac.id, ac.name, ac.min_age, ac.max_age
    FROM event_master em
    INNER JOIN age_categories ac ON ac.id = em.age_category_id
    WHERE em.event_id = ?
    ORDER BY COALESCE(ac.min_age, 0), COALESCE(ac.max_age, 9999), ac.name');
$stmt->bind_param('i', $assigned_event_id);
$stmt->execute();
$age_categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$valid_age_category_ids = array_map(static fn(array $category): int => (int) $category['id'], $age_categories);
if ($selected_age_category_id > 0 && !in_array($selected_age_category_id, $valid_age_category_ids, true)) {
    $selected_age_category_id = 0;
}

$sql = "SELECT em.id, em.name, em.label, em.gender, ac.name AS age_category_name, ac.min_age, ac.max_age,
        COUNT(DISTINCT p.id) AS approved_count
    FROM event_master em
    INNER JOIN age_categories ac ON ac.id = em.age_category_id
    LEFT JOIN participant_events pe ON pe.event_master_id = em.id
    LEFT JOIN participants p ON p.id = pe.participant_id AND p.status = 'approved'
    WHERE em.event_id = ?";
$params = [$assigned_event_id];
$types = 'i';

if ($selected_gender !== '') {
    $sql .= ' AND em.gender = ?';
    $params[] = $selected_gender;
    $types .= 's';
}

if ($selected_age_category_id > 0) {
    $sql .= ' AND em.age_category_id = ?';
    $params[] = $selected_age_category_id;
    $types .= 'i';
}

$sql .= ' GROUP BY em.id, em.name, em.label, em.gender, ac.name, ac.min_age, ac.max_age';
$sql .= ' ORDER BY COALESCE(ac.min_age, 0), COALESCE(ac.max_age, 9999), COALESCE(em.label, em.name), em.name';

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Event Wise Approved Participants</h1>
        <p class="text-muted mb-0">Review approved participant counts per event and open printable lists.</p>
    </div>
</div>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-sm-4 col-md-3">
                <label for="gender" class="form-label">Gender</label>
                <select name="gender" id="gender" class="form-select">
                    <option value="">All Genders</option>
                    <?php foreach ($valid_genders as $gender): ?>
                        <option value="<?php echo sanitize($gender); ?>" <?php echo $selected_gender === $gender ? 'selected' : ''; ?>><?php echo sanitize($gender); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-4 col-md-3">
                <label for="age_category_id" class="form-label">Age Category</label>
                <select name="age_category_id" id="age_category_id" class="form-select">
                    <option value="0">All Age Categories</option>
                    <?php foreach ($age_categories as $category): ?>
                        <option value="<?php echo (int) $category['id']; ?>" <?php echo $selected_age_category_id === (int) $category['id'] ? 'selected' : ''; ?>><?php echo sanitize($category['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-8 col-md-6 col-lg-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Apply Filter</button>
                <a href="event_staff_report_event_participants.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <?php if (!$events): ?>
            <div class="alert alert-info mb-0">No events found for the selected filters.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th scope="col" class="text-center" style="width: 60px;">#</th>
                            <th scope="col">Age Category</th>
                            <th scope="col">Event</th>
                            <th scope="col">Gender</th>
                            <th scope="col" class="text-center" style="width: 160px;">Approved Participants</th>
                            <th scope="col" class="text-end" style="width: 200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $index => $event): ?>
                            <tr>
                                <td class="text-center"><?php echo (int) ($index + 1); ?></td>
                                <td><?php echo sanitize($event['age_category_name']); ?></td>
                                <td><?php echo sanitize($event['label'] ?: $event['name']); ?></td>
                                <td><?php echo sanitize($event['gender']); ?></td>
                                <td class="text-center fw-semibold"><?php echo (int) $event['approved_count']; ?></td>
                                <td class="text-end">
                                    <a href="event_staff_report_event_participants.php?event_master_id=<?php echo (int) $event['id']; ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
                                        View Approved Participants
                                    </a>
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
