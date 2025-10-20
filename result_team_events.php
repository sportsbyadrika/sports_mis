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

$age_category_stmt = $db->prepare("SELECT DISTINCT ac.id, ac.name
    FROM event_master em
    INNER JOIN age_categories ac ON ac.id = em.age_category_id
    WHERE em.event_id = ? AND em.event_type = 'Team'
    ORDER BY ac.name");

$age_categories = [];
if ($age_category_stmt) {
    $age_category_stmt->bind_param('i', $assigned_event_id);
    $age_category_stmt->execute();
    $age_categories = $age_category_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $age_category_stmt->close();
}

$selected_age_category_id = null;
if ($age_categories) {
    $default_age_category_id = (int) ($age_categories[0]['id'] ?? 0);
    $selected_age_category_id = (int) get_param('age_category_id', $default_age_category_id);
    $age_category_ids = array_map('intval', array_column($age_categories, 'id'));
    if (!in_array($selected_age_category_id, $age_category_ids, true)) {
        $selected_age_category_id = $default_age_category_id;
    }
}

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

$status_keys = array_keys($status_labels);
$default_status_key = $status_keys[0] ?? 'pending';
$selected_status = strtolower((string) get_param('result_status', $default_status_key));
if (!array_key_exists($selected_status, $status_labels)) {
    $selected_status = $default_status_key;
}

$events = [];

if ($selected_age_category_id !== null) {
    $sql = "SELECT em.id, em.name, em.label, em.gender, ac.name AS age_category,
                   COUNT(DISTINCT CASE WHEN te.status = 'approved' THEN te.id END) AS approved_teams,
                   COUNT(DISTINCT CASE WHEN te.status = 'approved' AND ter.result IS NOT NULL AND ter.result <> '' THEN te.id END) AS results_updated,
                   COALESCE(ters.status, 'pending') AS result_status
            FROM event_master em
            INNER JOIN age_categories ac ON ac.id = em.age_category_id
            LEFT JOIN team_entries te ON te.event_master_id = em.id
            LEFT JOIN team_event_results ter ON ter.event_master_id = em.id AND ter.team_entry_id = te.id
            LEFT JOIN team_event_result_statuses ters ON ters.event_master_id = em.id
            WHERE em.event_id = ? AND em.event_type = 'Team'
                AND em.age_category_id = ?
                AND COALESCE(ters.status, 'pending') = ?
            GROUP BY em.id, em.name, em.label, em.gender, ac.name, ters.status
            ORDER BY ac.name, em.label, em.name";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('iis', $assigned_event_id, $selected_age_category_id, $selected_status);
    $stmt->execute();
    $events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Team Result Entry</h1>
        <p class="text-muted mb-0">Manage event wise team results and publication statuses.</p>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <?php if (!$age_categories): ?>
            <p class="mb-0 text-muted">No team events are configured for result entry.</p>
        <?php else: ?>
            <form method="get" class="row row-cols-1 row-cols-md-4 g-3 align-items-end mb-4">
                <div class="col">
                    <label for="age_category_id" class="form-label">Age Category</label>
                    <select id="age_category_id" name="age_category_id" class="form-select">
                        <?php foreach ($age_categories as $category): ?>
                            <?php $category_id = (int) $category['id']; ?>
                            <option value="<?php echo $category_id; ?>" <?php echo $category_id === $selected_age_category_id ? 'selected' : ''; ?>>
                                <?php echo sanitize($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col">
                    <label for="result_status" class="form-label">Status</label>
                    <select id="result_status" name="result_status" class="form-select">
                        <?php foreach ($status_labels as $status_key => $status_label): ?>
                            <option value="<?php echo sanitize($status_key); ?>" <?php echo $status_key === $selected_status ? 'selected' : ''; ?>>
                                <?php echo sanitize($status_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </div>
            </form>
            <?php if (count($events) === 0): ?>
                <p class="mb-0 text-muted">No team events match the selected filters.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Sl. No</th>
                            <th>Age Category</th>
                            <th>Event Label</th>
                            <th>Gender</th>
                            <th class="text-center">Approved Teams</th>
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
                                <td class="text-center"><?php echo (int) $event['approved_teams']; ?></td>
                                <td class="text-center">
                                    <?php
                                    $results_updated = (int) $event['results_updated'];
                                    $results_badge_class = $results_updated === (int) $event['approved_teams']
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
                                    <a class="btn btn-sm btn-outline-primary" href="result_team_entry.php?event_master_id=<?php echo (int) $event['id']; ?>" title="Update Results">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
