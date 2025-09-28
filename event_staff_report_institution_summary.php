<?php
$page_title = 'Institution Wise Count Report';
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

$institutions = [];
$stmt = $db->prepare('SELECT id, name, affiliation_number FROM institutions WHERE event_id = ? ORDER BY name');
$stmt->bind_param('i', $event_id);
$stmt->execute();
$institutions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$participant_stats = [];
$stmt = $db->prepare("SELECT p.institution_id,
       SUM(CASE WHEN p.status = 'submitted' THEN 1 ELSE 0 END) AS pending_count,
       SUM(CASE WHEN p.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
       COALESCE(SUM(CASE WHEN p.status IN ('submitted', 'approved') THEN pe.fees ELSE 0 END), 0) AS total_fees
FROM participants p
LEFT JOIN participant_events pe ON pe.participant_id = p.id
WHERE p.event_id = ?
GROUP BY p.institution_id");
$stmt->bind_param('i', $event_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $participant_stats[(int) $row['institution_id']] = [
        'pending' => (int) $row['pending_count'],
        'approved' => (int) $row['approved_count'],
        'fees' => (float) $row['total_fees'],
    ];
}
$stmt->close();

$team_stats = [];
$stmt = $db->prepare("SELECT te.institution_id,
       SUM(CASE WHEN te.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
       SUM(CASE WHEN te.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
       COALESCE(SUM(CASE WHEN te.status IN ('pending', 'approved') THEN em.fees ELSE 0 END), 0) AS total_fees
FROM team_entries te
INNER JOIN event_master em ON em.id = te.event_master_id
WHERE em.event_id = ?
GROUP BY te.institution_id");
$stmt->bind_param('i', $event_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $team_stats[(int) $row['institution_id']] = [
        'pending' => (int) $row['pending_count'],
        'approved' => (int) $row['approved_count'],
        'fees' => (float) $row['total_fees'],
    ];
}
$stmt->close();

$institution_event_stats = [];
$stmt = $db->prepare("SELECT ier.institution_id,
       SUM(CASE WHEN ier.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
       SUM(CASE WHEN ier.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
       COALESCE(SUM(CASE WHEN ier.status IN ('pending', 'approved') THEN em.fees ELSE 0 END), 0) AS total_fees
FROM institution_event_registrations ier
INNER JOIN event_master em ON em.id = ier.event_master_id
WHERE em.event_id = ?
GROUP BY ier.institution_id");
$stmt->bind_param('i', $event_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $institution_event_stats[(int) $row['institution_id']] = [
        'pending' => (int) $row['pending_count'],
        'approved' => (int) $row['approved_count'],
        'fees' => (float) $row['total_fees'],
    ];
}
$stmt->close();

$fund_transfer_stats = [];
$stmt = $db->prepare("SELECT institution_id,
       COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount,
       COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) AS approved_amount
FROM fund_transfers
WHERE event_id = ?
GROUP BY institution_id");
$stmt->bind_param('i', $event_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $fund_transfer_stats[(int) $row['institution_id']] = [
        'pending' => (float) $row['pending_amount'],
        'approved' => (float) $row['approved_amount'],
    ];
}
$stmt->close();

?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Institution Wise Count</h1>
        <p class="text-muted mb-0">Summary of participant, team, and institution-level registrations with payment tracking.</p>
    </div>
</div>
<?php if (!$institutions): ?>
    <div class="alert alert-info">No institutions found for your event.</div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th rowspan="2" class="text-center">#</th>
                            <th rowspan="2">Institution</th>
                            <th rowspan="2">Affiliation Code</th>
                            <th colspan="3" class="text-center">Participants</th>
                            <th colspan="3" class="text-center">Team Entries</th>
                            <th colspan="3" class="text-center">Institution Events</th>
                            <th rowspan="2" class="text-end">Total Fees Due</th>
                            <th colspan="2" class="text-center">Fund Transfers</th>
                            <th rowspan="2" class="text-end">Balance Fee</th>
                        </tr>
                        <tr>
                            <th class="text-center">Pending</th>
                            <th class="text-center">Approved</th>
                            <th class="text-end">Total Fees</th>
                            <th class="text-center">Pending</th>
                            <th class="text-center">Approved</th>
                            <th class="text-end">Total Fees</th>
                            <th class="text-center">Pending</th>
                            <th class="text-center">Approved</th>
                            <th class="text-end">Total Fees</th>
                            <th class="text-end">Pending</th>
                            <th class="text-end">Approved</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $totals = [
                            'participant_pending' => 0,
                            'participant_approved' => 0,
                            'participant_fees' => 0.0,
                            'team_pending' => 0,
                            'team_approved' => 0,
                            'team_fees' => 0.0,
                            'institution_pending' => 0,
                            'institution_approved' => 0,
                            'institution_fees' => 0.0,
                            'total_fees_due' => 0.0,
                            'fund_pending' => 0.0,
                            'fund_approved' => 0.0,
                            'balance_fee' => 0.0,
                        ];
                        ?>
                        <?php foreach ($institutions as $index => $institution): ?>
                            <?php
                            $institution_id = (int) $institution['id'];
                            $participants = $participant_stats[$institution_id] ?? ['pending' => 0, 'approved' => 0, 'fees' => 0.0];
                            $teams = $team_stats[$institution_id] ?? ['pending' => 0, 'approved' => 0, 'fees' => 0.0];
                            $institution_events = $institution_event_stats[$institution_id] ?? ['pending' => 0, 'approved' => 0, 'fees' => 0.0];
                            $funds = $fund_transfer_stats[$institution_id] ?? ['pending' => 0.0, 'approved' => 0.0];

                            $total_fees_due = $participants['fees'] + $teams['fees'] + $institution_events['fees'];
                            $balance_fee = $total_fees_due - $funds['approved'];

                            $totals['participant_pending'] += $participants['pending'];
                            $totals['participant_approved'] += $participants['approved'];
                            $totals['participant_fees'] += $participants['fees'];
                            $totals['team_pending'] += $teams['pending'];
                            $totals['team_approved'] += $teams['approved'];
                            $totals['team_fees'] += $teams['fees'];
                            $totals['institution_pending'] += $institution_events['pending'];
                            $totals['institution_approved'] += $institution_events['approved'];
                            $totals['institution_fees'] += $institution_events['fees'];
                            $totals['total_fees_due'] += $total_fees_due;
                            $totals['fund_pending'] += $funds['pending'];
                            $totals['fund_approved'] += $funds['approved'];
                            $totals['balance_fee'] += $balance_fee;
                            ?>
                            <tr>
                                <td class="text-center"><?php echo (int) ($index + 1); ?></td>
                                <td><?php echo sanitize($institution['name']); ?></td>
                                <td><?php echo sanitize($institution['affiliation_number'] ?? ''); ?></td>
                                <td class="text-center"><?php echo (int) $participants['pending']; ?></td>
                                <td class="text-center"><?php echo (int) $participants['approved']; ?></td>
                                <td class="text-end">₹<?php echo number_format((float) $participants['fees'], 2); ?></td>
                                <td class="text-center"><?php echo (int) $teams['pending']; ?></td>
                                <td class="text-center"><?php echo (int) $teams['approved']; ?></td>
                                <td class="text-end">₹<?php echo number_format((float) $teams['fees'], 2); ?></td>
                                <td class="text-center"><?php echo (int) $institution_events['pending']; ?></td>
                                <td class="text-center"><?php echo (int) $institution_events['approved']; ?></td>
                                <td class="text-end">₹<?php echo number_format((float) $institution_events['fees'], 2); ?></td>
                                <td class="text-end">₹<?php echo number_format((float) $total_fees_due, 2); ?></td>
                                <td class="text-end">₹<?php echo number_format((float) $funds['pending'], 2); ?></td>
                                <td class="text-end">₹<?php echo number_format((float) $funds['approved'], 2); ?></td>
                                <td class="text-end">₹<?php echo number_format((float) $balance_fee, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3" class="text-end">Totals</th>
                            <th class="text-center"><?php echo (int) $totals['participant_pending']; ?></th>
                            <th class="text-center"><?php echo (int) $totals['participant_approved']; ?></th>
                            <th class="text-end">₹<?php echo number_format($totals['participant_fees'], 2); ?></th>
                            <th class="text-center"><?php echo (int) $totals['team_pending']; ?></th>
                            <th class="text-center"><?php echo (int) $totals['team_approved']; ?></th>
                            <th class="text-end">₹<?php echo number_format($totals['team_fees'], 2); ?></th>
                            <th class="text-center"><?php echo (int) $totals['institution_pending']; ?></th>
                            <th class="text-center"><?php echo (int) $totals['institution_approved']; ?></th>
                            <th class="text-end">₹<?php echo number_format($totals['institution_fees'], 2); ?></th>
                            <th class="text-end">₹<?php echo number_format($totals['total_fees_due'], 2); ?></th>
                            <th class="text-end">₹<?php echo number_format($totals['fund_pending'], 2); ?></th>
                            <th class="text-end">₹<?php echo number_format($totals['fund_approved'], 2); ?></th>
                            <th class="text-end">₹<?php echo number_format($totals['balance_fee'], 2); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
