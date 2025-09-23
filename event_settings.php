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
$success = null;

$stmt = $db->prepare('SELECT * FROM events WHERE id = ?');
$stmt->bind_param('i', $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    echo '<div class="alert alert-danger">Unable to load event details.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

if (is_post()) {
    $event['name'] = trim((string) post_param('name', $event['name']));
    $event['location'] = trim((string) post_param('location', $event['location']));
    $event['start_date'] = post_param('start_date') ?: null;
    $event['end_date'] = post_param('end_date') ?: null;
    $event['description'] = trim((string) post_param('description', $event['description']));

    validate_required(['name' => 'Event name'], $errors, $event);

    if (!$errors) {
        $stmt = $db->prepare('UPDATE events SET name = ?, location = ?, start_date = ?, end_date = ?, description = ? WHERE id = ?');
        $stmt->bind_param('sssssi', $event['name'], $event['location'], $event['start_date'], $event['end_date'], $event['description'], $event_id);
        $stmt->execute();
        $stmt->close();
        $success = 'Event details updated successfully.';
    }
}
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h1 class="h5 mb-0">Event Settings</h1>
            </div>
            <div class="card-body">
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo sanitize($success); ?></div>
                <?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <label for="name" class="form-label">Event Name</label>
                        <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" id="name" name="name" value="<?php echo sanitize($event['name']); ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['name']); ?></div><?php endif; ?>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo sanitize($event['start_date']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo sanitize($event['end_date']); ?>">
                        </div>
                    </div>
                    <div class="mb-3 mt-3">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" class="form-control" id="location" name="location" value="<?php echo sanitize($event['location']); ?>">
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"><?php echo sanitize($event['description']); ?></textarea>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
