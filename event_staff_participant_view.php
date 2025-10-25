<?php
$page_title = 'Participant Details';
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
$participant_id = (int) get_param('participant_id', 0);

if ($participant_id <= 0) {
    echo '<div class="alert alert-danger">Invalid participant reference provided.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

// Helper to load participant details with aggregates.
function fetch_participant(mysqli $db, int $participant_id, int $event_id): ?array
{
    $sql = "SELECT p.name, p.gender, p.contact_number, p.status, p.chest_number, p.date_of_birth, p.photo_path,
                   i.name AS institution_name,
                   (SELECT COUNT(*)
                    FROM participant_events pe
                    JOIN event_master em1 ON em1.id = pe.event_master_id
                    WHERE pe.participant_id = p.id AND em1.event_type = 'Individual') AS event_count,
                   (SELECT COALESCE(SUM(pe2.fees), 0)
                    FROM participant_events pe2
                    JOIN event_master em2 ON em2.id = pe2.event_master_id
                    WHERE pe2.participant_id = p.id AND em2.event_type = 'Individual') AS total_fees
            FROM participants p
            LEFT JOIN institutions i ON i.id = p.institution_id
            WHERE p.id = ? AND p.event_id = ?
            LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $participant_id, $event_id);
    $stmt->execute();
    $participant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $participant ?: null;
}

if (is_post()) {
    $action = (string) post_param('action', '');

    if (!in_array($action, ['approve', 'reject'], true)) {
        set_flash('error', 'Invalid action requested.');
        redirect('event_staff_participant_view.php?participant_id=' . $participant_id);
    }

    $db->begin_transaction();

    $stmt = $db->prepare('SELECT status, chest_number FROM participants WHERE id = ? AND event_id = ? FOR UPDATE');
    $stmt->bind_param('ii', $participant_id, $assigned_event_id);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$current) {
        $db->rollback();
        set_flash('error', 'Participant not found or access denied.');
        redirect('event_staff_participants.php');
    }

    $status = $current['status'];

    if ($action === 'approve') {
        if ($status === 'approved') {
            $db->rollback();
            set_flash('error', 'The participant has already been approved.');
            redirect('event_staff_participant_view.php?participant_id=' . $participant_id);
        }

        $result = $db->query('SELECT MAX(chest_number) AS max_chest FROM participants WHERE chest_number IS NOT NULL');
        $max = $result ? (int) $result->fetch_assoc()['max_chest'] : 0;
        $next_chest_number = $max > 0 ? $max + 1 : 1001;

        $update_stmt = $db->prepare('UPDATE participants SET status = "approved", chest_number = ?, updated_at = NOW() WHERE id = ?');
        $update_stmt->bind_param('ii', $next_chest_number, $participant_id);
        $update_stmt->execute();
        $update_stmt->close();

        $db->commit();
        set_flash('success', 'Participant approved successfully. Assigned chest number: ' . $next_chest_number . '.');
        redirect('event_staff_participant_view.php?participant_id=' . $participant_id);
    }

    if ($action === 'reject') {
        if ($status === 'approved') {
            $db->rollback();
            set_flash('error', 'An approved participant cannot be rejected.');
            redirect('event_staff_participant_view.php?participant_id=' . $participant_id);
        }

        if ($status === 'rejected') {
            $db->rollback();
            set_flash('error', 'The participant has already been rejected.');
            redirect('event_staff_participant_view.php?participant_id=' . $participant_id);
        }

        $update_stmt = $db->prepare('UPDATE participants SET status = "rejected", chest_number = NULL, updated_at = NOW() WHERE id = ?');
        $update_stmt->bind_param('i', $participant_id);
        $update_stmt->execute();
        $update_stmt->close();

        $db->commit();
        set_flash('success', 'Participant marked as rejected.');
        redirect('event_staff_participant_view.php?participant_id=' . $participant_id);
    }

    $db->rollback();
    set_flash('error', 'Unable to process the requested action.');
    redirect('event_staff_participant_view.php?participant_id=' . $participant_id);
}

$participant = fetch_participant($db, $participant_id, $assigned_event_id);

if (!$participant) {
    echo '<div class="alert alert-danger">Participant not found or access denied.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$participant_age = calculate_age($participant['date_of_birth'] ?? null);
$age_categories = fetch_age_categories($db);
$participant_age_category = determine_age_category_label($participant_age, $age_categories);

$participant_events = [];
$stmt = $db->prepare("SELECT em.name AS event_name, em.label AS event_label, pe.fees\n    FROM participant_events pe\n    INNER JOIN event_master em ON em.id = pe.event_master_id\n    WHERE pe.participant_id = ? AND em.event_type = 'Individual'\n    ORDER BY em.name");
$stmt->bind_param('i', $participant_id);
$stmt->execute();
$participant_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$success_message = get_flash('success');
$error_message = get_flash('error');

$status_classes = [
    'draft' => 'secondary',
    'submitted' => 'primary',
    'approved' => 'success',
    'rejected' => 'danger',
];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Participant Details</h1>
        <p class="text-muted mb-0">Review and manage the participant's registration.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="event_staff_participant_print.php?participant_id=<?php echo (int) $participant_id; ?>" class="btn btn-outline-success" target="_blank" rel="noopener" title="Print participant profile">
            <i class="bi bi-printer"></i>
        </a>
        <a href="event_staff_participant_edit.php?id=<?php echo (int) $participant_id; ?>" class="btn btn-outline-primary" title="Edit participant">
            <i class="bi bi-pencil-square"></i>
        </a>
        <a href="event_staff_participants.php" class="btn btn-outline-secondary">Back to Participants</a>
    </div>
</div>

<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo sanitize($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo sanitize($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Personal Information</h2>
                <div class="row g-4 align-items-start">
                    <div class="col-md-8">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Name</dt>
                            <dd class="col-sm-8"><?php echo sanitize($participant['name']); ?></dd>

                            <dt class="col-sm-4">Institution</dt>
                            <dd class="col-sm-8"><?php echo sanitize($participant['institution_name'] ?? ''); ?></dd>

                            <dt class="col-sm-4">Date of Birth</dt>
                            <dd class="col-sm-8"><?php echo $participant['date_of_birth'] ? sanitize(format_date($participant['date_of_birth'])) : '<span class="text-muted">Not available</span>'; ?></dd>

                            <dt class="col-sm-4">Age</dt>
                            <dd class="col-sm-8"><?php echo $participant_age !== null ? (int) $participant_age . ' years' : '<span class="text-muted">Not available</span>'; ?></dd>

                            <dt class="col-sm-4">Age Category</dt>
                            <dd class="col-sm-8"><?php echo $participant_age_category ? sanitize($participant_age_category) : '<span class="text-muted">No age category</span>'; ?></dd>

                            <dt class="col-sm-4">Gender</dt>
                            <dd class="col-sm-8"><?php echo sanitize($participant['gender']); ?></dd>

                            <dt class="col-sm-4">Contact Number</dt>
                            <dd class="col-sm-8"><?php echo sanitize($participant['contact_number']); ?></dd>

                            <dt class="col-sm-4">Chest Number</dt>
                            <dd class="col-sm-8"><?php echo $participant['chest_number'] ? sanitize((string) $participant['chest_number']) : '<span class="text-muted">Not assigned</span>'; ?></dd>

                            <dt class="col-sm-4">Status</dt>
                            <dd class="col-sm-8">
                                <span class="badge bg-<?php echo $status_classes[$participant['status']] ?? 'secondary'; ?> text-uppercase">
                                    <?php echo sanitize($participant['status']); ?>
                                </span>
                            </dd>

                            <dt class="col-sm-4">Participating Events</dt>
                            <dd class="col-sm-8"><?php echo (int) $participant['event_count']; ?></dd>

                            <dt class="col-sm-4">Total Fees</dt>
                            <dd class="col-sm-8">₹<?php echo number_format((float) $participant['total_fees'], 2); ?></dd>
                        </dl>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="text-muted small mb-2">Participant Photo</div>
                        <?php if (!empty($participant['photo_path'])): ?>
                            <img src="<?php echo sanitize($participant['photo_path']); ?>" alt="Participant photo" class="img-fluid rounded border" style="max-width: 220px;">
                        <?php else: ?>
                            <div class="text-muted">No photo available</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Actions</h2>
                <p class="text-muted">Approve to assign a chest number or reject the participant if needed.</p>
                <div class="d-flex gap-2">
                    <form method="post">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-success" <?php echo $participant['status'] === 'approved' ? 'disabled' : ''; ?>>Approve</button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="btn btn-danger" <?php echo in_array($participant['status'], ['rejected', 'approved'], true) ? 'disabled' : ''; ?>>Reject</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mt-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Registered Events</h2>
        <?php if (count($participant_events) === 0): ?>
            <p class="text-muted mb-0">No events have been registered for this participant.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">Label</th>
                            <th scope="col" class="text-end">Fees</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($participant_events as $event): ?>
                            <tr>
                                <td><?php echo sanitize($event['event_name']); ?></td>
                                <td>
                                    <?php echo $event['event_label'] !== null && $event['event_label'] !== ''
                                        ? sanitize($event['event_label'])
                                        : '<span class="text-muted">No label</span>'; ?>
                                </td>
                                <td class="text-end">₹<?php echo number_format((float) $event['fees'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
