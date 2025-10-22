<?php
$page_title = 'Institution Financial Snapshot';
require_once __DIR__ . '/includes/auth.php';

require_login();
require_role(['event_staff']);

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/institution_financial_snapshot.php';

$db = get_db_connection();
$user = current_user();

if (!$user['event_id']) {
    echo '<div class="alert alert-warning">No event assigned to your account. Please contact the event administrator.</div>';
    include __DIR__ . '/includes/footer.php';

    return;
}

$event_id = (int) $user['event_id'];

$institutions = [];
$stmt = $db->prepare('SELECT id, name, affiliation_number FROM institutions WHERE event_id = ? ORDER BY name');
$stmt->bind_param('i', $event_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $institutions[(int) $row['id']] = $row;
}
$stmt->close();

if (!$institutions) {
    ?>
    <div class="alert alert-info">No institutions found for your event.</div>
    <?php
    include __DIR__ . '/includes/footer.php';

    return;
}

$selected_institution_id = (int) get_param('institution_id', 0);
if ($selected_institution_id <= 0 || !array_key_exists($selected_institution_id, $institutions)) {
    $selected_institution_id = (int) array_key_first($institutions);
}

$selected_institution = $institutions[$selected_institution_id];

$snapshot = get_institution_financial_snapshot($db, $event_id, $selected_institution_id);
$receipt_url = 'event_staff_institution_financial_receipt.php?institution_id=' . (int) $selected_institution_id;

$participant_count = $snapshot['participant_count'];
$participant_event_count = $snapshot['participant_event_count'];
$participant_fees = $snapshot['participant_fees'];
$team_entry_count = $snapshot['team_entry_count'];
$team_entry_fees = $snapshot['team_entry_fees'];
$institution_event_count = $snapshot['institution_event_count'];
$institution_event_fees = $snapshot['institution_event_fees'];
$total_fee_due = $snapshot['total_fee_due'];
$fund_pending = $snapshot['fund_pending'];
$fund_approved = $snapshot['fund_approved'];
$fund_total = $snapshot['fund_total'];
$balance = $snapshot['balance'];
$dues_cleared = $snapshot['dues_cleared'];
$fee_breakdown = $snapshot['fee_breakdown'];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Institution Financial Snapshot</h1>
        <p class="text-muted mb-0">Review fee dues and fund receipts for a selected institution.</p>
    </div>
</div>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-6 col-lg-4">
                <label for="institution" class="form-label">Institution</label>
                <select id="institution" name="institution_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($institutions as $institution_id => $institution): ?>
                        <option value="<?php echo (int) $institution_id; ?>" <?php echo $institution_id === $selected_institution_id ? 'selected' : ''; ?>>
                            <?php echo sanitize($institution['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">View Report</button>
            </div>
            <div class="col-12 col-md">
                <div class="bg-light rounded p-3 h-100">
                    <div class="small text-muted text-uppercase">Affiliation Code</div>
                    <div class="fw-semibold fs-6 mb-0"><?php echo sanitize($selected_institution['affiliation_number'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </form>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h2 class="h6 mb-0"><?php echo sanitize($selected_institution['name']); ?></h2>
            <div class="small text-muted">Participants: <?php echo number_format($participant_count); ?> · Teams: <?php echo number_format($team_entry_count); ?> · Institution Events: <?php echo number_format($institution_event_count); ?></div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary">Finance</span>
            <a href="<?php echo sanitize($receipt_url); ?>" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-printer"></i> Print Receipt
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-4 align-items-stretch">
            <div class="col-lg-6">
                <div class="text-muted text-uppercase small mb-2">Fee Due Breakdown</div>
                <?php foreach ($fee_breakdown as $item): ?>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <div>
                            <span class="d-block">
                                <?php if (!empty($item['link'])): ?>
                                    <a href="<?php echo sanitize($item['link']); ?>" target="_blank" rel="noopener noreferrer" class="text-decoration-none">
                                        <?php echo sanitize($item['label']); ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo sanitize($item['label']); ?>
                                <?php endif; ?>
                            </span>
                            <span class="small text-muted">
                                <?php foreach ($item['counts'] as $index => $count): ?>
                                    <?php if ($index > 0): ?>&middot; <?php endif; ?>
                                    <?php echo number_format((int) $count['value']); ?> <?php echo sanitize($count['label']); ?>
                                <?php endforeach; ?>
                            </span>
                        </div>
                        <span class="fw-semibold">₹<?php echo number_format((float) $item['amount'], 2); ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="d-flex justify-content-between pt-3">
                    <span class="fw-semibold text-uppercase">Total Fee Due</span>
                    <span class="fs-5 fw-bold">₹<?php echo number_format($total_fee_due, 2); ?></span>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="border rounded h-100 p-3 bg-light d-flex flex-column">
                    <div class="text-muted text-uppercase small mb-2">Fund Receipts</div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span>Pending Approval</span>
                        <span class="fw-semibold">₹<?php echo number_format($fund_pending, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span>Approved</span>
                        <span class="fw-semibold">₹<?php echo number_format($fund_approved, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between pt-3">
                        <span class="fw-semibold">Total Received</span>
                        <span class="fs-5 fw-bold">₹<?php echo number_format($fund_total, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mt-3">
                        <span class="fw-semibold text-uppercase">Balance</span>
                        <span class="fs-4 fw-bold <?php echo $dues_cleared ? 'text-success' : 'text-danger'; ?>">₹<?php echo number_format($balance, 2); ?></span>
                    </div>
                    <?php if ($dues_cleared): ?>
                        <div class="small text-success mt-2">All dues have been cleared for this institution.</div>
                    <?php else: ?>
                        <div class="small text-muted mt-2">Additional approved fund transfers are required to settle the outstanding balance.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
