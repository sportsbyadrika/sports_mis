<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = null;

if (is_post()) {
    $email = trim(post_param('email', ''));
    $password = (string) post_param('password', '');

    if (!$email || !$password) {
        $error = 'Email and password are required.';
    } else {
        if (!login_user($email, $password)) {
            $error = 'Invalid credentials. Please try again.';
        } else {
            redirect('dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height: 100vh;">
    <div class="card shadow-sm" style="min-width: 360px;">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <div class="display-6 text-primary"><i class="bi bi-trophy"></i></div>
                <h1 class="h4 mb-0"><?php echo APP_NAME; ?></h1>
                <p class="text-muted">Sports Event Management Information System</p>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert"><?php echo sanitize($error); ?></div>
            <?php endif; ?>
            <form method="post" novalidate>
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" required value="<?php echo sanitize(post_param('email', '')); ?>">
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">Login</button>
                </div>
            </form>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
