<?php
require_once __DIR__ . '/includes/header.php';
require_login();
require_role(['super_admin']);

$db = get_db_connection();
$errors = [];
$edit_admin = null;
$search = trim((string) get_param('q', ''));

$events_stmt = $db->query('SELECT id, name FROM events ORDER BY name');
$events = $events_stmt->fetch_all(MYSQLI_ASSOC);
$events_stmt->close();

if (is_post()) {
    $action = post_param('action');
    if ($action === 'create' || $action === 'update') {
        $data = [
            'name' => trim((string) post_param('name', '')),
            'email' => trim((string) post_param('email', '')),
            'contact_number' => trim((string) post_param('contact_number', '')),
            'event_id' => (int) post_param('event_id'),
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
            // Check unique email
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
                $stmt = $db->prepare('INSERT INTO users (name, email, password_hash, role, event_id, contact_number) VALUES (?, ?, ?, \'event_admin\', ?, ?)');
                $stmt->bind_param('sssiss', $data['name'], $data['email'], $hash, $data['event_id'], $data['contact_number']);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Event admin created successfully.');
            } else {
                $admin_id = (int) post_param('id');
                $query = 'UPDATE users SET name = ?, email = ?, event_id = ?, contact_number = ?';
                $params = [$data['name'], $data['email'], $data['event_id'], $data['contact_number']];
                $types = 'ssis';
                if ($password) {
                    $query .= ', password_hash = ?';
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                    $types .= 's';
                }
                $query .= ' WHERE id = ?';
                $params[] = $admin_id;
                $types .= 'i';
                $stmt = $db->prepare($query);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Event admin updated successfully.');
            }
            redirect('event_admins.php');
        }

        $edit_admin = $data;
        $edit_admin['id'] = (int) post_param('id');
    } elseif ($action === 'delete') {
        $admin_id = (int) post_param('id');
        $stmt = $db->prepare('DELETE FROM users WHERE id = ? AND role = \'event_admin\'');
        $stmt->bind_param('i', $admin_id);
        $stmt->execute();
        $stmt->close();
        set_flash('success', 'Event admin removed.');
        redirect('event_admins.php');
    }
}

if (!$edit_admin && ($edit_id = (int) get_param('edit', 0))) {
    $stmt = $db->prepare('SELECT id, name, email, contact_number, event_id FROM users WHERE id = ? AND role = \'event_admin\'');
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $edit_admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$sql = "SELECT u.id, u.name, u.email, u.contact_number, e.name AS event_name\n        FROM users u\n        LEFT JOIN events e ON e.id = u.event_id\n        WHERE u.role = 'event_admin'";
$params = [];
if ($search) {
    $sql .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
    $like = '%' . $search . '%';
    $params = [$like, $like];
}
$sql .= ' ORDER BY u.name';
$stmt = $db->prepare($sql);
if ($search) {
    $stmt->bind_param('ss', ...$params);
}
$stmt->execute();
$admins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flash = get_flash('success');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Event Administrators</h1>
        <p class="text-muted mb-0">Assign administrators to manage specific sports events.</p>
    </div>
</div>
<?php if ($flash): ?>
    <div class="alert alert-success"><?php echo sanitize($flash); ?></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><?php echo $edit_admin ? 'Edit Event Admin' : 'Add Event Admin'; ?></h2>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="<?php echo $edit_admin ? 'update' : 'create'; ?>">
                    <input type="hidden" name="id" value="<?php echo (int) ($edit_admin['id'] ?? 0); ?>">
                    <div class="mb-3">
                        <label class="form-label" for="name">Name</label>
                        <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" name="name" id="name" value="<?php echo sanitize($edit_admin['name'] ?? ''); ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['name']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" class="form-control <?php echo isset($errors['email']) ? 'is-invalid' : ''; ?>" name="email" id="email" value="<?php echo sanitize($edit_admin['email'] ?? ''); ?>" required>
                        <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['email']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="contact_number">Contact Number</label>
                        <input type="text" class="form-control" name="contact_number" id="contact_number" value="<?php echo sanitize($edit_admin['contact_number'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="event_id">Event</label>
                        <select name="event_id" id="event_id" class="form-select">
                            <option value="">-- Select Event --</option>
                            <?php foreach ($events as $event): ?>
                                <option value="<?php echo (int) $event['id']; ?>" <?php echo (isset($edit_admin['event_id']) && (int) $edit_admin['event_id'] === (int) $event['id']) ? 'selected' : ''; ?>><?php echo sanitize($event['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input type="password" class="form-control <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>" name="password" id="password" <?php echo $edit_admin ? '' : 'required'; ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="confirm_password">Confirm Password</label>
                        <input type="password" class="form-control <?php echo isset($errors['confirm_password']) ? 'is-invalid' : ''; ?>" name="confirm_password" id="confirm_password">
                        <?php if (isset($errors['confirm_password'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['confirm_password']); ?></div><?php endif; ?>
                    </div>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" type="submit"><?php echo $edit_admin ? 'Update Admin' : 'Create Admin'; ?></button>
                        <?php if ($edit_admin): ?>
                            <a href="event_admins.php" class="btn btn-outline-secondary">Cancel</a>
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
                        <a href="event_admins.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Contact</th>
                                <th>Event</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($admins as $admin): ?>
                            <tr>
                                <td><?php echo sanitize($admin['name']); ?></td>
                                <td><?php echo sanitize($admin['email']); ?></td>
                                <td><?php echo sanitize($admin['contact_number']); ?></td>
                                <td><?php echo sanitize($admin['event_name']); ?></td>
                                <td class="text-end">
                                    <div class="table-actions justify-content-end">
                                        <a href="event_admins.php?edit=<?php echo (int) $admin['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
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
                                <td colspan="5" class="text-center text-muted py-4">No event administrators found.</td>
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
