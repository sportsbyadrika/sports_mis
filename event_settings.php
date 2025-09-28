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
$delete_qr_path = null;

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
    $event['bank_account_number'] = trim((string) post_param('bank_account_number', (string) ($event['bank_account_number'] ?? '')));
    $event['bank_ifsc'] = trim((string) post_param('bank_ifsc', (string) ($event['bank_ifsc'] ?? '')));
    $event['bank_name'] = trim((string) post_param('bank_name', (string) ($event['bank_name'] ?? '')));

    $remove_qr = (string) post_param('remove_qr', '') === '1';

    if ($event['bank_account_number'] === '') {
        $event['bank_account_number'] = null;
    }
    if ($event['bank_ifsc'] === '') {
        $event['bank_ifsc'] = null;
    }
    if ($event['bank_name'] === '') {
        $event['bank_name'] = null;
    }

    validate_required(['name' => 'Event name'], $errors, $event);

    $qr_upload = $_FILES['payment_qr'] ?? null;
    $qr_ready_for_save = false;
    $qr_extension = null;

    if ($qr_upload && ($qr_upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($qr_upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errors['payment_qr'] = 'Failed to upload the QR code image.';
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $qr_upload['tmp_name']) : null;
            if ($finfo) {
                finfo_close($finfo);
            }

            $allowed_mimes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];

            if (!$mime || !array_key_exists($mime, $allowed_mimes)) {
                $errors['payment_qr'] = 'QR code image must be a JPG, PNG, or WEBP file.';
            } elseif (($qr_upload['size'] ?? 0) > 5 * 1024 * 1024) {
                $errors['payment_qr'] = 'QR code image must be smaller than 5MB.';
            } else {
                $qr_extension = $allowed_mimes[$mime];
                $qr_ready_for_save = true;
            }
        }
    }

    if (!$errors) {
        if ($qr_ready_for_save) {
            $upload_dir = __DIR__ . '/uploads/event_payments';
            if (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true) && !is_dir($upload_dir)) {
                $errors['payment_qr'] = 'Unable to prepare the directory for QR code uploads.';
            } else {
                try {
                    $random = bin2hex(random_bytes(8));
                } catch (Throwable $e) {
                    $random = uniqid('', true);
                }
                $filename = 'qr_' . time() . '_' . $random . '.' . $qr_extension;
                $destination = $upload_dir . '/' . $filename;
                if (!move_uploaded_file($qr_upload['tmp_name'], $destination)) {
                    $errors['payment_qr'] = 'Failed to save the QR code image.';
                } else {
                    $delete_qr_path = $event['payment_qr_path'] ?? null;
                    $event['payment_qr_path'] = 'uploads/event_payments/' . $filename;
                }
            }
        } elseif ($remove_qr && !empty($event['payment_qr_path'])) {
            $delete_qr_path = $event['payment_qr_path'];
            $event['payment_qr_path'] = null;
        }
    }

    if (!$errors) {
        $stmt = $db->prepare('UPDATE events SET name = ?, location = ?, start_date = ?, end_date = ?, description = ?, bank_account_number = ?, bank_ifsc = ?, bank_name = ?, payment_qr_path = ? WHERE id = ?');
        $stmt->bind_param(
            'sssssssssi',
            $event['name'],
            $event['location'],
            $event['start_date'],
            $event['end_date'],
            $event['description'],
            $event['bank_account_number'],
            $event['bank_ifsc'],
            $event['bank_name'],
            $event['payment_qr_path'],
            $event_id
        );
        $stmt->execute();
        $stmt->close();
        $success = 'Event details updated successfully.';

        if ($delete_qr_path && $delete_qr_path !== ($event['payment_qr_path'] ?? null)) {
            $file = __DIR__ . '/' . ltrim($delete_qr_path, '/');
            if (is_file($file)) {
                @unlink($file);
            }
        }
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
                <form method="post" enctype="multipart/form-data">
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
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="bank_account_number" class="form-label">Bank Account Number</label>
                            <input type="text" class="form-control" id="bank_account_number" name="bank_account_number" value="<?php echo sanitize((string) ($event['bank_account_number'] ?? '')); ?>">
                            <div class="form-text">Provide the account number for fee payments.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="bank_ifsc" class="form-label">IFSC Code</label>
                            <input type="text" class="form-control" id="bank_ifsc" name="bank_ifsc" value="<?php echo sanitize((string) ($event['bank_ifsc'] ?? '')); ?>">
                        </div>
                    </div>
                    <div class="mb-3 mt-3">
                        <label for="bank_name" class="form-label">Bank Name</label>
                        <input type="text" class="form-control" id="bank_name" name="bank_name" value="<?php echo sanitize((string) ($event['bank_name'] ?? '')); ?>">
                    </div>
                    <div class="mb-3">
                        <label for="payment_qr" class="form-label">Payment QR Code</label>
                        <input type="file" class="form-control <?php echo isset($errors['payment_qr']) ? 'is-invalid' : ''; ?>" id="payment_qr" name="payment_qr" accept="image/png,image/jpeg,image/webp">
                        <?php if (isset($errors['payment_qr'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['payment_qr']); ?></div><?php else: ?><div class="form-text">Upload a QR image (PNG, JPG, WEBP, max 5MB).</div><?php endif; ?>
                        <?php if (!empty($event['payment_qr_path'])): ?>
                            <div class="mt-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" id="remove_qr" name="remove_qr">
                                    <label class="form-check-label" for="remove_qr">Remove current QR code image</label>
                                </div>
                                <div class="mt-2">
                                    <img src="<?php echo sanitize($event['payment_qr_path']); ?>" alt="Payment QR code" class="img-thumbnail" style="max-width: 200px;">
                                </div>
                            </div>
                        <?php endif; ?>
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
