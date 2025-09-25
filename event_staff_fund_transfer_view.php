<?php
$page_title = 'Fund Transfer Details';
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
$transfer_id = (int) get_param('transfer_id', 0);

if ($transfer_id <= 0) {
    echo '<div class="alert alert-danger">Invalid transfer reference provided.</div>';
    echo '<a href="event_staff_fund_transfers.php" class="btn btn-outline-secondary mt-3">Back to Fund Transfers</a>';
    include __DIR__ . '/includes/footer.php';
    return;
}

function fetch_transfer(mysqli $db, int $transfer_id, int $event_id): ?array
{
    $sql = "SELECT ft.*, i.name AS institution_name, i.contact_number AS institution_contact,
                   submitter.name AS submitted_by_name, submitter.email AS submitted_by_email,
                   reviewer.name AS reviewed_by_name
            FROM fund_transfers ft
            INNER JOIN institutions i ON i.id = ft.institution_id
            LEFT JOIN users submitter ON submitter.id = ft.submitted_by
            LEFT JOIN users reviewer ON reviewer.id = ft.reviewed_by
            WHERE ft.id = ? AND ft.event_id = ?
            LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $transfer_id, $event_id);
    $stmt->execute();
    $transfer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $transfer ?: null;
}

if (is_post()) {
    $action = (string) post_param('action', '');
    $remarks = trim((string) post_param('remarks', ''));

    if (!in_array($action, ['approve', 'reject'], true)) {
        set_flash('error', 'Invalid action requested.');
        redirect('event_staff_fund_transfer_view.php?transfer_id=' . $transfer_id);
    }

    if ($action === 'reject' && $remarks === '') {
        set_flash('error', 'Please provide remarks when rejecting a fund transfer.');
        redirect('event_staff_fund_transfer_view.php?transfer_id=' . $transfer_id);
    }

    $db->begin_transaction();

    $stmt = $db->prepare('SELECT status FROM fund_transfers WHERE id = ? AND event_id = ? FOR UPDATE');
    $stmt->bind_param('ii', $transfer_id, $event_id);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$current) {
        $db->rollback();
        set_flash('error', 'Fund transfer not found or access denied.');
        redirect('event_staff_fund_transfers.php');
    }

    $status = $current['status'];

    if ($action === 'approve') {
        if ($status === 'approved') {
            $db->rollback();
            set_flash('error', 'This fund transfer has already been approved.');
            redirect('event_staff_fund_transfer_view.php?transfer_id=' . $transfer_id);
        }

        $stmt = $db->prepare('UPDATE fund_transfers SET status = "approved", reviewed_by = ?, reviewed_at = NOW(), remarks = ? WHERE id = ?');
        $stmt->bind_param('isi', $user['id'], $remarks, $transfer_id);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        set_flash('success', 'Fund transfer marked as approved.');
        redirect('event_staff_fund_transfer_view.php?transfer_id=' . $transfer_id);
    }

    if ($action === 'reject') {
        if ($status === 'rejected') {
            $db->rollback();
            set_flash('error', 'This fund transfer has already been rejected.');
            redirect('event_staff_fund_transfer_view.php?transfer_id=' . $transfer_id);
        }

        $stmt = $db->prepare('UPDATE fund_transfers SET status = "rejected", reviewed_by = ?, reviewed_at = NOW(), remarks = ? WHERE id = ?');
        $stmt->bind_param('isi', $user['id'], $remarks, $transfer_id);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        set_flash('success', 'Fund transfer marked as rejected.');
        redirect('event_staff_fund_transfer_view.php?transfer_id=' . $transfer_id);
    }

    $db->rollback();
    set_flash('error', 'Unable to process the requested action.');
    redirect('event_staff_fund_transfer_view.php?transfer_id=' . $transfer_id);
}

$transfer = fetch_transfer($db, $transfer_id, $event_id);

if (!$transfer) {
    echo '<div class="alert alert-danger">Fund transfer not found or access denied.</div>';
    echo '<a href="event_staff_fund_transfers.php" class="btn btn-outline-secondary mt-3">Back to Fund Transfers</a>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$success_message = get_flash('success');
$error_message = get_flash('error');

$status_classes = [
    'pending' => 'warning',
    'approved' => 'success',
    'rejected' => 'danger',
];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Fund Transfer Details</h1>
        <p class="text-muted mb-0">Review submission from <?php echo sanitize($transfer['institution_name']); ?>.</p>
    </div>
    <div>
        <a href="event_staff_fund_transfers.php" class="btn btn-outline-secondary">Back to Fund Transfers</a>
    </div>
</div>

<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo sanitize($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo sanitize($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Submission Information</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Institution</dt>
                    <dd class="col-sm-8"><?php echo sanitize($transfer['institution_name']); ?></dd>

                    <dt class="col-sm-4">Submitted By</dt>
                    <dd class="col-sm-8">
                        <?php echo sanitize($transfer['submitted_by_name'] ?? 'Unknown'); ?><br>
                        <span class="text-muted small"><?php echo sanitize($transfer['submitted_by_email'] ?? ''); ?></span>
                    </dd>

                    <dt class="col-sm-4">Transfer Date</dt>
                    <dd class="col-sm-8"><?php echo sanitize(format_date($transfer['transfer_date'])); ?></dd>

                    <dt class="col-sm-4">Mode</dt>
                    <dd class="col-sm-8"><?php echo sanitize($transfer['mode']); ?></dd>

                    <dt class="col-sm-4">Amount</dt>
                    <dd class="col-sm-8">₹<?php echo number_format((float) $transfer['amount'], 2); ?></dd>

                    <dt class="col-sm-4">Transaction Number</dt>
                    <dd class="col-sm-8"><?php echo sanitize($transfer['transaction_number']); ?></dd>

                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        <span class="badge bg-<?php echo $status_classes[$transfer['status']] ?? 'secondary'; ?> text-uppercase">
                            <?php echo sanitize($transfer['status']); ?>
                        </span>
                        <?php if ($transfer['reviewed_at']): ?>
                            <div class="text-muted small mt-1">
                                Reviewed on <?php echo sanitize(date('d M Y H:i', strtotime($transfer['reviewed_at']))); ?>
                                <?php if ($transfer['reviewed_by_name']): ?>
                                    <br>By <?php echo sanitize($transfer['reviewed_by_name']); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-4">Reference Document</dt>
                    <dd class="col-sm-8">
                        <a href="<?php echo sanitize($transfer['reference_document_path']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">View / Download</a>
                    </dd>

                    <?php if ($transfer['remarks']): ?>
                        <dt class="col-sm-4">Remarks</dt>
                        <dd class="col-sm-8"><?php echo nl2br(sanitize($transfer['remarks'])); ?></dd>
                    <?php endif; ?>

                    <dt class="col-sm-4">Submitted On</dt>
                    <dd class="col-sm-8"><?php echo sanitize(date('d M Y H:i', strtotime($transfer['created_at']))); ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 mb-3">Review Actions</h2>
                <?php if ($transfer['status'] === 'pending'): ?>
                    <form method="post" class="mb-3">
                        <div class="mb-3">
                            <label for="remarks" class="form-label">Remarks (optional for approval)</label>
                            <textarea name="remarks" id="remarks" class="form-control" rows="3" placeholder="Add remarks for the institution (required when rejecting)"></textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" name="action" value="approve" class="btn btn-success">Approve</button>
                            <button type="submit" name="action" value="reject" class="btn btn-danger">Reject</button>
                        </div>
                    </form>
                    <div class="alert alert-info mb-0">
                        Provide remarks when rejecting the request so the institution understands the reason.
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">This fund transfer has already been <?php echo sanitize($transfer['status']); ?>.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
