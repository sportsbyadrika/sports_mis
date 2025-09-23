<?php
require_once __DIR__ . '/includes/header.php';
require_login();
require_role(['super_admin']);

$db = get_db_connection();
$errors = [];
$edit_event_master = null;

if (is_post()) {
    $action = post_param('action');
    if ($action === 'create' || $action === 'update') {
        $data = [
            'event_id' => (int) post_param('event_id'),
            'age_category_id' => (int) post_param('age_category_id'),
            'code' => trim((string) post_param('code', '')),
            'name' => trim((string) post_param('name', '')),
            'gender' => post_param('gender'),
            'event_type' => post_param('event_type'),
            'fees' => post_param('fees') !== null && post_param('fees') !== '' ? (float) post_param('fees') : null,
            'label' => trim((string) post_param('label', '')),
        ];

        if ($data['label'] === '') {
            $data['label'] = null;
        }

        validate_required([
            'event_id' => 'Event',
            'age_category_id' => 'Age category',
            'code' => 'Event code',
            'name' => 'Event name',
            'gender' => 'Gender',
            'event_type' => 'Event type',
        ], $errors, $data);

        if ($data['fees'] === null || $data['fees'] < 0) {
            $errors['fees'] = 'Fees must be zero or a positive amount.';
        }

        if ($data['gender'] && !in_array($data['gender'], ['Male', 'Female', 'Open'], true)) {
            $errors['gender'] = 'Invalid gender selection.';
        }

        if ($data['event_type'] && !in_array($data['event_type'], ['Individual', 'Team', 'Institution'], true)) {
            $errors['event_type'] = 'Invalid event type selection.';
        }

        if (!$errors) {
            if ($action === 'create') {
                $stmt = $db->prepare('INSERT INTO event_master (event_id, age_category_id, code, name, gender, event_type, fees, label) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('iissssds', $data['event_id'], $data['age_category_id'], $data['code'], $data['name'], $data['gender'], $data['event_type'], $data['fees'], $data['label']);
                if ($stmt->execute()) {
                    set_flash('success', 'Event entry created successfully.');
                    $stmt->close();
                    redirect('event_master.php');
                }
                if ($stmt->errno === 1062) {
                    $errors['code'] = 'This event code is already in use for the selected event.';
                } else {
                    $errors['general'] = 'Unable to save event entry. Please try again.';
                }
                $stmt->close();
            } else {
                $event_master_id = (int) post_param('id');
                $stmt = $db->prepare('UPDATE event_master SET event_id = ?, age_category_id = ?, code = ?, name = ?, gender = ?, event_type = ?, fees = ?, label = ?, updated_at = NOW() WHERE id = ?');
                $stmt->bind_param('iissssdsi', $data['event_id'], $data['age_category_id'], $data['code'], $data['name'], $data['gender'], $data['event_type'], $data['fees'], $data['label'], $event_master_id);
                if ($stmt->execute()) {
                    set_flash('success', 'Event entry updated successfully.');
                    $stmt->close();
                    redirect('event_master.php');
                }
                if ($stmt->errno === 1062) {
                    $errors['code'] = 'This event code is already in use for the selected event.';
                } else {
                    $errors['general'] = 'Unable to update event entry. Please try again.';
                }
                $stmt->close();
            }
        }

        $edit_event_master = $data;
        $edit_event_master['id'] = (int) post_param('id');
    } elseif ($action === 'delete') {
        $event_master_id = (int) post_param('id');
        $stmt = $db->prepare('DELETE FROM event_master WHERE id = ?');
        $stmt->bind_param('i', $event_master_id);
        if ($stmt->execute()) {
            set_flash('success', 'Event entry removed.');
        } else {
            set_flash('error', 'Unable to delete event entry. Remove linked participant events first.');
        }
        $stmt->close();
        redirect('event_master.php');
    }
}

if (!$edit_event_master && ($edit_id = (int) get_param('edit', 0))) {
    $stmt = $db->prepare('SELECT * FROM event_master WHERE id = ?');
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $edit_event_master = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$events_result = $db->query('SELECT id, name FROM events ORDER BY name');
$events = $events_result ? $events_result->fetch_all(MYSQLI_ASSOC) : [];
$events_result?->close();

$age_result = $db->query('SELECT id, name FROM age_categories ORDER BY name');
$age_categories = $age_result ? $age_result->fetch_all(MYSQLI_ASSOC) : [];
$age_result?->close();

$stmt = $db->prepare('SELECT em.*, e.name AS event_name, ac.name AS age_category_name FROM event_master em JOIN events e ON e.id = em.event_id JOIN age_categories ac ON ac.id = em.age_category_id ORDER BY e.name, em.name');
$stmt->execute();
$event_entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flash_success = get_flash('success');
$flash_error = get_flash('error');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Event Master</h1>
        <p class="text-muted mb-0">Define the individual competitions available within each event.</p>
    </div>
</div>
<?php if ($flash_success): ?>
    <div class="alert alert-success"><?php echo sanitize($flash_success); ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
    <div class="alert alert-danger"><?php echo sanitize($flash_error); ?></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><?php echo $edit_event_master ? 'Edit Event Entry' : 'Add Event Entry'; ?></h2>
            </div>
            <div class="card-body">
                <?php if (isset($errors['general'])): ?>
                    <div class="alert alert-danger"><?php echo sanitize($errors['general']); ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="<?php echo $edit_event_master ? 'update' : 'create'; ?>">
                    <input type="hidden" name="id" value="<?php echo (int) ($edit_event_master['id'] ?? 0); ?>">
                    <div class="mb-3">
                        <label for="event_id" class="form-label">Event</label>
                        <select class="form-select <?php echo isset($errors['event_id']) ? 'is-invalid' : ''; ?>" id="event_id" name="event_id" required>
                            <option value="">Select Event</option>
                            <?php foreach ($events as $event): ?>
                                <option value="<?php echo (int) $event['id']; ?>" <?php echo isset($edit_event_master['event_id']) && (int) $edit_event_master['event_id'] === (int) $event['id'] ? 'selected' : ''; ?>><?php echo sanitize($event['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['event_id'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['event_id']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="age_category_id" class="form-label">Age Category</label>
                        <select class="form-select <?php echo isset($errors['age_category_id']) ? 'is-invalid' : ''; ?>" id="age_category_id" name="age_category_id" required>
                            <option value="">Select Age Category</option>
                            <?php foreach ($age_categories as $category): ?>
                                <option value="<?php echo (int) $category['id']; ?>" <?php echo isset($edit_event_master['age_category_id']) && (int) $edit_event_master['age_category_id'] === (int) $category['id'] ? 'selected' : ''; ?>><?php echo sanitize($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['age_category_id'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['age_category_id']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="code" class="form-label">Event Code</label>
                        <input type="text" class="form-control <?php echo isset($errors['code']) ? 'is-invalid' : ''; ?>" id="code" name="code" value="<?php echo sanitize($edit_event_master['code'] ?? ''); ?>" required>
                        <?php if (isset($errors['code'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['code']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="name" class="form-label">Event Name</label>
                        <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" id="name" name="name" value="<?php echo sanitize($edit_event_master['name'] ?? ''); ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['name']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="gender" class="form-label">Gender</label>
                        <select class="form-select <?php echo isset($errors['gender']) ? 'is-invalid' : ''; ?>" id="gender" name="gender" required>
                            <option value="">Select Gender</option>
                            <?php foreach (['Male', 'Female', 'Open'] as $gender): ?>
                                <option value="<?php echo $gender; ?>" <?php echo isset($edit_event_master['gender']) && $edit_event_master['gender'] === $gender ? 'selected' : ''; ?>><?php echo $gender; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['gender'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['gender']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="event_type" class="form-label">Event Type</label>
                        <select class="form-select <?php echo isset($errors['event_type']) ? 'is-invalid' : ''; ?>" id="event_type" name="event_type" required>
                            <option value="">Select Type</option>
                            <?php foreach (['Individual', 'Team', 'Institution'] as $type): ?>
                                <option value="<?php echo $type; ?>" <?php echo isset($edit_event_master['event_type']) && $edit_event_master['event_type'] === $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['event_type'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['event_type']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="fees" class="form-label">Event Fees</label>
                        <input type="number" step="0.01" min="0" class="form-control <?php echo isset($errors['fees']) ? 'is-invalid' : ''; ?>" id="fees" name="fees" value="<?php echo isset($edit_event_master['fees']) && $edit_event_master['fees'] !== null ? (float) $edit_event_master['fees'] : '0'; ?>" required>
                        <?php if (isset($errors['fees'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['fees']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="label" class="form-label">Event Label</label>
                        <input type="text" class="form-control" id="label" name="label" value="<?php echo sanitize($edit_event_master['label'] ?? ''); ?>" placeholder="Optional label or category">
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary"><?php echo $edit_event_master ? 'Update Event Entry' : 'Create Event Entry'; ?></button>
                        <?php if ($edit_event_master): ?>
                            <a href="event_master.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Event</th>
                                <th>Label</th>
                                <th>Gender</th>
                                <th>Age Category</th>
                                <th>Type</th>
                                <th class="text-end">Fees</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($event_entries as $entry): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo sanitize($entry['code']); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo sanitize($entry['name']); ?></div>
                                    <div class="text-muted small">Event: <?php echo sanitize($entry['event_name']); ?></div>
                                </td>
                                <td><?php echo $entry['label'] ? sanitize($entry['label']) : '<span class="text-muted">-</span>'; ?></td>
                                <td><?php echo sanitize($entry['gender']); ?></td>
                                <td><?php echo sanitize($entry['age_category_name']); ?></td>
                                <td><?php echo sanitize($entry['event_type']); ?></td>
                                <td class="text-end">₹<?php echo number_format((float) $entry['fees'], 2); ?></td>
                                <td class="text-end">
                                    <div class="table-actions justify-content-end">
                                        <a href="event_master.php?edit=<?php echo (int) $entry['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                        <form method="post" onsubmit="return confirm('Delete this event entry?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $entry['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$event_entries): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No event entries found.</td>
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
