<?php
require_once __DIR__ . '/includes/header.php';
require_login();
require_role(['institution_admin', 'event_admin', 'event_staff', 'super_admin']);

$user = current_user();
$db = get_db_connection();
$role = $user['role'];
$can_manage = $role === 'institution_admin';

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

$status_filter = get_param('status');
$search = trim((string) get_param('q', ''));

function fetch_participant(mysqli $db, int $id, ?int $institution_id): ?array
{
    $sql = 'SELECT * FROM participants WHERE id = ?';
    if ($institution_id) {
        $sql .= ' AND institution_id = ?';
    }
    $stmt = $db->prepare($sql);
    if ($institution_id) {
        $stmt->bind_param('ii', $id, $institution_id);
    } else {
        $stmt->bind_param('i', $id);
    }
    $stmt->execute();
    $participant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $participant ?: null;
}

if (is_post() && $can_manage) {
    $action = post_param('action');
    if ($action === 'delete') {
        $participant_id = (int) post_param('id');
        $participant = fetch_participant($db, $participant_id, $institution_id);
        if ($participant && $participant['status'] !== 'submitted') {
            if (!empty($participant['photo_path'])) {
                $photo_file = __DIR__ . '/' . ltrim($participant['photo_path'], '/');
                if (is_file($photo_file)) {
                    @unlink($photo_file);
                }
            }
            $stmt = $db->prepare('DELETE FROM participants WHERE id = ?');
            $stmt->bind_param('i', $participant_id);
            $stmt->execute();
            $stmt->close();
            set_flash('success', 'Participant removed.');
        } else {
            set_flash('error', 'Unable to delete participant.');
        }
        redirect('participants.php');
    } elseif ($action === 'submit') {
        $participant_id = (int) post_param('id');
        $participant = fetch_participant($db, $participant_id, $institution_id);
        if ($participant && $participant['status'] === 'draft') {
            $stmt = $db->prepare("UPDATE participants SET status = 'submitted', submitted_at = NOW(), submitted_by = ? WHERE id = ?");
            $stmt->bind_param('ii', $user['id'], $participant_id);
            $stmt->execute();
            $stmt->close();
            set_flash('success', 'Participant submitted successfully.');
        } else {
            set_flash('error', 'Participant cannot be submitted.');
        }
        redirect('participants.php');
    }
}

$statsSubquery = "SELECT pe.participant_id, COUNT(*) AS total_events, COALESCE(SUM(pe.fees), 0) AS total_fees\n    FROM participant_events pe\n    INNER JOIN event_master em ON em.id = pe.event_master_id AND em.event_type = 'Individual'\n    GROUP BY pe.participant_id";
$sql = "SELECT p.id, p.institution_id, p.event_id, p.name, p.email, p.date_of_birth, p.gender, p.guardian_name, p.contact_number, p.status, p.chest_number, i.name AS institution_name, COALESCE(stats.total_events, 0) AS total_events, COALESCE(stats.total_fees, 0) AS total_fees FROM participants p LEFT JOIN institutions i ON i.id = p.institution_id LEFT JOIN ($statsSubquery) stats ON stats.participant_id = p.id";
$conditions = [];
$params = [];
$types = '';

if ($event_id) {
    $conditions[] = 'p.event_id = ?';
    $params[] = $event_id;
    $types .= 'i';
}
if ($institution_id) {
    $conditions[] = 'p.institution_id = ?';
    $params[] = $institution_id;
    $types .= 'i';
}
if ($status_filter && in_array($status_filter, ['draft', 'submitted'], true)) {
    $conditions[] = 'p.status = ?';
    $params[] = $status_filter;
    $types .= 's';
}
if ($search) {
    $conditions[] = '(p.name LIKE ? OR p.email LIKE ? OR p.guardian_name LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY p.name';

$stmt = $db->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$age_categories = fetch_age_categories($db);

foreach ($participants as &$participant) {
    $participant['age'] = calculate_age($participant['date_of_birth']);
    $participant['age_category_label'] = determine_age_category_label($participant['age'], $age_categories);
}
unset($participant);

$total_events_sum = 0;
$total_fees_sum = 0.0;
foreach ($participants as $participant) {
    $total_events_sum += (int) $participant['total_events'];
    $total_fees_sum += (float) $participant['total_fees'];
}

$flash_success = get_flash('success');
$flash_error = get_flash('error');
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-0">Participants</h1>
        <p class="text-muted mb-0">Manage participant registrations for the event.</p>
    </div>
    <div class="d-flex flex-column flex-md-row align-items-md-center gap-2">
        <?php if ($role === 'institution_admin'): ?>
            <a href="institution_approved_report.php" class="btn btn-outline-primary" target="_blank">Approved Participants Report</a>
        <?php endif; ?>
        <?php if ($can_manage): ?>
            <a href="participant_form.php" class="btn btn-primary">Add Participant</a>
        <?php endif; ?>
        <form method="get" class="d-flex gap-2">
            <?php if ($role === 'event_admin' || $role === 'event_staff' || $role === 'super_admin'): ?>
                <?php if ($role !== 'institution_admin'): ?>
                    <?php if ($role === 'super_admin'): ?>
                        <input type="number" class="form-control" name="event_id" placeholder="Event ID" value="<?php echo $event_id ? (int) $event_id : ''; ?>">
                    <?php else: ?>
                        <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>">
                    <?php endif; ?>
                    <input type="number" class="form-control" name="institution_id" placeholder="Institution ID" value="<?php echo $institution_id ? (int) $institution_id : ''; ?>">
                <?php endif; ?>
            <?php endif; ?>
            <select name="status" class="form-select">
                <option value="">All Statuses</option>
                <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                <option value="submitted" <?php echo $status_filter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
            </select>
            <input type="text" class="form-control" name="q" placeholder="Search participants" value="<?php echo sanitize($search); ?>">
            <button class="btn btn-outline-primary" type="submit">Filter</button>
        </form>
    </div>
</div>
<?php if ($flash_success): ?>
    <div class="alert alert-success"><?php echo sanitize($flash_success); ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
    <div class="alert alert-danger"><?php echo sanitize($flash_error); ?></div>
<?php endif; ?>
<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>DOB</th>
                        <th>Gender</th>
                        <th>Guardian</th>
                        <th>Contact</th>
                        <th class="text-center">Total Events</th>
                        <th class="text-end">Total Fees</th>
                        <th>Status</th>
                        <th class="text-center">Chest No.</th>
                        <?php if ($role !== 'institution_admin'): ?><th>Institution</th><?php endif; ?>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($participants as $index => $participant): ?>
                    <tr>
                        <td class="text-muted small"><?php echo $index + 1; ?></td>
                        <td>
                            <div class="fw-semibold"><?php echo sanitize($participant['name']); ?></div>
                            <div class="text-muted small"><?php echo sanitize($participant['email']); ?></div>
                        </td>
                        <td>
                            <div class="fw-semibold"><?php echo format_date($participant['date_of_birth']); ?></div>
                            <?php if ($participant['age'] !== null): ?>
                                <div class="text-muted small">Age: <?php echo (int) $participant['age']; ?></div>
                            <?php endif; ?>
                            <?php if ($participant['age_category_label']): ?>
                                <div class="text-muted small"><?php echo sanitize($participant['age_category_label']); ?></div>
                            <?php else: ?>
                                <div class="text-muted small text-nowrap text-secondary">No age category</div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo sanitize($participant['gender']); ?></td>
                        <td><?php echo sanitize($participant['guardian_name']); ?></td>
                        <td><?php echo sanitize($participant['contact_number']); ?></td>
                        <td class="text-center"><?php echo (int) $participant['total_events']; ?></td>
                        <td class="text-end">₹<?php echo number_format((float) $participant['total_fees'], 2); ?></td>
                        <td>
                            <?php $status_class = in_array($participant['status'], ['submitted', 'approved'], true) ? 'success' : 'secondary'; ?>
                            <span class="badge bg-<?php echo $status_class; ?> text-uppercase"><?php echo sanitize($participant['status']); ?></span>
                        </td>
                        <td class="text-center">
                            <?php if ($participant['status'] === 'approved' && $participant['chest_number']): ?>
                                <span class="fw-semibold"><?php echo sanitize((string) $participant['chest_number']); ?></span>
                            <?php else: ?>
                                <span class="text-muted small">Pending</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($role !== 'institution_admin'): ?><td><?php echo sanitize($participant['institution_name']); ?></td><?php endif; ?>
                        <td class="text-end">
                            <div class="table-actions justify-content-end flex-wrap gap-1">
                                <a href="participant_events.php?participant_id=<?php echo (int) $participant['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Manage Events">
                                    <i class="bi bi-calendar-event"></i>
                                </a>
                                <a href="participant_view.php?id=<?php echo (int) $participant['id']; ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                <?php if ($can_manage && $participant['status'] === 'draft'): ?>
                                    <a href="participant_form.php?id=<?php echo (int) $participant['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                    <form method="post" onsubmit="return confirm('Delete this participant?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int) $participant['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Submit this participant? After submission edits are locked.');">
                                        <input type="hidden" name="action" value="submit">
                                        <input type="hidden" name="id" value="<?php echo (int) $participant['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-send"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($participants): ?>
                    <tr class="fw-semibold">
                        <td colspan="6" class="text-end">Total</td>
                        <td class="text-center"><?php echo (int) $total_events_sum; ?></td>
                        <td class="text-end">₹<?php echo number_format($total_fees_sum, 2); ?></td>
                        <td colspan="<?php echo $role !== 'institution_admin' ? '4' : '3'; ?>"></td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td colspan="<?php echo $role !== 'institution_admin' ? '12' : '11'; ?>" class="text-center text-muted py-4">No participants found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
