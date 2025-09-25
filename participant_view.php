<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/participant_helpers.php';
require_login();
require_role(['institution_admin', 'event_admin', 'event_staff', 'super_admin']);

$user = current_user();
$db = get_db_connection();
$role = $user['role'];

$event_id = null;
$institution_id = null;
$institution_context = null;

if ($role === 'institution_admin') {
    if (!$user['institution_id']) {
        echo '<div class="alert alert-warning">No institution assigned to your account. Please contact the event administrator.</div>';
        include __DIR__ . '/includes/footer.php';
        return;
    }
    $institution_id = (int) $user['institution_id'];
    $stmt = $db->prepare('SELECT id, name, event_id FROM institutions WHERE id = ?');
    $stmt->bind_param('i', $institution_id);
    $stmt->execute();
    $institution_context = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$institution_context) {
        echo '<div class="alert alert-danger">Unable to load institution information.</div>';
        include __DIR__ . '/includes/footer.php';
        return;
    }
    $event_id = (int) $institution_context['event_id'];
} elseif ($role === 'event_admin' || $role === 'event_staff') {
    if (!$user['event_id']) {
        echo '<div class="alert alert-warning">No event assigned to your account. Please contact the super administrator.</div>';
        include __DIR__ . '/includes/footer.php';
        return;
    }
    $event_id = (int) $user['event_id'];
    $institution_id = (int) get_param('institution_id', 0) ?: null;
} else {
    $event_id = (int) get_param('event_id', 0) ?: null;
    $institution_id = (int) get_param('institution_id', 0) ?: null;
}

$participant_id = (int) get_param('id', 0);
if (!$participant_id) {
    echo '<div class="alert alert-warning">Participant not specified.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$participant = load_participant_with_access($db, $participant_id, $institution_id, $event_id, $role);
if (!$participant) {
    echo '<div class="alert alert-danger">Unable to load participant details or insufficient permissions.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$participant_age = calculate_age($participant['date_of_birth']);
$age_categories = fetch_age_categories($db);
$participant_age_category = determine_age_category_label($participant_age, $age_categories);

$stmt = $db->prepare('SELECT pe.id, em.name, em.code, em.label, em.event_type, em.fees, ac.name AS age_category_name FROM participant_events pe JOIN event_master em ON em.id = pe.event_master_id JOIN age_categories ac ON ac.id = em.age_category_id WHERE pe.participant_id = ? ORDER BY em.name');
$stmt->bind_param('i', $participant_id);
$stmt->execute();
$assigned_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_events = count($assigned_events);
$total_fees = 0.0;
foreach ($assigned_events as $event) {
    $total_fees += (float) $event['fees'];
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Participant Details</h1>
        <p class="text-muted mb-0">Review registration information and participation history.</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($participant['status'] === 'approved' && $participant['chest_number']): ?>
            <?php
                $card_params = ['id' => $participant_id];
                if (in_array($role, ['event_admin', 'event_staff', 'super_admin'], true) && $institution_id) {
                    $card_params['institution_id'] = $institution_id;
                }
                if ($role === 'super_admin' && $event_id) {
                    $card_params['event_id'] = $event_id;
                }
                $card_url = 'participant_competitor_card.php?' . http_build_query($card_params);
            ?>
            <a href="<?php echo sanitize($card_url); ?>" target="_blank" class="btn btn-outline-primary">Competitor Card</a>
        <?php endif; ?>
        <a href="participants.php" class="btn btn-outline-secondary">Close</a>
    </div>
</div>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <div>
            <h2 class="h6 mb-0"><?php echo sanitize($participant['name']); ?></h2>
            <?php $status_class = in_array($participant['status'], ['submitted', 'approved'], true) ? 'success' : 'secondary'; ?>
            <span class="badge bg-<?php echo $status_class; ?> text-uppercase"><?php echo sanitize($participant['status']); ?></span>
            <?php if ($participant['status'] === 'approved' && $participant['chest_number']): ?>
                <div class="mt-2">
                    <span class="badge bg-primary">Chest No: <?php echo sanitize((string) $participant['chest_number']); ?></span>
                </div>
            <?php endif; ?>
        </div>
        <div class="text-end">
            <div class="small text-muted">Total Events</div>
            <div class="fw-semibold"><?php echo (int) $total_events; ?></div>
            <div class="small text-muted mt-2">Total Fees</div>
            <div class="fw-semibold">₹<?php echo number_format($total_fees, 2); ?></div>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="text-muted small">Full Name</div>
                <div class="fw-semibold"><?php echo sanitize($participant['name']); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Date of Birth</div>
                <div class="fw-semibold"><?php echo format_date($participant['date_of_birth']); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Age</div>
                <div class="fw-semibold"><?php echo $participant_age !== null ? (int) $participant_age . ' years' : '<span class="text-muted">Not available</span>'; ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Age Category</div>
                <div class="fw-semibold"><?php echo $participant_age_category ? sanitize($participant_age_category) : '<span class="text-muted">No age category</span>'; ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Gender</div>
                <div class="fw-semibold"><?php echo sanitize($participant['gender']); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Guardian</div>
                <div class="fw-semibold"><?php echo sanitize($participant['guardian_name']); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Contact</div>
                <div class="fw-semibold"><?php echo sanitize($participant['contact_number']); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Aadhaar Number</div>
                <div class="fw-semibold"><?php echo sanitize($participant['aadhaar_number']); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Email</div>
                <div class="fw-semibold"><?php echo sanitize($participant['email']); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Passport Photo</div>
                <?php if (!empty($participant['photo_path'])): ?>
                    <div>
                        <img src="<?php echo sanitize($participant['photo_path']); ?>" alt="Passport photo" class="img-thumbnail" style="max-width: 140px;">
                    </div>
                <?php else: ?>
                    <div class="text-muted">No photo uploaded</div>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Chest Number</div>
                <div class="fw-semibold"><?php echo $participant['status'] === 'approved' && $participant['chest_number'] ? sanitize((string) $participant['chest_number']) : '<span class="text-muted">Not assigned</span>'; ?></div>
            </div>
            <div class="col-md-12">
                <div class="text-muted small">Address</div>
                <div class="fw-semibold"><?php echo nl2br(sanitize($participant['address'])); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Institution</div>
                <div class="fw-semibold"><?php echo sanitize($participant['institution_name']); ?></div>
            </div>
        </div>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h6 mb-3">Participation Events</h2>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Event Name</th>
                        <th>Label</th>
                        <th>Age Category</th>
                        <th>Type</th>
                        <th class="text-end">Fees</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($assigned_events as $event): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo sanitize($event['code']); ?></td>
                        <td><?php echo sanitize($event['name']); ?></td>
                        <td><?php echo $event['label'] ? sanitize($event['label']) : '<span class="text-muted">-</span>'; ?></td>
                        <td><?php echo sanitize($event['age_category_name']); ?></td>
                        <td><?php echo sanitize($event['event_type']); ?></td>
                        <td class="text-end">₹<?php echo number_format((float) $event['fees'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($assigned_events): ?>
                    <tr class="fw-semibold">
                        <td colspan="4" class="text-end">Totals</td>
                        <td class="text-end"><?php echo (int) $total_events; ?> events</td>
                        <td class="text-end">₹<?php echo number_format($total_fees, 2); ?></td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No events assigned to this participant.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
