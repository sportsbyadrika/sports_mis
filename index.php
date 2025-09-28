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

$page_title = 'Login';

include __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center py-5">
    <div class="col-12 col-sm-10 col-md-8 col-lg-5 col-xl-4">
        <div class="card shadow-sm border-0 login-card">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                    <img src="assets/images/logo.svg" alt="SportsbyA Tech logo" class="mb-3" height="48" width="48">
                    <h1 class="h4 mb-0 text-primary"><?php echo APP_NAME; ?></h1>
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
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
