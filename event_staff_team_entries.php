<?php
$page_title = 'Team Entries Approval';
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

$assigned_event_id = (int) $user['event_id'];
$selected_institution_id = (int) get_param('institution_id', 0);
$status_filter = trim((string) get_param('status', ''));
$allowed_statuses = ['pending', 'approved', 'rejected'];

if (is_post()) {
    $action = post_param('action');
    if ($action === 'update_status') {
        $team_entry_id = (int) post_param('id');
        $new_status = post_param('status');

        if (!in_array($new_status, $allowed_statuses, true)) {
            set_flash('error', 'Invalid status selected.');
            redirect('event_staff_team_entries.php');
        }

        $stmt = $db->prepare('SELECT te.id FROM team_entries te JOIN event_master em ON em.id = te.event_master_id WHERE te.id = ? AND em.event_id = ?');
        $stmt->bind_param('ii', $team_entry_id, $assigned_event_id);
        $stmt->execute();
        $team_entry = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$team_entry) {
            set_flash('error', 'Unable to locate the selected team entry.');
            redirect('event_staff_team_entries.php');
        }

        if ($new_status === 'pending') {
            $stmt = $db->prepare('UPDATE team_entries SET status = ?, reviewed_by = NULL, reviewed_at = NULL, updated_at = NOW() WHERE id = ?');
            $stmt->bind_param('si', $new_status, $team_entry_id);
        } else {
            $reviewed_by = (int) $user['id'];
            $stmt = $db->prepare('UPDATE team_entries SET status = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?');
            $stmt->bind_param('sii', $new_status, $reviewed_by, $team_entry_id);
        }
        $stmt->execute();
        $stmt->close();

        set_flash('success', 'Team entry status updated successfully.');
        redirect('event_staff_team_entries.php');
    }
}

$stmt = $db->prepare('SELECT DISTINCT i.id, i.name
    FROM team_entries te
    JOIN institutions i ON i.id = te.institution_id
    JOIN event_master em ON em.id = te.event_master_id
    WHERE em.event_id = ?
    ORDER BY i.name');
$stmt->bind_param('i', $assigned_event_id);
$stmt->execute();
$institutions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sql = 'SELECT te.id, te.team_name, te.status, te.submitted_at, te.reviewed_at, i.name AS institution_name,
               em.name AS event_name, em.code, em.label, em.fees,
               u1.name AS submitted_by_name, u2.name AS reviewed_by_name
        FROM team_entries te
        JOIN institutions i ON i.id = te.institution_id
        JOIN event_master em ON em.id = te.event_master_id
        LEFT JOIN users u1 ON u1.id = te.submitted_by
        LEFT JOIN users u2 ON u2.id = te.reviewed_by
        WHERE em.event_id = ?';

$params = [$assigned_event_id];
$types = 'i';

if ($selected_institution_id > 0) {
    $sql .= ' AND te.institution_id = ?';
    $params[] = $selected_institution_id;
    $types .= 'i';
}

if ($status_filter !== '' && in_array($status_filter, $allowed_statuses, true)) {
    $sql .= ' AND te.status = ?';
    $params[] = $status_filter;
    $types .= 's';
}

$sql .= ' ORDER BY te.submitted_at DESC';

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$team_entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$team_entry_ids = array_map(static fn(array $entry): int => (int) $entry['id'], $team_entries);
$team_members = [];

if ($team_entry_ids) {
    $placeholders = implode(',', array_fill(0, count($team_entry_ids), '?'));
    $types = str_repeat('i', count($team_entry_ids));

    $stmt = $db->prepare(
        "SELECT tem.team_entry_id, p.id AS participant_id, p.name, p.chest_number
         FROM team_entry_members tem
         JOIN participants p ON p.id = tem.participant_id
         WHERE tem.team_entry_id IN ($placeholders)
         ORDER BY p.name"
    );
    $stmt->bind_param($types, ...$team_entry_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $team_members[(int) $row['team_entry_id']][] = $row;
    }
    $stmt->close();
}

$status_classes = [
    'pending' => 'bg-warning text-dark',
    'approved' => 'bg-success',
    'rejected' => 'bg-danger',
];

$success_message = get_flash('success');
$error_message = get_flash('error');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Team Entries</h1>
        <p class="text-muted mb-0">Review and approve team entries submitted by participating institutions.</p>
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
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="institution_id" class="form-label">Institution</label>
                <select name="institution_id" id="institution_id" class="form-select">
                    <option value="0">All Institutions</option>
                    <?php foreach ($institutions as $institution): ?>
                        <option value="<?php echo (int) $institution['id']; ?>" <?php echo $institution['id'] == $selected_institution_id ? 'selected' : ''; ?>>
                            <?php echo sanitize($institution['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="status" class="form-label">Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($allowed_statuses as $status): ?>
                        <option value="<?php echo $status; ?>" <?php echo $status === $status_filter ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="event_staff_team_entries.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <?php if (!$team_entries): ?>
            <p class="text-muted mb-0">No team entries found for the selected filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Team</th>
                            <th>Institution</th>
                            <th>Event</th>
                            <th class="text-end">Fees (₹)</th>
                            <th>Participants</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($team_entries as $entry): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo sanitize($entry['team_name']); ?></div>
                                    <div class="small text-muted">Submitted on <?php echo sanitize(date('d M Y H:i', strtotime($entry['submitted_at']))); ?><?php if (!empty($entry['submitted_by_name'])): ?> by <?php echo sanitize($entry['submitted_by_name']); ?><?php endif; ?></div>
                                </td>
                                <td><?php echo sanitize($entry['institution_name']); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo sanitize($entry['event_name']); ?></div>
                                    <div class="text-muted small"><?php echo sanitize($entry['code']); ?><?php if (!empty($entry['label'])): ?> &middot; <?php echo sanitize($entry['label']); ?><?php endif; ?></div>
                                </td>
                                <td class="text-end">₹<?php echo number_format((float) ($entry['fees'] ?? 0), 2); ?></td>
                                <td>
                                    <?php $members = $team_members[$entry['id']] ?? []; ?>
                                    <?php if ($members): ?>
                                        <ul class="list-unstyled mb-0 small">
                                            <?php foreach ($members as $member): ?>
                                                <li>
                                                    <?php echo sanitize($member['name']); ?>
                                                    <?php if (!empty($member['chest_number'])): ?>
                                                        <span class="text-muted">(Chest <?php echo sanitize((string) $member['chest_number']); ?>)</span>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <span class="text-muted small">No participants listed.</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $badge_class = $status_classes[$entry['status']] ?? 'bg-secondary'; ?>
                                    <span class="badge <?php echo $badge_class; ?> text-uppercase"><?php echo sanitize($entry['status']); ?></span>
                                    <?php if (!empty($entry['reviewed_at'])): ?>
                                        <div class="small text-muted mt-1">Reviewed on <?php echo sanitize(date('d M Y H:i', strtotime($entry['reviewed_at']))); ?><?php if (!empty($entry['reviewed_by_name'])): ?> by <?php echo sanitize($entry['reviewed_by_name']); ?><?php endif; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <form method="post" class="d-flex align-items-center justify-content-end gap-2">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="id" value="<?php echo (int) $entry['id']; ?>">
                                        <select name="status" class="form-select form-select-sm" style="max-width: 140px;">
                                            <?php foreach ($allowed_statuses as $status): ?>
                                                <option value="<?php echo $status; ?>" <?php echo $entry['status'] === $status ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                    </form>
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
