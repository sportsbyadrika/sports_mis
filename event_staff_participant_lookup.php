<?php
$page_title = 'Participant Lookup';
require_once __DIR__ . '/includes/header.php';
require_login();
require_role(['event_staff']);

$user = current_user();
$db = get_db_connection();

if (!$user['event_id']) {
    echo '<div class="alert alert-warning">No event assigned to your account. Please contact the event administrator.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$assigned_event_id = (int) $user['event_id'];
$chest_filter = trim((string) get_param('chest_number', ''));
$name_filter = trim((string) get_param('name', ''));

$has_search = $chest_filter !== '' || $name_filter !== '';
$participants = [];

if ($has_search) {
    $sql = "SELECT p.*, i.name AS institution_name,
                   COUNT(CASE WHEN em.event_type = 'Individual' THEN pe.id END) AS event_count,
                   COALESCE(SUM(CASE WHEN em.event_type = 'Individual' THEN pe.fees ELSE 0 END), 0) AS total_fees
            FROM participants p
            LEFT JOIN institutions i ON i.id = p.institution_id
            LEFT JOIN participant_events pe ON pe.participant_id = p.id
            LEFT JOIN event_master em ON em.id = pe.event_master_id
            WHERE p.event_id = ?";

    $params = [$assigned_event_id];
    $types = 'i';

    if ($chest_filter !== '') {
        $sql .= " AND p.chest_number IS NOT NULL AND CAST(p.chest_number AS CHAR) LIKE ?";
        $params[] = '%' . $chest_filter . '%';
        $types .= 's';
    }

    if ($name_filter !== '') {
        $sql .= " AND p.name LIKE ?";
        $params[] = '%' . $name_filter . '%';
        $types .= 's';
    }

    $sql .= " GROUP BY p.id, i.name";
    $sql .= " ORDER BY p.name";

    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

$age_categories = fetch_age_categories($db);
$status_classes = [
    'draft' => 'secondary',
    'submitted' => 'primary',
    'approved' => 'success',
    'rejected' => 'danger',
];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Participant Lookup</h1>
        <p class="text-muted mb-0">Search for a participant using their chest number or name and review their details.</p>
    </div>
    <div>
        <a href="event_staff_participants.php" class="btn btn-outline-secondary">
            <i class="bi bi-people-fill me-2"></i>Participant List
        </a>
    </div>
</div>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="chest_number" class="form-label">Chest Number</label>
                <input type="text" name="chest_number" id="chest_number" class="form-control" value="<?php echo sanitize($chest_filter); ?>" placeholder="Enter chest number">
            </div>
            <div class="col-md-4">
                <label for="name" class="form-label">Participant Name</label>
                <input type="text" name="name" id="name" class="form-control" value="<?php echo sanitize($name_filter); ?>" placeholder="Enter participant name">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Search</button>
                <a href="event_staff_participant_lookup.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>
<?php if (!$has_search): ?>
    <div class="alert alert-info">Enter a chest number or participant name to begin your search.</div>
<?php elseif (count($participants) === 0): ?>
    <div class="alert alert-warning">No participants matched the provided filters.</div>
<?php else: ?>
    <?php foreach ($participants as $participant): ?>
        <?php
            $participant_age = calculate_age($participant['date_of_birth'] ?? null);
            $participant_age_category = determine_age_category_label($participant_age, $age_categories);
            $status = $participant['status'] ?? 'draft';
            $status_class = $status_classes[$status] ?? 'secondary';
        ?>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="row g-4 align-items-start">
                    <div class="col-md-3 col-lg-2 text-center">
                        <?php if (!empty($participant['photo_path'])): ?>
                            <img src="<?php echo sanitize($participant['photo_path']); ?>" alt="Passport photo" class="img-thumbnail" style="max-width: 160px;">
                        <?php else: ?>
                            <div class="border rounded bg-light d-flex align-items-center justify-content-center" style="height: 160px;">
                                <span class="text-muted">No photo</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 col-lg-7">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h2 class="h5 mb-1"><?php echo sanitize($participant['name']); ?></h2>
                                <div class="mb-2">
                                    <span class="badge bg-<?php echo $status_class; ?> text-uppercase"><?php echo sanitize($status); ?></span>
                                    <?php if (!empty($participant['chest_number'])): ?>
                                        <span class="badge bg-primary ms-2">Chest No: <?php echo sanitize((string) $participant['chest_number']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted">Institution: <?php echo sanitize($participant['institution_name'] ?? ''); ?></div>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <div class="text-muted small">Date of Birth</div>
                                <div class="fw-semibold"><?php echo $participant['date_of_birth'] ? sanitize(format_date($participant['date_of_birth'])) : '<span class="text-muted">Not available</span>'; ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Age</div>
                                <div class="fw-semibold"><?php echo $participant_age !== null ? (int) $participant_age . ' years' : '<span class="text-muted">Not available</span>'; ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Age Category</div>
                                <div class="fw-semibold"><?php echo $participant_age_category ? sanitize($participant_age_category) : '<span class="text-muted">No age category</span>'; ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Gender</div>
                                <div class="fw-semibold"><?php echo sanitize($participant['gender'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Contact</div>
                                <div class="fw-semibold"><?php echo sanitize($participant['contact_number'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Aadhaar Number</div>
                                <div class="fw-semibold"><?php echo sanitize($participant['aadhaar_number'] ?? ''); ?></div>
                            </div>
                            <div class="col-12">
                                <div class="text-muted small">Address</div>
                                <div class="fw-semibold"><?php echo nl2br(sanitize($participant['address'] ?? '')); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Total Events</div>
                                <div class="fw-semibold"><?php echo (int) ($participant['event_count'] ?? 0); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Total Fees</div>
                                <div class="fw-semibold">₹<?php echo number_format((float) ($participant['total_fees'] ?? 0), 2); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-3 d-flex flex-column align-items-end gap-2">
                        <div class="btn-group" role="group" aria-label="Participant actions">
                            <a href="event_staff_participant_view.php?participant_id=<?php echo (int) $participant['id']; ?>" class="btn btn-outline-primary" title="View participant">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="event_staff_participant_edit.php?id=<?php echo (int) $participant['id']; ?>" class="btn btn-outline-secondary" title="Edit participant">
                                <i class="bi bi-pencil-square"></i>
                            </a>
                            <a href="event_staff_participant_print.php?participant_id=<?php echo (int) $participant['id']; ?>" class="btn btn-outline-success" title="Print participant" target="_blank" rel="noopener">
                                <i class="bi bi-printer"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
