<?php
require_once __DIR__ . '/includes/header.php';
require_login();

$db = get_db_connection();
$user = current_user();

function fetch_count(mysqli $db, string $query, string $types = '', array $params = []): int
{
    $stmt = $db->prepare($query);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return (int) $count;
}

function fetch_sum(mysqli $db, string $query, string $types = '', array $params = []): float
{
    $stmt = $db->prepare($query);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $stmt->bind_result($sum);
    $stmt->fetch();
    $stmt->close();

    return $sum !== null ? (float) $sum : 0.0;
}

$cards = [];
$fee_summary = null;
$event_financial_summary = null;
$news_items = [];

switch ($user['role']) {
    case 'super_admin':
        $cards[] = [
            'label' => 'Events',
            'icon' => 'bi-calendar-event',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM events'),
            'link' => 'events.php',
        ];
        $cards[] = [
            'label' => 'Event Admins',
            'icon' => 'bi-people',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM users WHERE role = 'event_admin'"),
            'link' => 'event_admins.php',
        ];
        $cards[] = [
            'label' => 'Institutions',
            'icon' => 'bi-building',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM institutions'),
            'link' => 'events.php',
        ];
        $cards[] = [
            'label' => 'Participants',
            'icon' => 'bi-people-fill',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM participants'),
            'link' => 'events.php',
        ];
        $cards[] = [
            'label' => 'Institution Event Registrations',
            'icon' => 'bi-building-check',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM institution_event_registrations'),
            'link' => 'events.php',
        ];
        $cards[] = [
            'label' => 'Pending Institution Approvals',
            'icon' => 'bi-hourglass-split',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM institution_event_registrations WHERE status = 'pending'"),
            'link' => 'institutions.php',
        ];
        break;
    case 'event_admin':
        if (!$user['event_id']) {
            echo '<div class="alert alert-warning">No event assigned to your account. Please contact the super administrator.</div>';
            include __DIR__ . '/includes/footer.php';
            return;
        }
        $event_id = (int) $user['event_id'];
        $stmt = $db->prepare('SELECT name, start_date, end_date, location FROM events WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $event_id);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $cards[] = [
            'label' => 'Participating Institutions',
            'icon' => 'bi-building',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM institutions WHERE event_id = ?', 'i', [$event_id]),
            'link' => 'institutions.php',
        ];
        $cards[] = [
            'label' => 'Institution Admins',
            'icon' => 'bi-person-badge',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM users WHERE role = 'institution_admin' AND event_id = ?", 'i', [$event_id]),
            'link' => 'institution_admins.php',
        ];
        $cards[] = [
            'label' => 'Event Staff',
            'icon' => 'bi-person-gear',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM users WHERE role = 'event_staff' AND event_id = ?", 'i', [$event_id]),
            'link' => 'event_staff.php',
        ];
        $cards[] = [
            'label' => 'Institution Event Registrations',
            'icon' => 'bi-building-check',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM institution_event_registrations ier JOIN event_master em ON em.id = ier.event_master_id WHERE em.event_id = ?', 'i', [$event_id]),
            'link' => 'institutions.php',
        ];
        $cards[] = [
            'label' => 'Pending Institution Approvals',
            'icon' => 'bi-hourglass-split',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM institution_event_registrations ier JOIN event_master em ON em.id = ier.event_master_id WHERE em.event_id = ? AND ier.status = 'pending'", 'i', [$event_id]),
            'link' => 'institutions.php',
        ];
        $cards[] = [
            'label' => 'Participants',
            'icon' => 'bi-people-fill',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM participants WHERE event_id = ?', 'i', [$event_id]),
            'link' => 'institutions.php',
        ];
        echo '<div class="mb-4">';
        echo '<h1 class="h3 mb-1">' . sanitize($event['name'] ?? 'Event Dashboard') . '</h1>';
        if ($event) {
            echo '<p class="text-muted mb-0">' . sanitize($event['location'] ?? '') . ' | ' . format_date($event['start_date'] ?? null);
            if (!empty($event['end_date'])) {
                echo ' - ' . format_date($event['end_date']);
            }
            echo '</p>';
        }
        echo '</div>';
        break;
    case 'institution_admin':
        if (!$user['institution_id']) {
            echo '<div class="alert alert-warning">No institution assigned to your account. Please contact the event administrator.</div>';
            include __DIR__ . '/includes/footer.php';
            return;
        }
        $institution_id = (int) $user['institution_id'];
        $stmt = $db->prepare('SELECT name, event_id FROM institutions WHERE id = ?');
        $stmt->bind_param('i', $institution_id);
        $stmt->execute();
        $institution = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $cards[] = [
            'label' => 'Total Participants',
            'icon' => 'bi-people',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM participants WHERE institution_id = ?', 'i', [$institution_id]),
            'link' => 'participants.php',
        ];
        $cards[] = [
            'label' => 'Approved Participants',
            'icon' => 'bi-person-check',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM participants WHERE institution_id = ? AND status = 'approved'", 'i', [$institution_id]),
            'link' => 'participants.php',
        ];
        $cards[] = [
            'label' => 'Pending Team Entries',
            'icon' => 'bi-hourglass-split',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM team_entries WHERE institution_id = ? AND status = 'pending'", 'i', [$institution_id]),
            'link' => 'institution_team_entries.php',
        ];
        $cards[] = [
            'label' => 'Approved Team Entries',
            'icon' => 'bi-clipboard-check',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM team_entries WHERE institution_id = ? AND status = 'approved'", 'i', [$institution_id]),
            'link' => 'institution_team_entries.php',
        ];
        $cards[] = [
            'label' => 'Pending Institution Events',
            'icon' => 'bi-hourglass',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM institution_event_registrations WHERE institution_id = ? AND status = 'pending'", 'i', [$institution_id]),
            'link' => 'institution_event_registrations.php',
        ];
        $cards[] = [
            'label' => 'Approved Institution Events',
            'icon' => 'bi-check2-circle',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM institution_event_registrations WHERE institution_id = ? AND status = 'approved'", 'i', [$institution_id]),
            'link' => 'institution_event_registrations.php',
        ];
        $institution_events_count = fetch_count($db, 'SELECT COUNT(DISTINCT em.event_id) FROM institution_event_registrations ier JOIN event_master em ON em.id = ier.event_master_id WHERE ier.institution_id = ?', 'i', [$institution_id]);
        if ($institution_events_count === 0 && !empty($institution['event_id'])) {
            $institution_events_count = 1;
        }
        $participant_fees = fetch_sum($db, "SELECT COALESCE(SUM(pe.fees), 0) FROM participant_events pe JOIN participants p ON p.id = pe.participant_id WHERE p.institution_id = ? AND p.status IN ('submitted', 'approved')", 'i', [$institution_id]);
        $team_entry_fees = fetch_sum($db, "SELECT COALESCE(SUM(em.fees), 0) FROM team_entries te JOIN event_master em ON em.id = te.event_master_id WHERE te.institution_id = ? AND te.status IN ('pending', 'approved')", 'i', [$institution_id]);
        $institution_event_fees = fetch_sum($db, "SELECT COALESCE(SUM(em.fees), 0) FROM institution_event_registrations ier JOIN event_master em ON em.id = ier.event_master_id WHERE ier.institution_id = ? AND ier.status IN ('pending', 'approved')", 'i', [$institution_id]);
        $total_due = $participant_fees + $team_entry_fees + $institution_event_fees;
        $fee_paid = fetch_sum($db, "SELECT COALESCE(SUM(amount), 0) FROM fund_transfers WHERE institution_id = ? AND status = 'approved'", 'i', [$institution_id]);
        $fund_pending = fetch_sum($db, "SELECT COALESCE(SUM(amount), 0) FROM fund_transfers WHERE institution_id = ? AND status = 'pending'", 'i', [$institution_id]);
        $balance = max($total_due - $fee_paid, 0.0);

        $fee_summary = [
            'participant_fees' => $participant_fees,
            'team_entry_fees' => $team_entry_fees,
            'institution_event_fees' => $institution_event_fees,
            'total_due' => $total_due,
            'fee_paid' => $fee_paid,
            'balance' => $balance,
        ];
        $event_financial_summary = [
            'events_count' => $institution_events_count,
            'participant_fees' => $participant_fees,
            'team_entry_fees' => $team_entry_fees,
            'institution_event_fees' => $institution_event_fees,
            'total_due' => $total_due,
            'fund_pending' => $fund_pending,
            'fund_approved' => $fee_paid,
            'fund_total' => $fund_pending + $fee_paid,
            'balance' => $balance,
        ];
        echo '<div class="mb-4">';
        echo '<h1 class="h3">' . sanitize($institution['name'] ?? 'Institution Dashboard') . '</h1>';
        echo '</div>';
        if (!empty($institution['event_id'])) {
            $news_items = fetch_event_news($db, (int) $institution['event_id']);
        }
        break;
    case 'event_staff':
        if (!$user['event_id']) {
            echo '<div class="alert alert-warning">No event assigned to your account. Please contact the event administrator.</div>';
            include __DIR__ . '/includes/footer.php';
            return;
        }
        $event_id = (int) $user['event_id'];
        $cards[] = [
            'label' => 'Participants',
            'icon' => 'bi-people-fill',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM participants WHERE event_id = ?', 'i', [$event_id]),
            'link' => 'event_staff_participants.php?event_id=' . $event_id,
        ];
        $cards[] = [
            'label' => 'Institutions',
            'icon' => 'bi-building',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM institutions WHERE event_id = ?', 'i', [$event_id]),
            'link' => 'institutions.php',
        ];
        $cards[] = [
            'label' => 'Team Entries',
            'icon' => 'bi-people',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM team_entries te JOIN event_master em ON em.id = te.event_master_id WHERE em.event_id = ?', 'i', [$event_id]),
            'link' => 'event_staff_team_entries.php',
        ];
        $cards[] = [
            'label' => 'Pending Institution Events',
            'icon' => 'bi-hourglass-split',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM institution_event_registrations ier JOIN event_master em ON em.id = ier.event_master_id WHERE em.event_id = ? AND ier.status = 'pending'", 'i', [$event_id]),
            'link' => 'institution_event_registrations.php',
        ];
        $cards[] = [
            'label' => 'Approved Institution Events',
            'icon' => 'bi-check-circle',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM institution_event_registrations ier JOIN event_master em ON em.id = ier.event_master_id WHERE em.event_id = ? AND ier.status = 'approved'", 'i', [$event_id]),
            'link' => 'institution_event_registrations.php',
        ];
        $participant_fees = fetch_sum($db, "SELECT COALESCE(SUM(pe.fees), 0) FROM participant_events pe JOIN participants p ON p.id = pe.participant_id WHERE p.event_id = ? AND p.status IN ('submitted', 'approved')", 'i', [$event_id]);
        $team_entry_fees = fetch_sum($db, "SELECT COALESCE(SUM(em.fees), 0) FROM team_entries te JOIN event_master em ON em.id = te.event_master_id WHERE em.event_id = ? AND te.status IN ('pending', 'approved')", 'i', [$event_id]);
        $institution_event_fees = fetch_sum($db, "SELECT COALESCE(SUM(em.fees), 0) FROM institution_event_registrations ier JOIN event_master em ON em.id = ier.event_master_id WHERE em.event_id = ? AND ier.status IN ('pending', 'approved')", 'i', [$event_id]);
        $total_due = $participant_fees + $team_entry_fees + $institution_event_fees;
        $fund_pending = fetch_sum($db, "SELECT COALESCE(SUM(amount), 0) FROM fund_transfers WHERE event_id = ? AND status = 'pending'", 'i', [$event_id]);
        $fund_approved = fetch_sum($db, "SELECT COALESCE(SUM(amount), 0) FROM fund_transfers WHERE event_id = ? AND status = 'approved'", 'i', [$event_id]);
        $balance = max($total_due - $fund_approved, 0.0);
        $event_financial_summary = [
            'events_count' => fetch_count($db, 'SELECT COUNT(*) FROM events WHERE id = ?', 'i', [$event_id]),
            'participant_fees' => $participant_fees,
            'team_entry_fees' => $team_entry_fees,
            'institution_event_fees' => $institution_event_fees,
            'total_due' => $total_due,
            'fund_pending' => $fund_pending,
            'fund_approved' => $fund_approved,
            'fund_total' => $fund_pending + $fund_approved,
            'balance' => $balance,
        ];
        echo '<div class="mb-4">';
        $stmt = $db->prepare('SELECT name FROM events WHERE id = ?');
        $stmt->bind_param('i', $event_id);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        echo '<h1 class="h3">' . sanitize($event['name'] ?? 'Event Staff Dashboard') . '</h1>';
        echo '</div>';
        $news_items = fetch_event_news($db, $event_id);
        break;
}
?>
<div class="row g-4">
    <?php foreach ($cards as $card): ?>
        <div class="col-12 col-md-6 col-xl-3">
            <a href="<?php echo sanitize($card['link']); ?>" class="text-decoration-none text-reset">
                <div class="card card-dashboard shadow-sm h-100">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted text-uppercase small"><?php echo sanitize($card['label']); ?></div>
                            <div class="display-6 fw-bold"><?php echo (int) $card['count']; ?></div>
                        </div>
                        <i class="<?php echo sanitize($card['icon']); ?> text-accent fs-1"></i>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
<?php if ($news_items): ?>
    <div class="card shadow-sm mt-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Latest Event News</h2>
            <span class="badge bg-info">Updates</span>
        </div>
        <div class="card-body">
            <?php foreach ($news_items as $index => $news): ?>
                <div class="<?php echo $index === array_key_last($news_items) ? '' : 'border-bottom pb-3 mb-3'; ?>">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h3 class="h6 mb-1"><?php echo sanitize($news['title']); ?></h3>
                        </div>
                        <span class="small text-muted"><?php echo date('d M Y', strtotime($news['created_at'])); ?></span>
                    </div>
                    <p class="mb-2 small"><?php echo nl2br(sanitize($news['content'])); ?></p>
                    <?php if (!empty($news['url'])): ?>
                        <a href="<?php echo sanitize($news['url']); ?>" class="small" target="_blank" rel="noopener">Read more</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
<?php if ($fee_summary): ?>
    <div class="card shadow-sm mt-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Fee Summary</h2>
            <span class="badge bg-primary">Financial Overview</span>
        </div>
        <div class="card-body">
            <div class="row g-4 align-items-stretch">
                <div class="col-lg-7">
                    <div class="text-muted text-uppercase small mb-2">Fee Due Breakdown</div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span>Participant Fees</span>
                        <span class="fw-semibold">₹<?php echo number_format($fee_summary['participant_fees'], 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span>Team Entry Fees</span>
                        <span class="fw-semibold">₹<?php echo number_format($fee_summary['team_entry_fees'], 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span>Institution Event Fees</span>
                        <span class="fw-semibold">₹<?php echo number_format($fee_summary['institution_event_fees'], 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between pt-3">
                        <span class="fw-semibold text-uppercase">Total Fee Due</span>
                        <span class="fs-5 fw-bold">₹<?php echo number_format($fee_summary['total_due'], 2); ?></span>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="border rounded h-100 p-3 bg-light d-flex flex-column justify-content-center">
                        <div class="d-flex justify-content-between mb-3">
                            <span class="fw-semibold">Fee Paid</span>
                            <span class="fs-5">₹<?php echo number_format($fee_summary['fee_paid'], 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="fw-semibold text-uppercase">Balance</span>
                            <span class="fs-4 fw-bold text-danger">₹<?php echo number_format($fee_summary['balance'], 2); ?></span>
                        </div>
                        <?php if ($fee_summary['balance'] <= 0): ?>
                            <div class="small text-success mt-3">All dues have been settled.</div>
                        <?php else: ?>
                            <div class="small text-muted mt-3">Submit approved fund transfers to clear the outstanding balance.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php if ($event_financial_summary): ?>
    <div class="card shadow-sm mt-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Event Financial Snapshot</h2>
            <div class="d-flex align-items-center gap-2">
                <?php if (isset($event_financial_summary['events_count'])): ?>
                    <span class="badge bg-success-subtle text-success-emphasis">Events: <?php echo (int) $event_financial_summary['events_count']; ?></span>
                <?php endif; ?>
                <span class="badge bg-primary">Finance</span>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-4 align-items-stretch">
                <div class="col-lg-6">
                    <div class="text-muted text-uppercase small mb-2">Fee Due Breakdown</div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span>Participant Fees</span>
                        <span class="fw-semibold">₹<?php echo number_format($event_financial_summary['participant_fees'], 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span>Team Entry Fees</span>
                        <span class="fw-semibold">₹<?php echo number_format($event_financial_summary['team_entry_fees'], 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span>Institution Event Fees</span>
                        <span class="fw-semibold">₹<?php echo number_format($event_financial_summary['institution_event_fees'], 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between pt-3">
                        <span class="fw-semibold text-uppercase">Total Fee Due</span>
                        <span class="fs-5 fw-bold">₹<?php echo number_format($event_financial_summary['total_due'], 2); ?></span>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="border rounded h-100 p-3 bg-light d-flex flex-column">
                        <div class="text-muted text-uppercase small mb-2">Fund Receipts</div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span>Pending Approval</span>
                            <span class="fw-semibold">₹<?php echo number_format($event_financial_summary['fund_pending'], 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span>Approved</span>
                            <span class="fw-semibold">₹<?php echo number_format($event_financial_summary['fund_approved'], 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between pt-3">
                            <span class="fw-semibold">Total Received</span>
                            <span class="fs-5 fw-bold">₹<?php echo number_format($event_financial_summary['fund_total'], 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mt-3">
                            <span class="fw-semibold text-uppercase">Balance</span>
                            <span class="fs-4 fw-bold <?php echo $event_financial_summary['balance'] <= 0 ? 'text-success' : 'text-danger'; ?>">₹<?php echo number_format($event_financial_summary['balance'], 2); ?></span>
                        </div>
                        <?php if ($event_financial_summary['balance'] <= 0): ?>
                            <div class="small text-success mt-2">All dues have been cleared for this event.</div>
                        <?php else: ?>
                            <div class="small text-muted mt-2">Additional approved fund transfers are required to settle the outstanding balance.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
