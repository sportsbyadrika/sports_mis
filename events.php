<?php
require_once __DIR__ . '/includes/header.php';
require_login();
require_role(['super_admin']);

$db = get_db_connection();
$errors = [];
$edit_event = null;
$search = trim((string) get_param('q', ''));

if (is_post()) {
    $action = post_param('action');
    if ($action === 'create' || $action === 'update') {
        $data = [
            'name' => trim((string) post_param('name', '')),
            'description' => trim((string) post_param('description', '')),
            'location' => trim((string) post_param('location', '')),
            'start_date' => post_param('start_date') ?: null,
            'end_date' => post_param('end_date') ?: null,
        ];

        validate_required(['name' => 'Event name'], $errors, $data);

        if (!$errors) {
            if ($action === 'create') {
                $stmt = $db->prepare('INSERT INTO events (name, description, location, start_date, end_date, created_by) VALUES (?, ?, ?, ?, ?, ?)');
                $created_by = current_user()['id'];
                $stmt->bind_param('sssssi', $data['name'], $data['description'], $data['location'], $data['start_date'], $data['end_date'], $created_by);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Event created successfully.');
            } else {
                $event_id = (int) post_param('id');
                $stmt = $db->prepare('UPDATE events SET name = ?, description = ?, location = ?, start_date = ?, end_date = ? WHERE id = ?');
                $stmt->bind_param('sssssi', $data['name'], $data['description'], $data['location'], $data['start_date'], $data['end_date'], $event_id);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Event updated successfully.');
            }
            redirect('events.php');
        }

        $edit_event = $data;
        $edit_event['id'] = (int) post_param('id');
    } elseif ($action === 'delete') {
        $event_id = (int) post_param('id');
        $stmt = $db->prepare('DELETE FROM events WHERE id = ?');
        $stmt->bind_param('i', $event_id);
        $stmt->execute();
        $stmt->close();
        set_flash('success', 'Event deleted.');
        redirect('events.php');
    }
}

if (!$edit_event && ($edit_id = (int) get_param('edit', 0))) {
    $stmt = $db->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_event = $result->fetch_assoc();
    $stmt->close();
}

$params = [];
$sql = 'SELECT e.*, 
        (SELECT COUNT(*) FROM institutions i WHERE i.event_id = e.id) AS institution_count,
        (SELECT COUNT(*) FROM participants p WHERE p.event_id = e.id) AS participant_count,
        (SELECT COUNT(*) FROM institution_event_registrations ier JOIN event_master em2 ON em2.id = ier.event_master_id WHERE em2.event_id = e.id) AS institution_event_registration_count,
        (SELECT COUNT(*) FROM institution_event_registrations ier JOIN event_master em2 ON em2.id = ier.event_master_id WHERE em2.event_id = e.id AND ier.status = \'pending\') AS pending_institution_registration_count
        FROM events e';
if ($search) {
    $sql .= ' WHERE e.name LIKE ? OR e.location LIKE ?';
    $like = '%' . $search . '%';
    $params = [$like, $like];
}
$sql .= ' ORDER BY e.start_date DESC, e.name';

$stmt = $db->prepare($sql);
if ($search) {
    $stmt->bind_param('ss', ...$params);
}
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flash = get_flash('success');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Events</h1>
        <p class="text-muted mb-0">Manage all sports events in the system.</p>
    </div>
</div>
<?php if ($flash): ?>
    <div class="alert alert-success"><?php echo sanitize($flash); ?></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><?php echo $edit_event ? 'Edit Event' : 'Create Event'; ?></h2>
            </div>
            <div class="card-body">
                <form method="post" novalidate>
                    <input type="hidden" name="action" value="<?php echo $edit_event ? 'update' : 'create'; ?>">
                    <input type="hidden" name="id" value="<?php echo (int) ($edit_event['id'] ?? 0); ?>">
                    <div class="mb-3">
                        <label for="name" class="form-label">Event Name</label>
                        <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" id="name" name="name" value="<?php echo sanitize($edit_event['name'] ?? ''); ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['name']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" class="form-control" id="location" name="location" value="<?php echo sanitize($edit_event['location'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label for="start_date" class="form-label">Start Date</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo sanitize($edit_event['start_date'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label for="end_date" class="form-label">End Date</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo sanitize($edit_event['end_date'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"><?php echo sanitize($edit_event['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary"><?php echo $edit_event ? 'Update Event' : 'Create Event'; ?></button>
                        <?php if ($edit_event): ?>
                            <a href="events.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <form class="row g-2 align-items-center mb-3" method="get">
                    <div class="col-sm-8">
                        <input type="text" name="q" class="form-control" placeholder="Search by name or location" value="<?php echo sanitize($search); ?>">
                    </div>
                    <div class="col-sm-4 d-flex gap-2">
                        <button type="submit" class="btn btn-outline-primary w-100">Search</button>
                        <a href="events.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Location</th>
                                <th>Dates</th>
                                <th>Institutions</th>
                                <th>Participants</th>
                                <th>Institution Events</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo sanitize($event['name']); ?></div>
                                    <div class="text-muted small"><?php echo sanitize($event['description']); ?></div>
                                </td>
                                <td><?php echo sanitize($event['location']); ?></td>
                                <td>
                                    <?php echo format_date($event['start_date']); ?>
                                    <?php if ($event['end_date']): ?>
                                        <span class="text-muted">-</span> <?php echo format_date($event['end_date']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int) $event['institution_count']; ?></td>
                                <td><?php echo (int) $event['participant_count']; ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo (int) $event['institution_event_registration_count']; ?></div>
                                    <div class="text-muted small">Pending: <?php echo (int) $event['pending_institution_registration_count']; ?></div>
                                </td>
                                <td class="text-end">
                                    <div class="table-actions justify-content-end">
                                        <a href="events.php?edit=<?php echo (int) $event['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                        <form method="post" onsubmit="return confirm('Delete this event?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $event['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$events): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">No events found.</td>
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
