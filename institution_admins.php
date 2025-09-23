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
$edit_admin = null;
$search = trim((string) get_param('q', ''));

$institutions_stmt = $db->prepare('SELECT id, name FROM institutions WHERE event_id = ? ORDER BY name');
$institutions_stmt->bind_param('i', $event_id);
$institutions_stmt->execute();
$institutions = $institutions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$institutions_stmt->close();

if (is_post()) {
    $action = post_param('action');
    if ($action === 'create' || $action === 'update') {
        $data = [
            'name' => trim((string) post_param('name', '')),
            'email' => trim((string) post_param('email', '')),
            'contact_number' => trim((string) post_param('contact_number', '')),
            'institution_id' => (int) post_param('institution_id'),
        ];
        $password = (string) post_param('password', '');
        $confirm = (string) post_param('confirm_password', '');

        $required = ['name' => 'Name', 'email' => 'Email'];
        if ($action === 'create') {
            $required['password'] = 'Password';
        }
        validate_required($required, $errors, array_merge($data, ['password' => $password]));

        if (!$data['institution_id']) {
            $errors['institution_id'] = 'Institution is required.';
        }

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
                $stmt = $db->prepare('INSERT INTO users (name, email, password_hash, role, contact_number, event_id, institution_id) VALUES (?, ?, ?, \'institution_admin\', ?, ?, ?)');
                $stmt->bind_param('sssiii', $data['name'], $data['email'], $hash, $data['contact_number'], $event_id, $data['institution_id']);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Institution admin created successfully.');
            } else {
                $admin_id = (int) post_param('id');
                $query = 'UPDATE users SET name = ?, email = ?, contact_number = ?, institution_id = ?';
                $params = [$data['name'], $data['email'], $data['contact_number'], $data['institution_id']];
                $types = 'sssi';
                if ($password) {
                    $query .= ', password_hash = ?';
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                    $types .= 's';
                }
                $query .= ' WHERE id = ? AND event_id = ?';
                $params[] = $admin_id;
                $params[] = $event_id;
                $types .= 'ii';
                $stmt = $db->prepare($query);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Institution admin updated successfully.');
            }
            redirect('institution_admins.php');
        }

        $edit_admin = $data;
        $edit_admin['id'] = (int) post_param('id');
    } elseif ($action === 'delete') {
        $admin_id = (int) post_param('id');
        $stmt = $db->prepare('DELETE FROM users WHERE id = ? AND role = \'institution_admin\' AND event_id = ?');
        $stmt->bind_param('ii', $admin_id, $event_id);
        $stmt->execute();
        $stmt->close();
        set_flash('success', 'Institution admin removed.');
        redirect('institution_admins.php');
    }
}

if (!$edit_admin && ($edit_id = (int) get_param('edit', 0))) {
    $stmt = $db->prepare('SELECT id, name, email, contact_number, institution_id FROM users WHERE id = ? AND role = \'institution_admin\' AND event_id = ?');
    $stmt->bind_param('ii', $edit_id, $event_id);
    $stmt->execute();
    $edit_admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$sql = "SELECT u.id, u.name, u.email, u.contact_number, i.name AS institution_name\n        FROM users u\n        LEFT JOIN institutions i ON i.id = u.institution_id\n        WHERE u.role = 'institution_admin' AND u.event_id = ?";
$params = [$event_id];
$types = 'i';
if ($search) {
    $sql .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}
$sql .= ' ORDER BY u.name';
$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$admins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flash = get_flash('success');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Institution Administrators</h1>
        <p class="text-muted mb-0">Manage administrators for participating institutions.</p>
    </div>
</div>
<?php if ($flash): ?>
    <div class="alert alert-success"><?php echo sanitize($flash); ?></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><?php echo $edit_admin ? 'Edit Institution Admin' : 'Add Institution Admin'; ?></h2>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="<?php echo $edit_admin ? 'update' : 'create'; ?>">
                    <input type="hidden" name="id" value="<?php echo (int) ($edit_admin['id'] ?? 0); ?>">
                    <div class="mb-3">
                        <label class="form-label" for="name">Name</label>
                        <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" id="name" name="name" value="<?php echo sanitize($edit_admin['name'] ?? ''); ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['name']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" class="form-control <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>" id="email" name="email" value="<?php echo sanitize($edit_admin['email'] ?? ''); ?>" required>
                        <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['email']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="contact_number">Contact Number</label>
                        <input type="text" class="form-control" id="contact_number" name="contact_number" value="<?php echo sanitize($edit_admin['contact_number'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="institution_id">Institution</label>
                        <select class="form-select <?php echo isset($errors['institution_id']) ? 'is-invalid' : ''; ?>" id="institution_id" name="institution_id" required>
                            <option value="">-- Select Institution --</option>
                            <?php foreach ($institutions as $institution): ?>
                                <option value="<?php echo (int) $institution['id']; ?>" <?php echo ((int) ($edit_admin['institution_id'] ?? 0) === (int) $institution['id']) ? 'selected' : ''; ?>><?php echo sanitize($institution['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['institution_id'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['institution_id']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input type="password" class="form-control <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>" id="password" name="password" <?php echo $edit_admin ? '' : 'required'; ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="confirm_password">Confirm Password</label>
                        <input type="password" class="form-control <?php echo isset($errors['confirm_password']) ? 'is-invalid' : ''; ?>" id="confirm_password" name="confirm_password">
                        <?php if (isset($errors['confirm_password'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['confirm_password']); ?></div><?php endif; ?>
                    </div>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" type="submit"><?php echo $edit_admin ? 'Update Admin' : 'Create Admin'; ?></button>
                        <?php if ($edit_admin): ?>
                            <a href="institution_admins.php" class="btn btn-outline-secondary">Cancel</a>
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
                        <a href="institution_admins.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Contact</th>
                                <th>Institution</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($admins as $admin): ?>
                            <tr>
                                <td><?php echo sanitize($admin['name']); ?></td>
                                <td><?php echo sanitize($admin['email']); ?></td>
                                <td><?php echo sanitize($admin['contact_number']); ?></td>
                                <td><?php echo sanitize($admin['institution_name']); ?></td>
                                <td class="text-end">
                                    <div class="table-actions justify-content-end">
                                        <a href="institution_admins.php?edit=<?php echo (int) $admin['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                        <form method="post" onsubmit="return confirm('Remove this admin?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $admin['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$admins): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No institution administrators found.</td>
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
