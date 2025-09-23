<?php
require_once __DIR__ . '/includes/header.php';
require_login();

$user = current_user();
$db = get_db_connection();
$errors = [];
$success = null;

if (is_post()) {
    $name = trim(post_param('name', $user['name']));
    $contact = trim(post_param('contact_number', $user['contact_number'] ?? ''));
    $password = (string) post_param('password', '');
    $confirm = (string) post_param('confirm_password', '');

    validate_required(['name' => 'Name'], $errors, ['name' => $name]);

    if ($password && $password !== $confirm) {
        $errors['confirm_password'] = 'Password confirmation does not match.';
    }

    if (!$errors) {
        $stmt = $db->prepare('UPDATE users SET name = ?, contact_number = ?' . ($password ? ', password_hash = ?' : '') . ' WHERE id = ?');
        if ($password) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt->bind_param('sssi', $name, $contact, $hash, $user['id']);
        } else {
            $stmt->bind_param('ssi', $name, $contact, $user['id']);
        }
        $stmt->execute();
        $stmt->close();
        $success = 'Profile updated successfully.';
        refresh_current_user();
        $user = current_user();
    }
}
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h1 class="h5 mb-0">My Profile</h1>
            </div>
            <div class="card-body">
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo sanitize($success); ?></div>
                <?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <label for="name" class="form-label">Full Name</label>
                        <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" id="name" name="name" value="<?php echo sanitize($user['name']); ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['name']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" value="<?php echo sanitize($user['email']); ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label for="contact_number" class="form-label">Contact Number</label>
                        <input type="text" class="form-control" id="contact_number" name="contact_number" value="<?php echo sanitize($user['contact_number'] ?? ''); ?>">
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label for="password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="password" name="password" placeholder="Leave blank to keep current password">
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm Password</label>
                        <input type="password" class="form-control <?php echo isset($errors['confirm_password']) ? 'is-invalid' : ''; ?>" id="confirm_password" name="confirm_password">
                        <?php if (isset($errors['confirm_password'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['confirm_password']); ?></div><?php endif; ?>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
