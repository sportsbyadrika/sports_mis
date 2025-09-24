<?php
require_once __DIR__ . '/includes/header.php';
require_login();

$user = current_user();
$db = get_db_connection();
$errors = [];
$success = null;

if (is_post()) {
    $currentPassword = (string) post_param('current_password', '');
    $newPassword = (string) post_param('password', '');
    $confirmPassword = (string) post_param('confirm_password', '');

    validate_required(
        [
            'current_password' => 'Current password',
            'password' => 'New password',
            'confirm_password' => 'Confirm password',
        ],
        $errors,
        [
            'current_password' => $currentPassword,
            'password' => $newPassword,
            'confirm_password' => $confirmPassword,
        ]
    );

    if (!$errors && $newPassword !== $confirmPassword) {
        $errors['confirm_password'] = 'Password confirmation does not match.';
    }

    if (!$errors) {
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $record = $result->fetch_assoc();
        $stmt->close();

        if (!$record || !password_verify($currentPassword, $record['password_hash'])) {
            $errors['current_password'] = 'Current password is incorrect.';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $update->bind_param('si', $hash, $user['id']);
            $update->execute();
            $update->close();
            $success = 'Password updated successfully.';
        }
    }
}
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h1 class="h5 mb-0">Change Password</h1>
            </div>
            <div class="card-body">
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo sanitize($success); ?></div>
                <?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" class="form-control <?php echo isset($errors['current_password']) ? 'is-invalid' : ''; ?>" id="current_password" name="current_password" required>
                        <?php if (isset($errors['current_password'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['current_password']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">New Password</label>
                        <input type="password" class="form-control <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>" id="password" name="password" required>
                        <?php if (isset($errors['password'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['password']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm Password</label>
                        <input type="password" class="form-control <?php echo isset($errors['confirm_password']) ? 'is-invalid' : ''; ?>" id="confirm_password" name="confirm_password" required>
                        <?php if (isset($errors['confirm_password'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['confirm_password']); ?></div><?php endif; ?>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
