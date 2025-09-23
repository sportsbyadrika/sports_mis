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

$participant_id = (int) get_param('participant_id', 0);
if (!$participant_id) {
    echo '<div class="alert alert-warning">Participant not specified.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

function load_participant_with_access(mysqli $db, int $participant_id, ?int $institution_id, ?int $event_id, string $role): ?array
{
    $sql = 'SELECT p.*, i.name AS institution_name FROM participants p INNER JOIN institutions i ON i.id = p.institution_id WHERE p.id = ?';
    $params = [$participant_id];
    $types = 'i';

    if ($institution_id) {
        $sql .= ' AND p.institution_id = ?';
        $params[] = $institution_id;
        $types .= 'i';
    }

    if ($role === 'event_admin' || $role === 'event_staff') {
        $sql .= ' AND p.event_id = ?';
        $params[] = $event_id;
        $types .= 'i';
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

$participant = load_participant_with_access($db, $participant_id, $institution_id, $event_id, $role);
if (!$participant) {
    echo '<div class="alert alert-danger">Unable to load participant details or insufficient permissions.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$can_manage_events = $can_manage && $participant['status'] === 'draft';

$dob = new DateTime($participant['date_of_birth']);
$age = $dob->diff(new DateTime())->y;

if (is_post()) {
    $action = post_param('action');
    if ($action === 'add') {
        if (!$can_manage_events) {
            set_flash('error', 'You cannot modify events for this participant.');
            redirect('participant_events.php?participant_id=' . $participant_id);
        }
        $event_master_id = (int) post_param('event_master_id');
        if (!$event_master_id) {
            set_flash('error', 'Select an event to add.');
            redirect('participant_events.php?participant_id=' . $participant_id);
        }
        $stmt = $db->prepare('SELECT em.*, ac.min_age, ac.max_age FROM event_master em JOIN age_categories ac ON ac.id = em.age_category_id WHERE em.id = ? AND em.event_id = ?');
        $stmt->bind_param('ii', $event_master_id, $participant['event_id']);
        $stmt->execute();
        $event_entry = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$event_entry) {
            set_flash('error', 'Invalid event selection.');
            redirect('participant_events.php?participant_id=' . $participant_id);
        }
        if ($event_entry['gender'] !== 'Open' && $event_entry['gender'] !== $participant['gender']) {
            set_flash('error', 'Selected event is not available for this participant.');
            redirect('participant_events.php?participant_id=' . $participant_id);
        }
        if ($event_entry['min_age'] !== null && $age < (int) $event_entry['min_age']) {
            set_flash('error', 'Participant does not meet the minimum age for this event.');
            redirect('participant_events.php?participant_id=' . $participant_id);
        }
        if ($event_entry['max_age'] !== null && $age > (int) $event_entry['max_age']) {
            set_flash('error', 'Participant exceeds the maximum age for this event.');
            redirect('participant_events.php?participant_id=' . $participant_id);
        }
        $stmt = $db->prepare('SELECT id FROM participant_events WHERE participant_id = ? AND event_master_id = ?');
        $stmt->bind_param('ii', $participant_id, $event_master_id);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($exists) {
            set_flash('error', 'This event is already assigned to the participant.');
            redirect('participant_events.php?participant_id=' . $participant_id);
        }
        $stmt = $db->prepare('INSERT INTO participant_events (participant_id, event_master_id, institution_id, fees) VALUES (?, ?, ?, ?)');
        $fees = (float) $event_entry['fees'];
        $stmt->bind_param('iiid', $participant_id, $event_master_id, $participant['institution_id'], $fees);
        $stmt->execute();
        $stmt->close();
        set_flash('success', 'Event added to participant successfully.');
        redirect('participant_events.php?participant_id=' . $participant_id);
    } elseif ($action === 'delete') {
        if (!$can_manage_events) {
            set_flash('error', 'You cannot modify events for this participant.');
            redirect('participant_events.php?participant_id=' . $participant_id);
        }
        $assignment_id = (int) post_param('id');
        $stmt = $db->prepare('DELETE FROM participant_events WHERE id = ? AND participant_id = ?');
        $stmt->bind_param('ii', $assignment_id, $participant_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected) {
            set_flash('success', 'Event removed from participant.');
        } else {
            set_flash('error', 'Unable to remove the selected event.');
        }
        redirect('participant_events.php?participant_id=' . $participant_id);
    }
}

$stmt = $db->prepare('SELECT pe.id, pe.event_master_id, em.name, em.code, em.event_type, em.fees, em.label, ac.name AS age_category_name FROM participant_events pe JOIN event_master em ON em.id = pe.event_master_id JOIN age_categories ac ON ac.id = em.age_category_id WHERE pe.participant_id = ? ORDER BY em.name');
$stmt->bind_param('i', $participant_id);
$stmt->execute();
$assigned_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$assigned_ids = array_map(static fn($row) => (int) $row['event_master_id'], $assigned_events);

$stmt = $db->prepare('SELECT em.id, em.name, em.code, em.gender, em.event_type, em.fees, em.label, ac.name AS age_category_name, ac.min_age, ac.max_age FROM event_master em JOIN age_categories ac ON ac.id = em.age_category_id WHERE em.event_id = ? ORDER BY em.name');
$stmt->bind_param('i', $participant['event_id']);
$stmt->execute();
$all_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$available_events = array_filter($all_events, static function ($event) use ($participant, $age, $assigned_ids) {
    if (in_array((int) $event['id'], $assigned_ids, true)) {
        return false;
    }
    if ($event['gender'] !== 'Open' && $event['gender'] !== $participant['gender']) {
        return false;
    }
    if ($event['min_age'] !== null && $age < (int) $event['min_age']) {
        return false;
    }
    if ($event['max_age'] !== null && $age > (int) $event['max_age']) {
        return false;
    }
    return true;
});

$flash_success = get_flash('success');
$flash_error = get_flash('error');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Participant Events</h1>
        <p class="text-muted mb-0">Assign competition entries to the participant.</p>
    </div>
    <a href="participants.php" class="btn btn-outline-secondary">Back to Participants</a>
</div>
<?php if ($flash_success): ?>
    <div class="alert alert-success"><?php echo sanitize($flash_success); ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
    <div class="alert alert-danger"><?php echo sanitize($flash_error); ?></div>
<?php endif; ?>
<?php if ($participant['status'] === 'submitted'): ?>
    <div class="alert alert-info">This participant has been submitted. Event assignments are read-only.</div>
<?php endif; ?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="text-muted small">Participant</div>
                <div class="fw-semibold"><?php echo sanitize($participant['name']); ?></div>
                <div class="text-muted small">Status: <span class="badge bg-<?php echo $participant['status'] === 'submitted' ? 'success' : 'secondary'; ?> text-uppercase"><?php echo sanitize($participant['status']); ?></span></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Date of Birth</div>
                <div class="fw-semibold"><?php echo format_date($participant['date_of_birth']); ?> <span class="text-muted">(<?php echo (int) $age; ?> yrs)</span></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Gender</div>
                <div class="fw-semibold"><?php echo sanitize($participant['gender']); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Institution</div>
                <div class="fw-semibold"><?php echo sanitize($participant['institution_name']); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Aadhaar Number</div>
                <div class="fw-semibold"><?php echo sanitize($participant['aadhaar_number']); ?></div>
            </div>
        </div>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h6 mb-3">Assigned Events</h2>
        <div class="table-responsive mb-4">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Event Name</th>
                        <th>Label</th>
                        <th>Age Category</th>
                        <th>Type</th>
                        <th class="text-end">Fees</th>
                        <?php if ($can_manage_events): ?><th class="text-end">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($assigned_events as $assigned): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo sanitize($assigned['code']); ?></td>
                        <td><?php echo sanitize($assigned['name']); ?></td>
                        <td><?php echo $assigned['label'] ? sanitize($assigned['label']) : '<span class="text-muted">-</span>'; ?></td>
                        <td><?php echo sanitize($assigned['age_category_name']); ?></td>
                        <td><?php echo sanitize($assigned['event_type']); ?></td>
                        <td class="text-end">₹<?php echo number_format((float) $assigned['fees'], 2); ?></td>
                        <?php if ($can_manage_events): ?>
                            <td class="text-end">
                                <form method="post" onsubmit="return confirm('Remove this event from the participant?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $assigned['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$assigned_events): ?>
                    <tr>
                        <td colspan="<?php echo $can_manage_events ? '7' : '6'; ?>" class="text-center text-muted py-4">No events assigned yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($can_manage_events): ?>
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="add">
                <div class="col-md-9">
                    <label for="event_master_id" class="form-label">Add Event</label>
                    <select class="form-select" id="event_master_id" name="event_master_id" required>
                        <option value="">Select an event</option>
                        <?php foreach ($available_events as $option): ?>
                            <option value="<?php echo (int) $option['id']; ?>">
                                <?php echo sanitize($option['name']); ?> (<?php echo sanitize($option['code']); ?>)
                                <?php if ($option['label']): ?> - <?php echo sanitize($option['label']); ?><?php endif; ?>
                                <?php if ($option['age_category_name']): ?> - <?php echo sanitize($option['age_category_name']); ?><?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-grid">
                    <button type="submit" class="btn btn-primary" <?php echo $available_events ? '' : 'disabled'; ?>>Add Event</button>
                </div>
            </form>
            <?php if (!$available_events): ?>
                <p class="text-muted small mt-2">No additional events are available for this participant based on their age and gender.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-muted mb-0">Event assignments can only be managed while the participant is in draft status by the institution admin.</p>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
