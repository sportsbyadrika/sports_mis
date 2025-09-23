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
$selected_event_id = (int) get_param('event_id', 0) ?: $assigned_event_id;

// Ensure the selected event is restricted to the staff member's assignment.
if ($selected_event_id !== $assigned_event_id) {
    $selected_event_id = $assigned_event_id;
}

$search = trim((string) get_param('q', ''));

$events = [];
$stmt = $db->prepare('SELECT id, name FROM events WHERE id = ? ORDER BY name');
$stmt->bind_param('i', $assigned_event_id);
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$types = 'i';
$params = [$selected_event_id];
$sql = "SELECT p.name, p.gender, p.contact_number, p.status, i.name AS institution_name
        FROM participants p
        LEFT JOIN institutions i ON i.id = p.institution_id
        WHERE p.event_id = ?";

if ($search !== '') {
    $sql .= ' AND p.name LIKE ?';
    $params[] = '%' . $search . '%';
    $types .= 's';
}

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
                <label for="event_id" class="form-label">Event</label>
                <select name="event_id" id="event_id" class="form-select">
                    <?php foreach ($events as $event): ?>
                        <option value="<?php echo (int) $event['id']; ?>" <?php echo $event['id'] == $selected_event_id ? 'selected' : ''; ?>>
                            <?php echo sanitize($event['name']); ?>
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
                            <th>Name</th>
                            <th>Institution</th>
                            <th>Gender</th>
                            <th>Contact</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($participants as $participant): ?>
                            <tr>
                                <td><?php echo sanitize($participant['name']); ?></td>
                                <td><?php echo sanitize($participant['institution_name'] ?? ''); ?></td>
                                <td><?php echo sanitize($participant['gender']); ?></td>
                                <td><?php echo sanitize($participant['contact_number']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $participant['status'] === 'submitted' ? 'success' : 'secondary'; ?> text-uppercase">
                                        <?php echo sanitize($participant['status']); ?>
                                    </span>
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
