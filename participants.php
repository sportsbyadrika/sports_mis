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
$errors = [];
$edit_participant = null;
$view_participant = null;

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
    if (in_array($action, ['create', 'update'], true)) {
        $data = [
            'name' => trim((string) post_param('name', '')),
            'date_of_birth' => post_param('date_of_birth'),
            'gender' => post_param('gender'),
            'guardian_name' => trim((string) post_param('guardian_name', '')),
            'contact_number' => trim((string) post_param('contact_number', '')),
            'address' => trim((string) post_param('address', '')),
            'email' => trim((string) post_param('email', '')),
        ];

        validate_required([
            'name' => 'Participant name',
            'date_of_birth' => 'Date of birth',
            'gender' => 'Gender',
            'guardian_name' => "Guardian's name",
            'contact_number' => 'Contact number',
        ], $errors, array_merge($data, ['date_of_birth' => $data['date_of_birth'] ?? '', 'gender' => $data['gender'] ?? '']));

        if ($data['gender'] && !in_array($data['gender'], ['Male', 'Female'], true)) {
            $errors['gender'] = 'Invalid gender selection.';
        }

        if (!$errors) {
            if ($action === 'create') {
                $stmt = $db->prepare('INSERT INTO participants (institution_id, event_id, name, date_of_birth, gender, guardian_name, contact_number, address, email, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\', ?)');
                $created_by = $user['id'];
                $stmt->bind_param('iisssssssi', $institution_id, $event_id, $data['name'], $data['date_of_birth'], $data['gender'], $data['guardian_name'], $data['contact_number'], $data['address'], $data['email'], $created_by);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Participant created successfully.');
            } else {
                $participant_id = (int) post_param('id');
                $participant = fetch_participant($db, $participant_id, $institution_id);
                if (!$participant) {
                    $errors['general'] = 'Participant not found.';
                } elseif ($participant['status'] === 'submitted') {
                    $errors['general'] = 'Submitted participants cannot be edited.';
                } else {
                    $stmt = $db->prepare('UPDATE participants SET name = ?, date_of_birth = ?, gender = ?, guardian_name = ?, contact_number = ?, address = ?, email = ?, updated_at = NOW() WHERE id = ?');
                    $stmt->bind_param('sssssssi', $data['name'], $data['date_of_birth'], $data['gender'], $data['guardian_name'], $data['contact_number'], $data['address'], $data['email'], $participant_id);
                    $stmt->execute();
                    $stmt->close();
                    set_flash('success', 'Participant updated successfully.');
                    redirect('participants.php');
                }
            }
            if (!$errors) {
                redirect('participants.php');
            }
        }

        $edit_participant = $data;
        $edit_participant['id'] = (int) post_param('id');
    } elseif ($action === 'delete') {
        $participant_id = (int) post_param('id');
        $participant = fetch_participant($db, $participant_id, $institution_id);
        if ($participant && $participant['status'] !== 'submitted') {
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
            $stmt = $db->prepare('UPDATE participants SET status = \'submitted\', submitted_at = NOW(), submitted_by = ? WHERE id = ?');
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

if (!$edit_participant && ($edit_id = (int) get_param('edit', 0)) && $can_manage) {
    $participant = fetch_participant($db, $edit_id, $institution_id);
    if ($participant) {
        $edit_participant = $participant;
    }
}

$view_id = (int) get_param('view', 0);
if ($view_id) {
    $sql = "SELECT p.*, i.name AS institution_name FROM participants p LEFT JOIN institutions i ON i.id = p.institution_id WHERE p.id = ?";
    $types = 'i';
    $params = [$view_id];
    if ($role === 'institution_admin') {
        $sql .= ' AND p.institution_id = ?';
        $types .= 'i';
        $params[] = $institution_id;
    } elseif ($role === 'event_admin' || $role === 'event_staff') {
        $sql .= ' AND p.event_id = ?';
        $types .= 'i';
        $params[] = $event_id;
    } elseif ($role === 'super_admin') {
        if ($event_id) {
            $sql .= ' AND p.event_id = ?';
            $types .= 'i';
            $params[] = $event_id;
        }
        if ($institution_id) {
            $sql .= ' AND p.institution_id = ?';
            $types .= 'i';
            $params[] = $institution_id;
        }
    }
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $view_participant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$sql = "SELECT p.*, i.name AS institution_name\n        FROM participants p\n        LEFT JOIN institutions i ON i.id = p.institution_id";
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

$flash_success = get_flash('success');
$flash_error = get_flash('error');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Participants</h1>
        <p class="text-muted mb-0">Manage participant registrations for the event.</p>
    </div>
    <div class="d-flex gap-2">
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
<?php if ($view_participant): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <div>
                <h2 class="h6 mb-0">Participant Details</h2>
                <span class="badge bg-<?php echo $view_participant['status'] === 'submitted' ? 'success' : 'secondary'; ?> text-uppercase"><?php echo sanitize($view_participant['status']); ?></span>
            </div>
            <a href="participants.php" class="btn btn-sm btn-outline-secondary">Close</a>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="text-muted small">Full Name</div>
                    <div class="fw-semibold"><?php echo sanitize($view_participant['name']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Date of Birth</div>
                    <div class="fw-semibold"><?php echo format_date($view_participant['date_of_birth']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Gender</div>
                    <div class="fw-semibold"><?php echo sanitize($view_participant['gender']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Guardian</div>
                    <div class="fw-semibold"><?php echo sanitize($view_participant['guardian_name']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Contact</div>
                    <div class="fw-semibold"><?php echo sanitize($view_participant['contact_number']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Email</div>
                    <div class="fw-semibold"><?php echo sanitize($view_participant['email']); ?></div>
                </div>
                <div class="col-md-12">
                    <div class="text-muted small">Address</div>
                    <div class="fw-semibold"><?php echo nl2br(sanitize($view_participant['address'])); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Institution</div>
                    <div class="fw-semibold"><?php echo sanitize($view_participant['institution_name']); ?></div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<div class="row g-4">
    <?php if ($can_manage): ?>
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><?php echo $edit_participant ? 'Edit Participant' : 'Add Participant'; ?></h2>
            </div>
            <div class="card-body">
                <?php if (isset($errors['general'])): ?>
                    <div class="alert alert-danger"><?php echo sanitize($errors['general']); ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="<?php echo $edit_participant ? 'update' : 'create'; ?>">
                    <input type="hidden" name="id" value="<?php echo (int) ($edit_participant['id'] ?? 0); ?>">
                    <div class="mb-3">
                        <label class="form-label" for="name">Full Name</label>
                        <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" id="name" name="name" value="<?php echo sanitize($edit_participant['name'] ?? ''); ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['name']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="date_of_birth">Date of Birth</label>
                        <input type="date" class="form-control <?php echo isset($errors['date_of_birth']) ? 'is-invalid' : ''; ?>" id="date_of_birth" name="date_of_birth" value="<?php echo sanitize($edit_participant['date_of_birth'] ?? ''); ?>" required>
                        <?php if (isset($errors['date_of_birth'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['date_of_birth']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="gender">Gender</label>
                        <select class="form-select <?php echo isset($errors['gender']) ? 'is-invalid' : ''; ?>" id="gender" name="gender" required>
                            <option value="">Select Gender</option>
                            <option value="Male" <?php echo (($edit_participant['gender'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo (($edit_participant['gender'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                        </select>
                        <?php if (isset($errors['gender'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['gender']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="guardian_name">Guardian Name</label>
                        <input type="text" class="form-control <?php echo isset($errors['guardian_name']) ? 'is-invalid' : ''; ?>" id="guardian_name" name="guardian_name" value="<?php echo sanitize($edit_participant['guardian_name'] ?? ''); ?>" required>
                        <?php if (isset($errors['guardian_name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['guardian_name']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="contact_number">Contact Number</label>
                        <input type="text" class="form-control <?php echo isset($errors['contact_number']) ? 'is-invalid' : ''; ?>" id="contact_number" name="contact_number" value="<?php echo sanitize($edit_participant['contact_number'] ?? ''); ?>" required>
                        <?php if (isset($errors['contact_number'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['contact_number']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?php echo sanitize($edit_participant['email'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="address">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="3"><?php echo sanitize($edit_participant['address'] ?? ''); ?></textarea>
                    </div>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" type="submit"><?php echo $edit_participant ? 'Update Participant' : 'Create Participant'; ?></button>
                        <?php if ($edit_participant): ?>
                            <a href="participants.php" class="btn btn-outline-secondary">Cancel</a>
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
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>DOB</th>
                                <th>Gender</th>
                                <th>Guardian</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <?php if ($role !== 'institution_admin'): ?><th>Institution</th><?php endif; ?>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($participants as $participant): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo sanitize($participant['name']); ?></div>
                                    <div class="text-muted small"><?php echo sanitize($participant['email']); ?></div>
                                </td>
                                <td><?php echo format_date($participant['date_of_birth']); ?></td>
                                <td><?php echo sanitize($participant['gender']); ?></td>
                                <td><?php echo sanitize($participant['guardian_name']); ?></td>
                                <td><?php echo sanitize($participant['contact_number']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $participant['status'] === 'submitted' ? 'success' : 'secondary'; ?> text-uppercase"><?php echo sanitize($participant['status']); ?></span>
                                </td>
                                <?php if ($role !== 'institution_admin'): ?><td><?php echo sanitize($participant['institution_name']); ?></td><?php endif; ?>
                                <td class="text-end">
                                    <div class="table-actions justify-content-end">
                                        <?php if ($can_manage && $participant['status'] === 'draft'): ?>
                                            <a href="participants.php?edit=<?php echo (int) $participant['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
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
                                        <?php else: ?>
                                            <a href="participants.php?view=<?php echo (int) $participant['id']; ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$participants): ?>
                            <tr>
                                <td colspan="<?php echo $role !== 'institution_admin' ? '8' : '7'; ?>" class="text-center text-muted py-4">No participants found.</td>
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
