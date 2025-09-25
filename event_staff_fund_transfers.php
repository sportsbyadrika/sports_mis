<?php
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
$status_filter = (string) get_param('status', 'all');
$institution_filter = (int) get_param('institution_id', 0);

$statuses = [
    'all' => 'All Statuses',
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
];

$stmt = $db->prepare('SELECT DISTINCT i.id, i.name FROM fund_transfers ft INNER JOIN institutions i ON i.id = ft.institution_id WHERE ft.event_id = ? ORDER BY i.name');
$stmt->bind_param('i', $event_id);
$stmt->execute();
$institutions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sql = "SELECT ft.id, ft.transfer_date, ft.mode, ft.amount, ft.transaction_number, ft.status, ft.created_at, ft.reference_document_path,
               i.name AS institution_name,
               submitter.name AS submitted_by_name
        FROM fund_transfers ft
        INNER JOIN institutions i ON i.id = ft.institution_id
        LEFT JOIN users submitter ON submitter.id = ft.submitted_by
        WHERE ft.event_id = ?";
$types = 'i';
$params = [$event_id];

if ($institution_filter > 0) {
    $sql .= ' AND ft.institution_id = ?';
    $types .= 'i';
    $params[] = $institution_filter;
}

if ($status_filter !== 'all' && array_key_exists($status_filter, $statuses)) {
    $sql .= ' AND ft.status = ?';
    $types .= 's';
    $params[] = $status_filter;
}

$sql .= ' ORDER BY ft.created_at DESC';

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$transfers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flash_success = get_flash('success');
$flash_error = get_flash('error');

$status_classes = [
    'pending' => 'warning',
    'approved' => 'success',
    'rejected' => 'danger',
];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Fund Transfer Requests</h1>
        <p class="text-muted mb-0">Review and manage fund transfer submissions from participating institutions.</p>
    </div>
</div>

<?php if ($flash_success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo sanitize($flash_success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($flash_error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo sanitize($flash_error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="institution_id" class="form-label">Participating Institution</label>
                <select name="institution_id" id="institution_id" class="form-select">
                    <option value="0">All Institutions</option>
                    <?php foreach ($institutions as $institution): ?>
                        <option value="<?php echo (int) $institution['id']; ?>" <?php echo $institution['id'] == $institution_filter ? 'selected' : ''; ?>>
                            <?php echo sanitize($institution['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="status" class="form-label">Status</label>
                <select name="status" id="status" class="form-select">
                    <?php foreach ($statuses as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo $status_filter === $key ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="event_staff_fund_transfers.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?php if (!$transfers): ?>
            <p class="text-muted mb-0">No fund transfer submissions match the selected filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Institution</th>
                            <th scope="col">Submitted By</th>
                            <th scope="col">Transfer Date</th>
                            <th scope="col">Mode</th>
                            <th scope="col" class="text-end">Amount (₹)</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transfers as $index => $transfer): ?>
                            <tr>
                                <td><?php echo (int) ($index + 1); ?></td>
                                <td><?php echo sanitize($transfer['institution_name']); ?></td>
                                <td><?php echo sanitize($transfer['submitted_by_name'] ?? 'N/A'); ?></td>
                                <td><?php echo sanitize(format_date($transfer['transfer_date'])); ?></td>
                                <td><?php echo sanitize($transfer['mode']); ?></td>
                                <td class="text-end">₹<?php echo number_format((float) $transfer['amount'], 2); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $status_classes[$transfer['status']] ?? 'secondary'; ?> text-uppercase">
                                        <?php echo sanitize($transfer['status']); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="event_staff_fund_transfer_view.php?transfer_id=<?php echo (int) $transfer['id']; ?>" class="btn btn-sm btn-outline-primary">View Details</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
