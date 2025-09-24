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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body class="d-flex flex-column min-vh-100" style="background-color: #f8f6f0;">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <i class="bi bi-trophy-fill me-2"></i>
                <?php echo APP_NAME; ?>
            </a>
        </div>
    </nav>

    <main class="flex-grow-1 d-flex align-items-center justify-content-center py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-5 col-xl-4">
                    <div class="card shadow-sm border-0 login-card">
                        <div class="card-body p-4">
                            <div class="text-center mb-4">
                                <div class="display-6 text-success"><i class="bi bi-trophy-fill"></i></div>
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
        </div>
    </main>

    <footer class="bg-success-subtle border-top py-3 mt-auto">
        <div class="container text-center">
            <span class="text-success fw-semibold">&copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?></span>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var backLink = document.querySelector('[data-role="back-link"]');
            if (!backLink) {
                return;
            }

            backLink.addEventListener('click', function (event) {
                event.preventDefault();
                if (window.history.length > 1) {
                    window.history.back();
                } else {
                    window.location.href = backLink.getAttribute('href');
                }
            });
        });
    </script>
</body>
</html>
