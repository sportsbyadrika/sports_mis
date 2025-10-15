<?php
$page_title = 'Institution Financial Snapshot';
require_once __DIR__ . '/includes/auth.php';

require_login();
require_role(['event_staff']);

require_once __DIR__ . '/includes/header.php';

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

$participant_count = 0;
$participant_fees = 0.0;
$stmt = $db->prepare(
    "SELECT COUNT(DISTINCT pe.participant_id) AS participant_count, COALESCE(SUM(pe.fees), 0) AS total_fees
    FROM participant_events pe
    JOIN participants p ON p.id = pe.participant_id
    WHERE p.institution_id = ? AND p.status IN ('submitted', 'approved')"
);
$stmt->bind_param('i', $selected_institution_id);
$stmt->execute();
$stmt->bind_result($participant_count_result, $participant_fees_result);
if ($stmt->fetch()) {
    $participant_count = (int) $participant_count_result;
    $participant_fees = (float) $participant_fees_result;
}
$stmt->close();

$team_entry_count = 0;
$team_entry_fees = 0.0;
$stmt = $db->prepare(
    "SELECT COUNT(*) AS entry_count, COALESCE(SUM(em.fees), 0) AS total_fees
    FROM team_entries te
    JOIN event_master em ON em.id = te.event_master_id
    WHERE te.institution_id = ? AND te.status IN ('pending', 'approved')"
);
$stmt->bind_param('i', $selected_institution_id);
$stmt->execute();
$stmt->bind_result($team_entry_count_result, $team_entry_fees_result);
if ($stmt->fetch()) {
    $team_entry_count = (int) $team_entry_count_result;
    $team_entry_fees = (float) $team_entry_fees_result;
}
$stmt->close();

$institution_event_count = 0;
$institution_event_fees = 0.0;
$stmt = $db->prepare(
    "SELECT COUNT(*) AS registration_count, COALESCE(SUM(em.fees), 0) AS total_fees
    FROM institution_event_registrations ier
    JOIN event_master em ON em.id = ier.event_master_id
    WHERE ier.institution_id = ? AND ier.status IN ('pending', 'approved')"
);
$stmt->bind_param('i', $selected_institution_id);
$stmt->execute();
$stmt->bind_result($institution_event_count_result, $institution_event_fees_result);
if ($stmt->fetch()) {
    $institution_event_count = (int) $institution_event_count_result;
    $institution_event_fees = (float) $institution_event_fees_result;
}
$stmt->close();

$total_fee_due = $participant_fees + $team_entry_fees + $institution_event_fees;

$fund_pending = 0.0;
$fund_approved = 0.0;
$stmt = $db->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount,
        COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) AS approved_amount
    FROM fund_transfers
    WHERE event_id = ? AND institution_id = ?"
);
$stmt->bind_param('ii', $event_id, $selected_institution_id);
$stmt->execute();
$stmt->bind_result($fund_pending_result, $fund_approved_result);
if ($stmt->fetch()) {
    $fund_pending = (float) $fund_pending_result;
    $fund_approved = (float) $fund_approved_result;
}
$stmt->close();

$fund_total = $fund_pending + $fund_approved;
$balance = max($total_fee_due - $fund_approved, 0.0);
$dues_cleared = $total_fee_due <= $fund_approved;
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
        <span class="badge bg-primary">Finance</span>
    </div>
    <div class="card-body">
        <div class="row g-4 align-items-stretch">
            <div class="col-lg-6">
                <div class="text-muted text-uppercase small mb-2">Fee Due Breakdown</div>
                <?php
                    $fee_breakdown = [
                        [
                            'label' => 'Participant Fees',
                            'count' => $participant_count,
                            'count_label' => 'Participants',
                            'amount' => $participant_fees,
                        ],
                        [
                            'label' => 'Team Entry Fees',
                            'count' => $team_entry_count,
                            'count_label' => 'Teams',
                            'amount' => $team_entry_fees,
                        ],
                        [
                            'label' => 'Institution Event Fees',
                            'count' => $institution_event_count,
                            'count_label' => 'Events',
                            'amount' => $institution_event_fees,
                        ],
                    ];
                ?>
                <?php foreach ($fee_breakdown as $item): ?>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <div>
                            <span class="d-block"><?php echo sanitize($item['label']); ?></span>
                            <span class="small text-muted"><?php echo number_format((int) $item['count']); ?> <?php echo sanitize($item['count_label']); ?></span>
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
