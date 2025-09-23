<?php $user = current_user(); ?>
<?php if ($user): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
            <i class="bi bi-trophy"></i>
            <span><?php echo APP_NAME; ?></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                <?php if ($user['role'] === 'super_admin'): ?>
                    <li class="nav-item"><a class="nav-link" href="events.php">Events</a></li>
                    <li class="nav-item"><a class="nav-link" href="event_admins.php">Event Admins</a></li>
                <?php endif; ?>
                <?php if ($user['role'] === 'event_admin'): ?>
                    <li class="nav-item"><a class="nav-link" href="event_settings.php">Event Settings</a></li>
                    <li class="nav-item"><a class="nav-link" href="institutions.php">Participating Institutions</a></li>
                    <li class="nav-item"><a class="nav-link" href="institution_admins.php">Institution Admins</a></li>
                    <li class="nav-item"><a class="nav-link" href="event_staff.php">Event Staff</a></li>
                <?php endif; ?>
                <?php if ($user['role'] === 'institution_admin'): ?>
                    <li class="nav-item"><a class="nav-link" href="participants.php">Participants</a></li>
                <?php endif; ?>
                <?php if ($user['role'] === 'event_staff'): ?>
                    <li class="nav-item"><a class="nav-link" href="event_staff_participants.php">Participants</a></li>
                <?php endif; ?>
            </ul>
            <div class="dropdown">
                <a class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="text-end me-2">
                        <div class="fw-semibold small"><?php echo sanitize($user['name']); ?></div>
                        <div class="small text-white-50 text-capitalize"><?php echo str_replace('_', ' ', sanitize($user['role'])); ?></div>
                    </div>
                    <i class="bi bi-person-circle fs-4"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>
<?php endif; ?>
