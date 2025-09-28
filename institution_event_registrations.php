<?php
$page_title = 'Institution Event Registrations';
require_once __DIR__ . '/includes/header.php';

require_login();
require_role(['institution_admin', 'event_admin', 'event_staff', 'super_admin']);

$user = current_user();
$db = get_db_connection();
$role = $user['role'];

$institution_id = null;
$event_id = null;
$institution_context = null;

$redirect_params = [];

if ($role === 'institution_admin') {
    if (!$user['institution_id']) {
        echo '<div class="alert alert-warning">No institution assigned to your account. Please contact the event administrator.</div>';
        include __DIR__ . '/includes/footer.php';
        return;
    }

    $institution_id = (int) $user['institution_id'];
    $stmt = $db->prepare('SELECT i.id, i.name, i.event_id, e.name AS event_name FROM institutions i JOIN events e ON e.id = i.event_id WHERE i.id = ? LIMIT 1');
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
} elseif (in_array($role, ['event_admin', 'event_staff'], true)) {
    if (!$user['event_id']) {
        echo '<div class="alert alert-warning">No event assigned to your account. Please contact the super administrator.</div>';
        include __DIR__ . '/includes/footer.php';
        return;
    }

    $event_id = (int) $user['event_id'];

    $institution_id = (int) get_param('institution_id', 0);
    if ($institution_id) {
        $stmt = $db->prepare('SELECT i.id, i.name, i.event_id, e.name AS event_name FROM institutions i JOIN events e ON e.id = i.event_id WHERE i.id = ? AND i.event_id = ? LIMIT 1');
        $stmt->bind_param('ii', $institution_id, $event_id);
        $stmt->execute();
        $institution_context = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$institution_context) {
            echo '<div class="alert alert-danger">Invalid institution selected for your event.</div>';
            include __DIR__ . '/includes/footer.php';
            return;
        }
    }
} else { // super_admin
    $institution_id = (int) get_param('institution_id', 0);
    if ($institution_id) {
        $stmt = $db->prepare('SELECT i.id, i.name, i.event_id, e.name AS event_name FROM institutions i JOIN events e ON e.id = i.event_id WHERE i.id = ? LIMIT 1');
        $stmt->bind_param('i', $institution_id);
        $stmt->execute();
        $institution_context = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($institution_context) {
            $event_id = (int) $institution_context['event_id'];
        }
    }
}

if ($institution_context && $role !== 'institution_admin') {
    $redirect_params['institution_id'] = $institution_context['id'];
}

$redirect_url = 'institution_event_registrations.php' . ($redirect_params ? '?' . http_build_query($redirect_params) : '');

$institution_options = [];

if (in_array($role, ['event_admin', 'event_staff'], true)) {
    $stmt = $db->prepare('SELECT id, name FROM institutions WHERE event_id = ? ORDER BY name');
    $stmt->bind_param('i', $event_id);
    $stmt->execute();
    $institution_options = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} elseif ($role === 'super_admin') {
    $result = $db->query('SELECT i.id, i.name, e.name AS event_name FROM institutions i JOIN events e ON e.id = i.event_id ORDER BY e.name, i.name');
    if ($result) {
        $institution_options = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
}

if ((in_array($role, ['event_admin', 'event_staff'], true) || $role === 'super_admin') && !$institution_context) {
    echo '<div class="mb-4">';
    echo '<h1 class="h4 mb-2">Institution Event Registrations</h1>';
    echo '<p class="text-muted mb-3">Select an institution to review and manage its event registrations.</p>';
    if ($institution_options) {
        echo '<form method="get" class="card card-body shadow-sm">';
        echo '<div class="mb-3">';
        echo '<label class="form-label" for="institution_id">Institution</label>';
        echo '<select name="institution_id" id="institution_id" class="form-select">';
        echo '<option value="">-- Select Institution --</option>';
        foreach ($institution_options as $option) {
            $label = sanitize($option['name']);
            if (isset($option['event_name'])) {
                $label .= ' (' . sanitize($option['event_name']) . ')';
            }
            echo '<option value="' . (int) $option['id'] . '">' . $label . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<button class="btn btn-primary" type="submit">Manage Registrations</button>';
        echo '</form>';
    } else {
        echo '<div class="alert alert-info">No institutions found for your account.</div>';
    }
    include __DIR__ . '/includes/footer.php';
    return;
}

if (!$institution_context) {
    echo '<div class="alert alert-danger">Unable to determine the institution context for managing registrations.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

if (is_post()) {
    $action = post_param('action');

    if ($action === 'add') {
        if (!in_array($role, ['institution_admin', 'super_admin'], true)) {
            set_flash('error', 'You do not have permission to add registrations.');
            redirect($redirect_url);
        }

        $event_master_id = (int) post_param('event_master_id');
        if (!$event_master_id) {
            set_flash('error', 'Select an event to register.');
            redirect($redirect_url);
        }

        $stmt = $db->prepare("SELECT id FROM event_master WHERE id = ? AND event_id = ? AND event_type = 'Institution'");
        $stmt->bind_param('ii', $event_master_id, $event_id);
        $stmt->execute();
        $event_entry = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$event_entry) {
            set_flash('error', 'Invalid institution-level event selected.');
            redirect($redirect_url);
        }

        $stmt = $db->prepare('SELECT id FROM institution_event_registrations WHERE institution_id = ? AND event_master_id = ?');
        $stmt->bind_param('ii', $institution_context['id'], $event_master_id);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($exists) {
            set_flash('error', 'This institution is already registered for the selected event.');
            redirect($redirect_url);
        }

        $submitted_by = (int) ($user['id'] ?? 0);
        $stmt = $db->prepare('INSERT INTO institution_event_registrations (institution_id, event_master_id, status, submitted_by) VALUES (?, ?, "pending", ?)');
        $stmt->bind_param('iii', $institution_context['id'], $event_master_id, $submitted_by);
        $stmt->execute();
        $stmt->close();

        set_flash('success', 'Institution registered for the event successfully.');
        redirect($redirect_url);
    } elseif ($action === 'delete') {
        if (!in_array($role, ['institution_admin', 'super_admin'], true)) {
            set_flash('error', 'You do not have permission to remove registrations.');
            redirect($redirect_url);
        }

        $registration_id = (int) post_param('id');
        $stmt = $db->prepare("DELETE FROM institution_event_registrations WHERE id = ? AND institution_id = ? AND status IN ('pending', 'rejected')");
        $stmt->bind_param('ii', $registration_id, $institution_context['id']);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected) {
            set_flash('success', 'Registration removed successfully.');
        } else {
            set_flash('error', 'Unable to remove the selected registration. Approved registrations cannot be removed.');
        }
        redirect($redirect_url);
    } elseif ($action === 'update_status') {
        if (!in_array($role, ['event_staff', 'super_admin'], true)) {
            set_flash('error', 'You do not have permission to update approval status.');
            redirect($redirect_url);
        }

        $registration_id = (int) post_param('id');
        $status = post_param('status');
        $allowed_statuses = ['pending', 'approved', 'rejected'];
        if (!in_array($status, $allowed_statuses, true)) {
            set_flash('error', 'Invalid status selected.');
            redirect($redirect_url);
        }

        $stmt = $db->prepare("SELECT ier.id FROM institution_event_registrations ier JOIN event_master em ON em.id = ier.event_master_id WHERE ier.id = ? AND ier.institution_id = ? AND em.event_id = ? AND em.event_type = 'Institution'");
        $stmt->bind_param('iii', $registration_id, $institution_context['id'], $event_id);
        $stmt->execute();
        $registration = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$registration) {
            set_flash('error', 'Unable to locate the registration for updating.');
            redirect($redirect_url);
        }

        if ($status === 'pending') {
            $stmt = $db->prepare('UPDATE institution_event_registrations SET status = ?, reviewed_by = NULL, reviewed_at = NULL WHERE id = ?');
            $stmt->bind_param('si', $status, $registration_id);
        } else {
            $stmt = $db->prepare('UPDATE institution_event_registrations SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?');
            $reviewed_by = (int) $user['id'];
            $stmt->bind_param('sii', $status, $reviewed_by, $registration_id);
        }

        $stmt->execute();
        $stmt->close();

        set_flash('success', 'Registration status updated successfully.');
        redirect($redirect_url);
    }
}

$stmt = $db->prepare('SELECT ier.id, ier.event_master_id, ier.status, ier.submitted_at, ier.reviewed_at, em.name, em.code, em.label, em.fees, u1.name AS submitted_by_name, u2.name AS reviewed_by_name
    FROM institution_event_registrations ier
    JOIN event_master em ON em.id = ier.event_master_id
    LEFT JOIN users u1 ON u1.id = ier.submitted_by
    LEFT JOIN users u2 ON u2.id = ier.reviewed_by
    WHERE ier.institution_id = ? AND em.event_type = "Institution"
    ORDER BY em.name');
$stmt->bind_param('i', $institution_context['id']);
$stmt->execute();
$registrations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $db->prepare("SELECT id, code, name, label, fees FROM event_master WHERE event_id = ? AND event_type = 'Institution' ORDER BY name");
$stmt->bind_param('i', $event_id);
$stmt->execute();
$institution_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$registered_event_ids = array_map(static fn($row) => (int) $row['event_master_id'], $registrations);
$available_events = array_filter($institution_events, static function ($event) use ($registered_event_ids) {
    return !in_array((int) $event['id'], $registered_event_ids, true);
});

$success_message = get_flash('success');
$error_message = get_flash('error');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Institution Event Registrations</h1>
        <p class="text-muted mb-0">Manage registrations for institution-level events for <?php echo sanitize($institution_context['name']); ?>.</p>
    </div>
    <div class="text-end">
        <div class="text-muted small">Event: <?php echo sanitize($institution_context['event_name']); ?></div>
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
    <?php if (in_array($role, ['institution_admin', 'super_admin'], true)): ?>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0">Register for an Institution Event</h2>
            </div>
            <div class="card-body">
                <?php if ($available_events): ?>
                    <form method="post">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="form-label" for="event_master_id">Institution Event</label>
                            <select class="form-select" id="event_master_id" name="event_master_id" required>
                                <option value="">-- Select Institution Event --</option>
                                <?php foreach ($available_events as $event): ?>
                                    <option value="<?php echo (int) $event['id']; ?>">
                                        <?php echo sanitize($event['name']); ?>
                                        <?php if (!empty($event['label'])): ?>
                                            (<?php echo sanitize($event['label']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Submit Registration</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0">All institution-level events are already registered.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-lg-<?php echo in_array($role, ['institution_admin', 'super_admin'], true) ? '8' : '12'; ?>">
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Registered Institution Events</h2>
                <?php if (in_array($role, ['event_staff', 'super_admin'], true)): ?>
                    <span class="badge bg-secondary">Approver Mode</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>Reviewed</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($registrations as $registration): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo sanitize($registration['name']); ?></div>
                                    <div class="text-muted small"><?php echo sanitize($registration['code']); ?><?php if (!empty($registration['label'])): ?> &middot; <?php echo sanitize($registration['label']); ?><?php endif; ?></div>
                                </td>
                                <td>
                                    <?php
                                    $status = $registration['status'];
                                    $badge_class = match ($status) {
                                        'approved' => 'bg-success',
                                        'rejected' => 'bg-danger',
                                        default => 'bg-warning text-dark',
                                    };
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?> text-uppercase"><?php echo sanitize(strtoupper($status)); ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($registration['submitted_at'])): ?>
                                        <div class="small text-muted"><?php echo sanitize(date('d M Y H:i', strtotime($registration['submitted_at']))); ?></div>
                                    <?php else: ?>
                                        <span class="text-muted small">--</span>
                                    <?php endif; ?>
                                    <?php if (!empty($registration['submitted_by_name'])): ?>
                                        <div class="small">by <?php echo sanitize($registration['submitted_by_name']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($registration['reviewed_at'])): ?>
                                        <div class="small text-muted"><?php echo sanitize(date('d M Y H:i', strtotime($registration['reviewed_at']))); ?></div>
                                        <?php if (!empty($registration['reviewed_by_name'])): ?>
                                            <div class="small">by <?php echo sanitize($registration['reviewed_by_name']); ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">--</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="table-actions justify-content-end">
                                        <?php if (in_array($role, ['institution_admin', 'super_admin'], true) && in_array($registration['status'], ['pending', 'rejected'], true)): ?>
                                            <form method="post" onsubmit="return confirm('Remove this registration request?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int) $registration['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (in_array($role, ['event_staff', 'super_admin'], true)): ?>
                                            <form method="post" class="d-flex align-items-center gap-2">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="id" value="<?php echo (int) $registration['id']; ?>">
                                                <select name="status" class="form-select form-select-sm">
                                                    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $value => $label): ?>
                                                        <option value="<?php echo $value; ?>" <?php echo $registration['status'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$registrations): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">No institution event registrations yet.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
