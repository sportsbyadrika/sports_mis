<?php
$page_title = 'Fund Transfer Submissions';
require_once __DIR__ . '/includes/header.php';

require_login();
require_role(['institution_admin']);

$user = current_user();
$db = get_db_connection();

if (!$user['institution_id']) {
    echo '<div class="alert alert-warning">No institution assigned to your account. Please contact the event administrator.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$institution_id = (int) $user['institution_id'];
$stmt = $db->prepare('SELECT id, name, event_id FROM institutions WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $institution_id);
$stmt->execute();
$institution = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$institution) {
    echo '<div class="alert alert-danger">Unable to locate institution details.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$event_id = (int) $institution['event_id'];
$errors = [];
$form_values = [
    'transfer_date' => date('Y-m-d'),
    'mode' => 'NEFT',
    'amount' => '',
    'transaction_number' => '',
];

function handle_reference_upload(?array $file, array &$errors): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors['reference_document'] = 'Reference document is required.';
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors['reference_document'] = 'Failed to upload the reference document.';
        return null;
    }

    $allowed_mimes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
    if ($finfo) {
        finfo_close($finfo);
    }

    if (!$mime || !array_key_exists($mime, $allowed_mimes)) {
        $errors['reference_document'] = 'The reference document must be a PDF or image (JPG/PNG).';
        return null;
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        $errors['reference_document'] = 'Reference document must be smaller than 5MB.';
        return null;
    }

    $extension = $allowed_mimes[$mime];
    $upload_dir = __DIR__ . '/uploads/fund_transfers';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true) && !is_dir($upload_dir)) {
        $errors['reference_document'] = 'Unable to prepare the upload directory.';
        return null;
    }

    try {
        $random = bin2hex(random_bytes(5));
    } catch (Throwable $e) {
        $random = uniqid('', true);
    }

    $filename = 'fund_' . time() . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $random) . '.' . $extension;
    $destination = $upload_dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        $errors['reference_document'] = 'Failed to save the uploaded document.';
        return null;
    }

    return 'uploads/fund_transfers/' . $filename;
}

if (is_post()) {
    $form_values = [
        'transfer_date' => trim((string) post_param('transfer_date', '')),
        'mode' => trim((string) post_param('mode', '')),
        'amount' => trim((string) post_param('amount', '')),
        'transaction_number' => trim((string) post_param('transaction_number', '')),
    ];

    validate_required([
        'transfer_date' => 'Transfer date',
        'mode' => 'Mode of transfer',
        'amount' => 'Amount transferred',
        'transaction_number' => 'Transaction number',
    ], $errors, $form_values);

    $modes = ['NEFT', 'UPI', 'Other'];
    if ($form_values['mode'] && !in_array($form_values['mode'], $modes, true)) {
        $errors['mode'] = 'Invalid mode selected.';
    }

    $transfer_date = null;
    if ($form_values['transfer_date']) {
        $date = DateTime::createFromFormat('Y-m-d', $form_values['transfer_date']);
        if ($date && $date->format('Y-m-d') === $form_values['transfer_date']) {
            $transfer_date = $date->format('Y-m-d');
        } else {
            $errors['transfer_date'] = 'Please provide a valid transfer date.';
        }
    }

    $amount = null;
    if ($form_values['amount'] !== '') {
        $amount = filter_var($form_values['amount'], FILTER_VALIDATE_FLOAT);
        if ($amount === false || $amount <= 0) {
            $errors['amount'] = 'Amount must be a positive number.';
        }
    }

    $document_path = null;
    if (!$errors) {
        $document_path = handle_reference_upload($_FILES['reference_document'] ?? null, $errors);
    }

    if (!$errors && $transfer_date && $amount !== null && $document_path) {
        $stmt = $db->prepare('INSERT INTO fund_transfers (event_id, institution_id, submitted_by, transfer_date, mode, amount, transaction_number, reference_document_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending")');
        $submitted_by = (int) $user['id'];
        $transaction_number = $form_values['transaction_number'];
        $mode = $form_values['mode'];
        $stmt->bind_param('iiissdss', $event_id, $institution_id, $submitted_by, $transfer_date, $mode, $amount, $transaction_number, $document_path);
        $stmt->execute();
        $stmt->close();

        set_flash('success', 'Fund transfer submitted successfully for verification.');
        redirect('institution_fund_transfers.php');
    }
}

$success_message = get_flash('success');

$stmt = $db->prepare('SELECT ft.id, ft.transfer_date, ft.mode, ft.amount, ft.transaction_number, ft.status, ft.reference_document_path, ft.created_at, ft.remarks, ft.reviewed_at, u.name AS reviewed_by_name
    FROM fund_transfers ft
    LEFT JOIN users u ON u.id = ft.reviewed_by
    WHERE ft.institution_id = ?
    ORDER BY ft.created_at DESC');
$stmt->bind_param('i', $institution_id);
$stmt->execute();
$transfers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$status_classes = [
    'pending' => 'warning',
    'approved' => 'success',
    'rejected' => 'danger',
];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Fund Transfer Submissions</h1>
        <p class="text-muted mb-0">Submit transfer details for the institution: <?php echo sanitize($institution['name']); ?>.</p>
    </div>
</div>

<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo sanitize($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Unable to submit the fund transfer.</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $message): ?>
                <li><?php echo sanitize($message); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0">New Fund Transfer</h2>
            </div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" novalidate>
                    <div class="mb-3">
                        <label for="transfer_date" class="form-label">Date of Fund Transfer</label>
                        <input type="date" name="transfer_date" id="transfer_date" class="form-control" value="<?php echo sanitize($form_values['transfer_date']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="mode" class="form-label">Mode of Transfer</label>
                        <select name="mode" id="mode" class="form-select" required>
                            <?php foreach (['NEFT', 'UPI', 'Other'] as $mode_option): ?>
                                <option value="<?php echo $mode_option; ?>" <?php echo $form_values['mode'] === $mode_option ? 'selected' : ''; ?>><?php echo $mode_option; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="amount" class="form-label">Amount Transferred (₹)</label>
                        <input type="number" step="0.01" min="0" name="amount" id="amount" class="form-control" value="<?php echo sanitize($form_values['amount']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="transaction_number" class="form-label">Transaction Number</label>
                        <input type="text" name="transaction_number" id="transaction_number" class="form-control" value="<?php echo sanitize($form_values['transaction_number']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="reference_document" class="form-label">Upload Reference Document</label>
                        <input type="file" name="reference_document" id="reference_document" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                        <div class="form-text">Accepted formats: PDF, JPG, PNG. Max size 5MB.</div>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Submit Transfer Details</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0">Submission History</h2>
            </div>
            <div class="card-body">
                <?php if (!$transfers): ?>
                    <p class="text-muted mb-0">No fund transfer submissions have been recorded yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Mode</th>
                                    <th scope="col" class="text-end">Amount (₹)</th>
                                    <th scope="col">Transaction No.</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="text-end">Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transfers as $transfer): ?>
                                    <tr>
                                        <td><?php echo sanitize(format_date($transfer['transfer_date'])); ?></td>
                                        <td><?php echo sanitize($transfer['mode']); ?></td>
                                        <td class="text-end">₹<?php echo number_format((float) $transfer['amount'], 2); ?></td>
                                        <td><?php echo sanitize($transfer['transaction_number']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $status_classes[$transfer['status']] ?? 'secondary'; ?> text-uppercase">
                                                <?php echo sanitize($transfer['status']); ?>
                                            </span>
                                            <?php if ($transfer['status'] !== 'pending' && $transfer['reviewed_at']): ?>
                                                <div class="small text-muted">
                                                    <?php echo sanitize('Reviewed on ' . format_date($transfer['reviewed_at'])); ?>
                                                    <?php if ($transfer['reviewed_by_name']): ?>
                                                        <br><span>By <?php echo sanitize($transfer['reviewed_by_name']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($transfer['remarks']): ?>
                                                <div class="small text-muted">Remarks: <?php echo sanitize($transfer['remarks']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="<?php echo sanitize($transfer['reference_document_path']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
