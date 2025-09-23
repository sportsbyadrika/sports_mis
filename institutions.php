<?php
require_once __DIR__ . '/includes/header.php';
require_login();
require_role(['event_admin', 'event_staff', 'super_admin']);

$user = current_user();
$db = get_db_connection();
$can_manage = $user['role'] === 'event_admin' || $user['role'] === 'super_admin';

$event_id = null;
if ($user['role'] === 'event_admin' || $user['role'] === 'event_staff') {
    if (!$user['event_id']) {
        echo '<div class="alert alert-warning">No event assigned to your account. Please contact the super administrator.</div>';
        include __DIR__ . '/includes/footer.php';
        return;
    }
    $event_id = (int) $user['event_id'];
} else {
    $event_id = (int) get_param('event_id', 0) ?: null;
}

$errors = [];
$edit_institution = null;
$search = trim((string) get_param('q', ''));
$all_events = [];
if ($user['role'] === 'super_admin') {
    $result = $db->query('SELECT id, name FROM events ORDER BY name');
    $all_events = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
}

if (is_post()) {
    if (!$can_manage) {
        redirect('institutions.php');
    }
    $action = post_param('action');
    if ($action === 'create' || $action === 'update') {
        $data = [
            'name' => trim((string) post_param('name', '')),
            'spoc_name' => trim((string) post_param('spoc_name', '')),
            'designation' => trim((string) post_param('designation', '')),
            'contact_number' => trim((string) post_param('contact_number', '')),
            'address' => trim((string) post_param('address', '')),
            'event_id' => $event_id ?: (int) post_param('event_id'),
        ];

        validate_required(['name' => 'Institution name', 'spoc_name' => 'SPOC name'], $errors, $data);
        if (!$data['event_id']) {
            $errors['event_id'] = 'Event is required.';
        }

        if (!$errors) {
            if ($action === 'create') {
                $stmt = $db->prepare('INSERT INTO institutions (event_id, name, spoc_name, designation, contact_number, address, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $created_by = $user['id'];
                $stmt->bind_param('isssssi', $data['event_id'], $data['name'], $data['spoc_name'], $data['designation'], $data['contact_number'], $data['address'], $created_by);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Institution created successfully.');
            } else {
                $institution_id = (int) post_param('id');
                $stmt = $db->prepare('UPDATE institutions SET name = ?, spoc_name = ?, designation = ?, contact_number = ?, address = ? WHERE id = ?');
                $stmt->bind_param('sssssi', $data['name'], $data['spoc_name'], $data['designation'], $data['contact_number'], $data['address'], $institution_id);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Institution updated successfully.');
            }
            $redirect = 'institutions.php';
            if ($user['role'] === 'super_admin' && $event_id) {
                $redirect .= '?event_id=' . $event_id;
            }
            redirect($redirect);
        }
        $edit_institution = $data;
        $edit_institution['id'] = (int) post_param('id');
    } elseif ($action === 'delete') {
        $institution_id = (int) post_param('id');
        $stmt = $db->prepare('DELETE FROM institutions WHERE id = ?');
        $stmt->bind_param('i', $institution_id);
        $stmt->execute();
        $stmt->close();
        set_flash('success', 'Institution removed.');
        $redirect = 'institutions.php';
        if ($user['role'] === 'super_admin' && $event_id) {
            $redirect .= '?event_id=' . $event_id;
        }
        redirect($redirect);
    }
}

if (!$edit_institution && ($edit_id = (int) get_param('edit', 0))) {
    $stmt = $db->prepare('SELECT * FROM institutions WHERE id = ?');
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $edit_institution = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$sql = "SELECT i.*, e.name AS event_name, (SELECT COUNT(*) FROM participants p WHERE p.institution_id = i.id) AS participant_count\n        FROM institutions i\n        LEFT JOIN events e ON e.id = i.event_id";
$conditions = [];
$params = [];
$types = '';
if ($event_id) {
    $conditions[] = 'i.event_id = ?';
    $types .= 'i';
    $params[] = $event_id;
}
if ($search) {
    $conditions[] = '(i.name LIKE ? OR i.spoc_name LIKE ?)';
    $types .= 'ss';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY i.name';

$stmt = $db->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$institutions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flash = get_flash('success');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Participating Institutions</h1>
        <p class="text-muted mb-0">Manage institutions participating in the event.</p>
    </div>
    <?php if ($user['role'] === 'super_admin'): ?>
        <form method="get" class="d-flex align-items-center gap-2">
            <select name="event_id" class="form-select">
                <option value="">All Events</option>
                <?php foreach ($all_events as $event): ?>
                    <option value="<?php echo (int) $event['id']; ?>" <?php echo ($event_id && $event_id == $event['id']) ? 'selected' : ''; ?>><?php echo sanitize($event['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-outline-primary">Filter</button>
        </form>
    <?php endif; ?>
</div>
<?php if ($flash): ?>
    <div class="alert alert-success"><?php echo sanitize($flash); ?></div>
<?php endif; ?>
<div class="row g-4">
    <?php if ($can_manage): ?>
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><?php echo $edit_institution ? 'Edit Institution' : 'Add Institution'; ?></h2>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="<?php echo $edit_institution ? 'update' : 'create'; ?>">
                    <input type="hidden" name="id" value="<?php echo (int) ($edit_institution['id'] ?? 0); ?>">
                    <?php if ($user['role'] === 'super_admin'): ?>
                        <div class="mb-3">
                            <label class="form-label" for="event_id">Event</label>
                            <select name="event_id" id="event_id" class="form-select <?php echo isset($errors['event_id']) ? 'is-invalid' : ''; ?>">
                                <?php foreach ($all_events as $event): ?>
                                    <option value="<?php echo (int) $event['id']; ?>" <?php echo (($edit_institution['event_id'] ?? $event_id) == $event['id']) ? 'selected' : ''; ?>><?php echo sanitize($event['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['event_id'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['event_id']); ?></div><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label" for="name">Institution Name</label>
                        <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" name="name" id="name" value="<?php echo sanitize($edit_institution['name'] ?? ''); ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['name']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="spoc_name">SPOC Name</label>
                        <input type="text" class="form-control <?php echo isset($errors['spoc_name']) ? 'is-invalid' : ''; ?>" name="spoc_name" id="spoc_name" value="<?php echo sanitize($edit_institution['spoc_name'] ?? ''); ?>" required>
                        <?php if (isset($errors['spoc_name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['spoc_name']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="designation">Designation</label>
                        <input type="text" class="form-control" name="designation" id="designation" value="<?php echo sanitize($edit_institution['designation'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="contact_number">Contact Number</label>
                        <input type="text" class="form-control" name="contact_number" id="contact_number" value="<?php echo sanitize($edit_institution['contact_number'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="address">Address</label>
                        <textarea class="form-control" name="address" id="address" rows="3"><?php echo sanitize($edit_institution['address'] ?? ''); ?></textarea>
                    </div>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" type="submit"><?php echo $edit_institution ? 'Update Institution' : 'Create Institution'; ?></button>
                        <?php if ($edit_institution): ?>
                            <a href="institutions.php<?php echo ($user['role'] === 'super_admin' && $event_id) ? '?event_id=' . $event_id : ''; ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-lg-<?php echo $can_manage ? '8' : '12'; ?>">
        <div class="card shadow-sm">
            <div class="card-body">
                <form class="row g-2 align-items-center mb-3" method="get">
                    <?php if ($user['role'] === 'super_admin'): ?>
                        <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>">
                    <?php endif; ?>
                    <div class="col-sm-8">
                        <input type="text" name="q" class="form-control" placeholder="Search by institution or SPOC" value="<?php echo sanitize($search); ?>">
                    </div>
                    <div class="col-sm-4 d-flex gap-2">
                        <button class="btn btn-outline-primary w-100" type="submit">Search</button>
                        <a href="institutions.php<?php echo ($user['role'] === 'super_admin' && $event_id) ? '?event_id=' . $event_id : ''; ?>" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>SPOC</th>
                                <th>Contact</th>
                                <th>Address</th>
                                <th>Participants</th>
                                <?php if ($user['role'] === 'super_admin'): ?><th>Event</th><?php endif; ?>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($institutions as $institution): ?>
                            <tr>
                                <td><?php echo sanitize($institution['name']); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo sanitize($institution['spoc_name']); ?></div>
                                    <div class="text-muted small"><?php echo sanitize($institution['designation']); ?></div>
                                </td>
                                <td><?php echo sanitize($institution['contact_number']); ?></td>
                                <td><?php echo sanitize($institution['address']); ?></td>
                                <td><?php echo (int) $institution['participant_count']; ?></td>
                                <?php if ($user['role'] === 'super_admin'): ?><td><?php echo sanitize($institution['event_name']); ?></td><?php endif; ?>
                                <td class="text-end">
                                    <div class="table-actions justify-content-end">
                                        <a href="participants.php?institution_id=<?php echo (int) $institution['id']; ?>" class="btn btn-sm btn-outline-secondary">Participants</a>
                                        <?php if ($can_manage): ?>
                                            <a href="institutions.php?edit=<?php echo (int) $institution['id']; ?><?php echo ($user['role'] === 'super_admin' && $event_id) ? '&event_id=' . $event_id : ''; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                            <form method="post" onsubmit="return confirm('Remove this institution?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int) $institution['id']; ?>">
                                                <?php if ($user['role'] === 'super_admin' && $event_id): ?>
                                                    <input type="hidden" name="event_id" value="<?php echo (int) $event_id; ?>">
                                                <?php endif; ?>
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$institutions): ?>
                            <tr>
                                <td colspan="<?php echo $user['role'] === 'super_admin' ? '7' : '6'; ?>" class="text-center py-4 text-muted">No institutions found.</td>
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
