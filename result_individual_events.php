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

$sql = "SELECT em.id, em.name, em.label, em.gender, ac.name AS age_category,
               COUNT(DISTINCT CASE WHEN p.status = 'approved' THEN p.id END) AS approved_participants,
               COUNT(DISTINCT CASE WHEN ier.result IS NOT NULL AND ier.result <> '' THEN ier.participant_id END) AS results_updated,
               COALESCE(ers.status, 'pending') AS result_status
        FROM event_master em
        INNER JOIN age_categories ac ON ac.id = em.age_category_id
        LEFT JOIN participant_events pe ON pe.event_master_id = em.id
        LEFT JOIN participants p ON p.id = pe.participant_id
        LEFT JOIN individual_event_results ier ON ier.event_master_id = em.id AND ier.participant_id = pe.participant_id
        LEFT JOIN individual_event_result_statuses ers ON ers.event_master_id = em.id
        WHERE em.event_id = ? AND em.event_type = 'Individual'
        GROUP BY em.id, em.name, em.label, em.gender, ac.name, ers.status
        ORDER BY ac.name, em.label, em.name";

$stmt = $db->prepare($sql);
$stmt->bind_param('i', $assigned_event_id);
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$status_badges = [
    'pending' => 'secondary',
    'entry' => 'primary',
    'published' => 'success',
];

$status_labels = [
    'pending' => 'Pending',
    'entry' => 'Entry',
    'published' => 'Published',
];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Individual Result Entry</h1>
        <p class="text-muted mb-0">Manage event wise individual participant results and publish statuses.</p>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <?php if (count($events) === 0): ?>
            <p class="mb-0 text-muted">No individual events are configured for result entry.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Sl. No</th>
                            <th>Age Category</th>
                            <th>Event Label</th>
                            <th>Gender</th>
                            <th class="text-center">Approved Participants</th>
                            <th class="text-center">Results Updated</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $index => $event): ?>
                            <?php $status_key = strtolower((string) $event['result_status']); ?>
                            <tr>
                                <td><?php echo (int) ($index + 1); ?></td>
                                <td><?php echo sanitize($event['age_category']); ?></td>
                                <td>
                                    <?php echo sanitize($event['label'] ?: $event['name']); ?>
                                </td>
                                <td><?php echo sanitize($event['gender']); ?></td>
                                <td class="text-center"><?php echo (int) $event['approved_participants']; ?></td>
                                <td class="text-center">
                                    <?php
                                    $results_updated = (int) $event['results_updated'];
                                    $results_badge_class = $results_updated === (int) $event['approved_participants']
                                        ? 'bg-success'
                                        : 'bg-warning text-dark';
                                    ?>
                                    <span class="badge <?php echo $results_badge_class; ?>"><?php echo $results_updated; ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $status_badges[$status_key] ?? 'secondary'; ?>">
                                        <?php echo sanitize($status_labels[$status_key] ?? 'Pending'); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="result_individual_entry.php?event_master_id=<?php echo (int) $event['id']; ?>" title="Update Results">
                                        <i class="bi bi-pencil-square"></i>
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
