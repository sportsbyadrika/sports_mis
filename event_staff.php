<?php
require_once __DIR__ . '/includes/header.php';
require_login();
require_role(['event_admin']);

$user = current_user();
$db = get_db_connection();

if (!$user['event_id']) {
    echo '<div class="alert alert-warning">No event assigned to your account. Please contact the super administrator.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$event_id = (int) $user['event_id'];
$errors = [];
$edit_staff = null;
$search = trim((string) get_param('q', ''));

if (is_post()) {
    $action = post_param('action');
    if ($action === 'create' || $action === 'update') {
        $data = [
            'name' => trim((string) post_param('name', '')),
            'email' => trim((string) post_param('email', '')),
            'contact_number' => trim((string) post_param('contact_number', '')),
        ];
        $password = (string) post_param('password', '');
        $confirm = (string) post_param('confirm_password', '');

        $required = ['name' => 'Name', 'email' => 'Email'];
        if ($action === 'create') {
            $required['password'] = 'Password';
        }
        validate_required($required, $errors, array_merge($data, ['password' => $password]));

        if ($password && $password !== $confirm) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }

        if (!$errors) {
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
            $current_id = (int) post_param('id', 0);
            $stmt->bind_param('si', $data['email'], $current_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $errors['email'] = 'Email is already registered.';
            }
            $stmt->close();
        }

        if (!$errors) {
            if ($action === 'create') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare('INSERT INTO users (name, email, password_hash, role, contact_number, event_id) VALUES (?, ?, ?, \'event_staff\', ?, ?)');
                $stmt->bind_param('ssssi', $data['name'], $data['email'], $hash, $data['contact_number'], $event_id);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Event staff created successfully.');
            } else {
                $staff_id = (int) post_param('id');
                $query = 'UPDATE users SET name = ?, email = ?, contact_number = ?';
                $params = [$data['name'], $data['email'], $data['contact_number']];
                $types = 'sss';
                if ($password) {
                    $query .= ', password_hash = ?';
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                    $types .= 's';
                }
                $query .= ' WHERE id = ? AND event_id = ?';
                $params[] = $staff_id;
                $params[] = $event_id;
                $types .= 'ii';
                $stmt = $db->prepare($query);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Event staff updated successfully.');
            }
            redirect('event_staff.php');
        }

        $edit_staff = $data;
        $edit_staff['id'] = (int) post_param('id');
    } elseif ($action === 'delete') {
        $staff_id = (int) post_param('id');
        $stmt = $db->prepare('DELETE FROM users WHERE id = ? AND role = \'event_staff\' AND event_id = ?');
        $stmt->bind_param('ii', $staff_id, $event_id);
        $stmt->execute();
        $stmt->close();
        set_flash('success', 'Event staff removed.');
        redirect('event_staff.php');
    }
}

if (!$edit_staff && ($edit_id = (int) get_param('edit', 0))) {
    $stmt = $db->prepare('SELECT id, name, email, contact_number FROM users WHERE id = ? AND role = \'event_staff\' AND event_id = ?');
    $stmt->bind_param('ii', $edit_id, $event_id);
    $stmt->execute();
    $edit_staff = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$sql = "SELECT id, name, email, contact_number FROM users WHERE role = 'event_staff' AND event_id = ?";
$params = [$event_id];
$types = 'i';
if ($search) {
    $sql .= ' AND (name LIKE ? OR email LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}
$sql .= ' ORDER BY name';
$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$staff_members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flash = get_flash('success');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Event Staff</h1>
        <p class="text-muted mb-0">Manage staff members supporting the event.</p>
    </div>
</div>
<?php if ($flash): ?>
    <div class="alert alert-success"><?php echo sanitize($flash); ?></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><?php echo $edit_staff ? 'Edit Staff Member' : 'Add Staff Member'; ?></h2>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="<?php echo $edit_staff ? 'update' : 'create'; ?>">
                    <input type="hidden" name="id" value="<?php echo (int) ($edit_staff['id'] ?? 0); ?>">
                    <div class="mb-3">
                        <label class="form-label" for="name">Name</label>
                        <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" id="name" name="name" value="<?php echo sanitize($edit_staff['name'] ?? ''); ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['name']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" class="form-control <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>" id="email" name="email" value="<?php echo sanitize($edit_staff['email'] ?? ''); ?>" required>
                        <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['email']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="contact_number">Contact Number</label>
                        <input type="text" class="form-control" id="contact_number" name="contact_number" value="<?php echo sanitize($edit_staff['contact_number'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input type="password" class="form-control <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>" id="password" name="password" <?php echo $edit_staff ? '' : 'required'; ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="confirm_password">Confirm Password</label>
                        <input type="password" class="form-control <?php echo isset($errors['confirm_password']) ? 'is-invalid' : ''; ?>" id="confirm_password" name="confirm_password">
                        <?php if (isset($errors['confirm_password'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['confirm_password']); ?></div><?php endif; ?>
                    </div>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" type="submit"><?php echo $edit_staff ? 'Update Staff' : 'Create Staff'; ?></button>
                        <?php if ($edit_staff): ?>
                            <a href="event_staff.php" class="btn btn-outline-secondary">Cancel</a>
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
                        <input type="text" name="q" class="form-control" placeholder="Search by name or email" value="<?php echo sanitize($search); ?>">
                    </div>
                    <div class="col-sm-4 d-flex gap-2">
                        <button class="btn btn-outline-primary w-100" type="submit">Search</button>
                        <a href="event_staff.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Contact</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($staff_members as $staff): ?>
                            <tr>
                                <td><?php echo sanitize($staff['name']); ?></td>
                                <td><?php echo sanitize($staff['email']); ?></td>
                                <td><?php echo sanitize($staff['contact_number']); ?></td>
                                <td class="text-end">
                                    <div class="table-actions justify-content-end">
                                        <a href="event_staff.php?edit=<?php echo (int) $staff['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                        <form method="post" onsubmit="return confirm('Remove this staff member?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $staff['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$staff_members): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No event staff found.</td>
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
