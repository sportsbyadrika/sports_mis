<?php
$page_title = 'Edit Participant';
require_once __DIR__ . '/includes/header.php';
require_login();
require_role(['event_staff']);

$user = current_user();
$db = get_db_connection();

if (!$user['event_id']) {
    echo '<div class="alert alert-warning">No event assigned to your account. Please contact the event administrator.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$event_id = (int) $user['event_id'];
$participant_id = (int) get_param('id', 0);

if ($participant_id <= 0) {
    echo '<div class="alert alert-danger">Invalid participant reference provided.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$stmt = $db->prepare('SELECT * FROM participants WHERE id = ? AND event_id = ?');
$stmt->bind_param('ii', $participant_id, $event_id);
$stmt->execute();
$existing_participant = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing_participant) {
    echo '<div class="alert alert-danger">Participant not found or access denied.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$allowed_statuses = ['draft', 'submitted', 'approved', 'rejected'];
$errors = [];
$data = [
    'name' => $existing_participant['name'] ?? '',
    'date_of_birth' => $existing_participant['date_of_birth'] ?? '',
    'gender' => $existing_participant['gender'] ?? '',
    'guardian_name' => $existing_participant['guardian_name'] ?? '',
    'contact_number' => $existing_participant['contact_number'] ?? '',
    'address' => $existing_participant['address'] ?? '',
    'email' => $existing_participant['email'] ?? '',
    'aadhaar_number' => $existing_participant['aadhaar_number'] ?? '',
    'photo_path' => $existing_participant['photo_path'] ?? null,
    'status' => $existing_participant['status'] ?? 'draft',
];

function event_staff_process_participant_photo(?array $file, ?string $currentPath, array &$errors): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $currentPath;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors['photo'] = 'Failed to upload passport photo.';
        return null;
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        $errors['photo'] = 'Passport photo must be smaller than 2MB.';
        return null;
    }

    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        $errors['photo'] = 'Passport photo must be a JPG or PNG image.';
        return null;
    }

    $extension = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
    $uploadDir = __DIR__ . '/uploads/participants';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        $errors['photo'] = 'Unable to prepare the upload directory.';
        return null;
    }

    try {
        $random = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $random = uniqid('', true);
    }

    $filename = 'participant_' . time() . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $random) . '.' . $extension;
    $destination = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        $errors['photo'] = 'Failed to save the uploaded passport photo.';
        return null;
    }

    if ($currentPath) {
        $existing = __DIR__ . '/' . ltrim($currentPath, '/');
        if (is_file($existing)) {
            @unlink($existing);
        }
    }

    return 'uploads/participants/' . $filename;
}

if (is_post()) {
    $data = [
        'name' => trim((string) post_param('name', '')),
        'date_of_birth' => post_param('date_of_birth'),
        'gender' => post_param('gender'),
        'guardian_name' => trim((string) post_param('guardian_name', '')),
        'contact_number' => trim((string) post_param('contact_number', '')),
        'address' => trim((string) post_param('address', '')),
        'email' => trim((string) post_param('email', '')),
        'aadhaar_number' => trim((string) post_param('aadhaar_number', '')),
        'photo_path' => $existing_participant['photo_path'] ?? null,
        'status' => post_param('status', $existing_participant['status'] ?? 'draft'),
    ];

    $data['aadhaar_number'] = preg_replace('/\D+/', '', $data['aadhaar_number']);
    $photo_file = $_FILES['photo'] ?? null;

    validate_required([
        'name' => 'Participant name',
        'date_of_birth' => 'Date of birth',
        'gender' => 'Gender',
        'guardian_name' => "Guardian's name",
        'contact_number' => 'Contact number',
        'aadhaar_number' => 'Aadhaar number',
    ], $errors, array_merge($data, [
        'date_of_birth' => $data['date_of_birth'] ?? '',
        'gender' => $data['gender'] ?? '',
    ]));

    if ($data['gender'] && !in_array($data['gender'], ['Male', 'Female'], true)) {
        $errors['gender'] = 'Invalid gender selection.';
    }

    if ($data['aadhaar_number'] && !preg_match('/^\d{12}$/', $data['aadhaar_number'])) {
        $errors['aadhaar_number'] = 'Aadhaar number must contain exactly 12 digits.';
    }

    if (!in_array($data['status'], $allowed_statuses, true)) {
        $errors['status'] = 'Invalid status selected.';
        $data['status'] = $existing_participant['status'] ?? 'draft';
    }

    if (!$errors) {
        $photo_path = event_staff_process_participant_photo($photo_file, $data['photo_path'], $errors);
        if (!$errors) {
            $data['photo_path'] = $photo_path;
        }
    }

    if (!$errors) {
        $db->begin_transaction();

        $stmt = $db->prepare('SELECT status, chest_number FROM participants WHERE id = ? AND event_id = ? FOR UPDATE');
        $stmt->bind_param('ii', $participant_id, $event_id);
        $stmt->execute();
        $current_state = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$current_state) {
            $db->rollback();
            set_flash('error', 'Unable to update the participant at this time.');
            redirect('event_staff_participant_lookup.php');
        }

        $chest_number_to_assign = null;

        if ($data['status'] === 'approved') {
            if (!empty($current_state['chest_number'])) {
                $chest_number_to_assign = (int) $current_state['chest_number'];
            } else {
                $result = $db->query('SELECT MAX(chest_number) AS max_chest FROM participants WHERE chest_number IS NOT NULL');
                $max = $result ? (int) ($result->fetch_assoc()['max_chest'] ?? 0) : 0;
                $chest_number_to_assign = $max > 0 ? $max + 1 : 1001;
            }
        }

        $sql = 'UPDATE participants SET name = ?, date_of_birth = ?, gender = ?, guardian_name = ?, contact_number = ?, address = ?, email = ?, aadhaar_number = ?, photo_path = ?, status = ?, updated_at = NOW()';
        $types = 'sssssssss';
        $types .= 's';
        $params = [
            $data['name'],
            $data['date_of_birth'],
            $data['gender'],
            $data['guardian_name'],
            $data['contact_number'],
            $data['address'],
            $data['email'],
            $data['aadhaar_number'],
            $data['photo_path'],
            $data['status'],
        ];

        if ($data['status'] === 'approved') {
            $sql .= ', chest_number = ?';
            $types .= 'i';
            $params[] = $chest_number_to_assign;
        } else {
            $sql .= ', chest_number = NULL';
        }

        $sql .= ' WHERE id = ? AND event_id = ?';
        $types .= 'ii';
        $params[] = $participant_id;
        $params[] = $event_id;

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            $db->rollback();
            $errors['general'] = 'Failed to prepare the update statement.';
        } else {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            if ($stmt->errno) {
                $errors['general'] = 'Failed to update the participant. Please try again.';
                $stmt->close();
                $db->rollback();
            } else {
                $stmt->close();
                $db->commit();

                $message = 'Participant updated successfully.';
                if ($data['status'] === 'approved' && $chest_number_to_assign) {
                    $message .= ' Assigned chest number: ' . $chest_number_to_assign . '.';
                }

                set_flash('success', $message);
                redirect('event_staff_participant_view.php?participant_id=' . $participant_id);
            }
        }
    }
}

$success_message = get_flash('success');
$error_message = get_flash('error');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Edit Participant</h1>
        <p class="text-muted mb-0">Update participant information and manage their registration status.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="event_staff_participant_view.php?participant_id=<?php echo (int) $participant_id; ?>" class="btn btn-outline-secondary">
            <i class="bi bi-eye me-2"></i>View Participant
        </a>
        <a href="event_staff_participant_lookup.php" class="btn btn-outline-primary">
            <i class="bi bi-search me-2"></i>Back to Lookup
        </a>
    </div>
</div>
<?php if (!empty($errors['general'])): ?>
    <div class="alert alert-danger"><?php echo sanitize($errors['general']); ?></div>
<?php endif; ?>
<?php if ($success_message): ?>
    <div class="alert alert-success"><?php echo sanitize($success_message); ?></div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger"><?php echo sanitize($error_message); ?></div>
<?php endif; ?>
<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-6">
                <label for="name" class="form-label">Participant Name</label>
                <input type="text" name="name" id="name" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($data['name']); ?>">
                <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['name']); ?></div><?php endif; ?>
            </div>
            <div class="col-md-3">
                <label for="date_of_birth" class="form-label">Date of Birth</label>
                <input type="date" name="date_of_birth" id="date_of_birth" class="form-control <?php echo isset($errors['date_of_birth']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($data['date_of_birth']); ?>">
                <?php if (isset($errors['date_of_birth'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['date_of_birth']); ?></div><?php endif; ?>
            </div>
            <div class="col-md-3">
                <label for="gender" class="form-label">Gender</label>
                <select name="gender" id="gender" class="form-select <?php echo isset($errors['gender']) ? 'is-invalid' : ''; ?>">
                    <option value="">Select</option>
                    <option value="Male" <?php echo $data['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                    <option value="Female" <?php echo $data['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                </select>
                <?php if (isset($errors['gender'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['gender']); ?></div><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label for="guardian_name" class="form-label">Guardian's Name</label>
                <input type="text" name="guardian_name" id="guardian_name" class="form-control <?php echo isset($errors['guardian_name']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($data['guardian_name']); ?>">
                <?php if (isset($errors['guardian_name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['guardian_name']); ?></div><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label for="contact_number" class="form-label">Contact Number</label>
                <input type="text" name="contact_number" id="contact_number" class="form-control <?php echo isset($errors['contact_number']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($data['contact_number']); ?>">
                <?php if (isset($errors['contact_number'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['contact_number']); ?></div><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label for="email" class="form-label">Email</label>
                <input type="email" name="email" id="email" class="form-control" value="<?php echo sanitize($data['email']); ?>">
            </div>
            <div class="col-md-6">
                <label for="aadhaar_number" class="form-label">Aadhaar Number</label>
                <input type="text" name="aadhaar_number" id="aadhaar_number" class="form-control <?php echo isset($errors['aadhaar_number']) ? 'is-invalid' : ''; ?>" value="<?php echo sanitize($data['aadhaar_number']); ?>">
                <?php if (isset($errors['aadhaar_number'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['aadhaar_number']); ?></div><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label for="status" class="form-label">Registration Status</label>
                <select name="status" id="status" class="form-select <?php echo isset($errors['status']) ? 'is-invalid' : ''; ?>">
                    <?php foreach ($allowed_statuses as $status): ?>
                        <option value="<?php echo $status; ?>" <?php echo $data['status'] === $status ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['status'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['status']); ?></div><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label for="address" class="form-label">Address</label>
                <textarea name="address" id="address" rows="3" class="form-control"><?php echo sanitize($data['address']); ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">Current Passport Photo</label>
                <?php if (!empty($data['photo_path'])): ?>
                    <div class="mb-2">
                        <img src="<?php echo sanitize($data['photo_path']); ?>" alt="Passport photo" class="img-thumbnail" style="max-width: 160px;">
                    </div>
                <?php else: ?>
                    <div class="text-muted">No photo uploaded</div>
                <?php endif; ?>
                <label for="photo" class="form-label">Upload New Photo</label>
                <input type="file" name="photo" id="photo" class="form-control <?php echo isset($errors['photo']) ? 'is-invalid' : ''; ?>" accept="image/jpeg,image/png">
                <?php if (isset($errors['photo'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['photo']); ?></div><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label">Chest Number</label>
                <input type="text" class="form-control" value="<?php echo !empty($existing_participant['chest_number']) ? sanitize((string) $existing_participant['chest_number']) : 'Not assigned'; ?>" readonly>
                <div class="form-text">Chest number is automatically assigned when the participant is approved.</div>
            </div>
            <div class="col-12 d-flex justify-content-end gap-2 mt-3">
                <a href="event_staff_participant_view.php?participant_id=<?php echo (int) $participant_id; ?>" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
