<?php
require_once __DIR__ . '/includes/header.php';
require_login();
require_role(['institution_admin']);

$user = current_user();
$db = get_db_connection();
$role = $user['role'];
$can_manage = $role === 'institution_admin';

if (!$can_manage) {
    echo '<div class="alert alert-danger">Only institution administrators can manage participants.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

if (!$user['institution_id']) {
    echo '<div class="alert alert-warning">No institution assigned to your account. Please contact the event administrator.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$institution_id = (int) $user['institution_id'];
$stmt = $db->prepare('SELECT id, name, event_id FROM institutions WHERE id = ?');
$stmt->bind_param('i', $institution_id);
$stmt->execute();
$institution_context = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$institution_context) {
    echo '<div class="alert alert-danger">Unable to load institution information.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}
$event_id = (int) $institution_context['event_id'];

$participant_id = (int) get_param('id', 0);
$is_edit = $participant_id > 0;
$existing_participant = null;

if ($is_edit) {
    $stmt = $db->prepare('SELECT * FROM participants WHERE id = ? AND institution_id = ?');
    $stmt->bind_param('ii', $participant_id, $institution_id);
    $stmt->execute();
    $existing_participant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$existing_participant) {
        echo '<div class="alert alert-danger">Participant not found.</div>';
        include __DIR__ . '/includes/footer.php';
        return;
    }
    if ($existing_participant['status'] === 'submitted') {
        echo '<div class="alert alert-warning">Submitted participants cannot be edited.</div>';
        include __DIR__ . '/includes/footer.php';
        return;
    }
}

$errors = [];
$data = [
    'name' => '',
    'date_of_birth' => '',
    'gender' => '',
    'guardian_name' => '',
    'contact_number' => '',
    'address' => '',
    'email' => '',
    'aadhaar_number' => '',
    'photo_path' => $existing_participant['photo_path'] ?? null,
];

if ($existing_participant) {
    $data = array_merge($data, [
        'name' => $existing_participant['name'] ?? '',
        'date_of_birth' => $existing_participant['date_of_birth'] ?? '',
        'gender' => $existing_participant['gender'] ?? '',
        'guardian_name' => $existing_participant['guardian_name'] ?? '',
        'contact_number' => $existing_participant['contact_number'] ?? '',
        'address' => $existing_participant['address'] ?? '',
        'email' => $existing_participant['email'] ?? '',
        'aadhaar_number' => $existing_participant['aadhaar_number'] ?? '',
    ]);
}

function process_participant_photo(?array $file, ?string $currentPath, array &$errors): ?string
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
    ];

    $data['aadhaar_number'] = preg_replace('/\D+/', '', $data['aadhaar_number']);
    $photo_file = $_FILES['photo'] ?? null;

    if (!$is_edit && (!$photo_file || ($photo_file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
        $errors['photo'] = 'Passport photo is required.';
    }

    validate_required([
        'name' => 'Participant name',
        'date_of_birth' => 'Date of birth',
        'gender' => 'Gender',
        'guardian_name' => "Guardian's name",
        'contact_number' => 'Contact number',
        'aadhaar_number' => 'Aadhaar number',
    ], $errors, array_merge($data, ['date_of_birth' => $data['date_of_birth'] ?? '', 'gender' => $data['gender'] ?? '']));

    if ($data['gender'] && !in_array($data['gender'], ['Male', 'Female'], true)) {
        $errors['gender'] = 'Invalid gender selection.';
    }

    if ($data['aadhaar_number'] && !preg_match('/^\d{12}$/', $data['aadhaar_number'])) {
        $errors['aadhaar_number'] = 'Aadhaar number must contain exactly 12 digits.';
    }

    if (!$errors) {
        $photo_path = process_participant_photo($photo_file, $data['photo_path'], $errors);
        if (!$errors) {
            $data['photo_path'] = $photo_path;
        }
    }

    if (!$errors) {
        if ($is_edit) {
            $stmt = $db->prepare('UPDATE participants SET name = ?, date_of_birth = ?, gender = ?, guardian_name = ?, contact_number = ?, address = ?, email = ?, aadhaar_number = ?, photo_path = ?, updated_at = NOW() WHERE id = ? AND institution_id = ?');
            $stmt->bind_param('sssssssssii', $data['name'], $data['date_of_birth'], $data['gender'], $data['guardian_name'], $data['contact_number'], $data['address'], $data['email'], $data['aadhaar_number'], $data['photo_path'], $participant_id, $institution_id);
            $stmt->execute();
            $stmt->close();
            set_flash('success', 'Participant updated successfully.');
        } else {
            $stmt = $db->prepare("INSERT INTO participants (institution_id, event_id, name, date_of_birth, gender, guardian_name, contact_number, address, email, aadhaar_number, photo_path, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)");
            $created_by = $user['id'];
            $stmt->bind_param('iisssssssssi', $institution_id, $event_id, $data['name'], $data['date_of_birth'], $data['gender'], $data['guardian_name'], $data['contact_number'], $data['address'], $data['email'], $data['aadhaar_number'], $data['photo_path'], $created_by);
            $stmt->execute();
            $stmt->close();
            set_flash('success', 'Participant created successfully.');
        }
        redirect('participants.php');
    }
}

?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0"><?php echo $is_edit ? 'Edit Participant' : 'Add Participant'; ?></h1>
        <p class="text-muted mb-0"><?php echo $is_edit ? 'Update the participant details.' : 'Register a new participant for your institution.'; ?></p>
    </div>
    <a href="participants.php" class="btn btn-outline-secondary">Back to Participants</a>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <?php if (isset($errors['general'])): ?>
            <div class="alert alert-danger"><?php echo sanitize($errors['general']); ?></div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="name">Full Name</label>
                <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" id="name" name="name" value="<?php echo sanitize($data['name']); ?>" required>
                <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['name']); ?></div><?php endif; ?>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="date_of_birth">Date of Birth</label>
                <input type="date" class="form-control <?php echo isset($errors['date_of_birth']) ? 'is-invalid' : ''; ?>" id="date_of_birth" name="date_of_birth" value="<?php echo sanitize($data['date_of_birth']); ?>" required>
                <?php if (isset($errors['date_of_birth'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['date_of_birth']); ?></div><?php endif; ?>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="gender">Gender</label>
                <select class="form-select <?php echo isset($errors['gender']) ? 'is-invalid' : ''; ?>" id="gender" name="gender" required>
                    <option value="">Select Gender</option>
                    <option value="Male" <?php echo $data['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                    <option value="Female" <?php echo $data['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                </select>
                <?php if (isset($errors['gender'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['gender']); ?></div><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="guardian_name">Guardian Name</label>
                <input type="text" class="form-control <?php echo isset($errors['guardian_name']) ? 'is-invalid' : ''; ?>" id="guardian_name" name="guardian_name" value="<?php echo sanitize($data['guardian_name']); ?>" required>
                <?php if (isset($errors['guardian_name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['guardian_name']); ?></div><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="contact_number">Contact Number</label>
                <input type="text" class="form-control <?php echo isset($errors['contact_number']) ? 'is-invalid' : ''; ?>" id="contact_number" name="contact_number" value="<?php echo sanitize($data['contact_number']); ?>" required>
                <?php if (isset($errors['contact_number'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['contact_number']); ?></div><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="aadhaar_number">Aadhaar Number</label>
                <input type="text" class="form-control <?php echo isset($errors['aadhaar_number']) ? 'is-invalid' : ''; ?>" id="aadhaar_number" name="aadhaar_number" value="<?php echo sanitize($data['aadhaar_number']); ?>" required>
                <?php if (isset($errors['aadhaar_number'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['aadhaar_number']); ?></div><?php endif; ?>
                <div class="form-text">Enter the 12-digit Aadhaar number for the participant.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="email">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?php echo sanitize($data['email']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="photo">Passport Photo</label>
                <?php if (!empty($data['photo_path'])): ?>
                    <div class="mb-2">
                        <img src="<?php echo sanitize($data['photo_path']); ?>" alt="Passport photo preview" class="img-thumbnail" style="max-width: 140px;">
                    </div>
                <?php endif; ?>
                <input type="file" class="form-control <?php echo isset($errors['photo']) ? 'is-invalid' : ''; ?>" id="photo" name="photo" accept="image/png,image/jpeg" <?php echo $data['photo_path'] ? '' : 'required'; ?>>
                <?php if (isset($errors['photo'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['photo']); ?></div><?php endif; ?>
                <div class="form-text">Upload a JPG or PNG image up to 2MB.</div>
            </div>
            <div class="col-12">
                <label class="form-label" for="address">Address</label>
                <textarea class="form-control" id="address" name="address" rows="3"><?php echo sanitize($data['address']); ?></textarea>
            </div>
            <div class="col-12 d-flex justify-content-end gap-2 mt-3">
                <a href="participants.php" class="btn btn-outline-secondary">Cancel</a>
                <button class="btn btn-primary" type="submit"><?php echo $is_edit ? 'Update Participant' : 'Create Participant'; ?></button>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
