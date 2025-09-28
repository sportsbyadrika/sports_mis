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

$assigned_event_id = (int) $user['event_id'];
$search = trim((string) get_param('q', ''));
$selected_institution_id = (int) get_param('institution_id', 0);

// Load institutions that have participants registered for the assigned event.
$institutions = [];
$stmt = $db->prepare('SELECT DISTINCT i.id, i.name
    FROM participants p
    INNER JOIN institutions i ON i.id = p.institution_id
    WHERE p.event_id = ?
    ORDER BY i.name');
$stmt->bind_param('i', $assigned_event_id);
$stmt->execute();
$institutions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$types = 'i';
$params = [$assigned_event_id];
$sql = "SELECT p.id, p.name, p.gender, p.contact_number, p.status, p.chest_number,
               i.name AS institution_name,
               COUNT(CASE WHEN em.event_type = 'Individual' THEN pe.id END) AS event_count,
               COALESCE(SUM(CASE WHEN em.event_type = 'Individual' THEN pe.fees ELSE 0 END), 0) AS total_fees
        FROM participants p
        LEFT JOIN institutions i ON i.id = p.institution_id
        LEFT JOIN participant_events pe ON pe.participant_id = p.id
        LEFT JOIN event_master em ON em.id = pe.event_master_id
        WHERE p.event_id = ?";

if ($selected_institution_id > 0) {
    $sql .= ' AND p.institution_id = ?';
    $params[] = $selected_institution_id;
    $types .= 'i';
}

if ($search !== '') {
    $sql .= ' AND p.name LIKE ?';
    $params[] = '%' . $search . '%';
    $types .= 's';
}

$sql .= ' GROUP BY p.id, p.name, p.gender, p.contact_number, p.status, p.chest_number, i.name';
$sql .= ' ORDER BY p.name';

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Event Participants</h1>
        <p class="text-muted mb-0">Review the participant registrations submitted for your event.</p>
    </div>
</div>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="institution_id" class="form-label">Participating Institution</label>
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
                <label for="participant_name" class="form-label">Participant Name</label>
                <input type="text" name="q" id="participant_name" class="form-control" value="<?php echo sanitize($search); ?>" placeholder="Search by name">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="event_staff_participants.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <?php if (count($participants) === 0): ?>
            <p class="mb-0 text-muted">No participants found for the selected filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th>Name</th>
                            <th>Institution</th>
                            <th>Gender</th>
                            <th>Contact</th>
                            <th class="text-center">Participating Events</th>
                            <th class="text-end">Total Fees</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $status_classes = [
                            'draft' => 'secondary',
                            'submitted' => 'primary',
                            'approved' => 'success',
                            'rejected' => 'danger',
                        ];
                        ?>
                        <?php foreach ($participants as $index => $participant): ?>
                            <tr>
                                <td><?php echo (int) ($index + 1); ?></td>
                                <td><?php echo sanitize($participant['name']); ?></td>
                                <td><?php echo sanitize($participant['institution_name'] ?? ''); ?></td>
                                <td><?php echo sanitize($participant['gender']); ?></td>
                                <td><?php echo sanitize($participant['contact_number']); ?></td>
                                <td class="text-center"><?php echo (int) $participant['event_count']; ?></td>
                                <td class="text-end">₹<?php echo number_format((float) $participant['total_fees'], 2); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $status_classes[$participant['status']] ?? 'secondary'; ?> text-uppercase">
                                        <?php echo sanitize($participant['status']); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="event_staff_participant_view.php?participant_id=<?php echo (int) $participant['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        View
                                    </a>
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
