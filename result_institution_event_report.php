<?php
require_once __DIR__ . '/includes/header.php';

require_login();
require_role(['event_staff', 'institution_admin']);

$user = current_user();
$db = get_db_connection();

if (!$user['event_id']) {
    echo '<div class="alert alert-warning">No event assigned to your account. Please contact the event administrator.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$event_id = (int) $user['event_id'];

$age_categories = [];
$age_category_stmt = $db->prepare(
    "SELECT DISTINCT ac.id, ac.name
       FROM event_master em
       INNER JOIN age_categories ac ON ac.id = em.age_category_id
      WHERE em.event_id = ? AND em.event_type = 'Institution'
   ORDER BY ac.name ASC"
);

if ($age_category_stmt) {
    $age_category_stmt->bind_param('i', $event_id);
    $age_category_stmt->execute();
    $age_categories = $age_category_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $age_category_stmt->close();
}

$selected_age_category_id = (int) get_param('age_category_id', 0);
$valid_age_category_ids = array_map('intval', array_column($age_categories, 'id'));
if ($selected_age_category_id !== 0 && !in_array($selected_age_category_id, $valid_age_category_ids, true)) {
    $selected_age_category_id = 0;
}

$status_filter_options = [
    'all' => 'All Statuses',
    'published' => 'Published',
    'entry' => 'Entry',
    'pending' => 'Pending',
];

$status_labels = [
    'pending' => 'Pending',
    'entry' => 'Entry',
    'published' => 'Published',
];

$status_badges = [
    'pending' => 'secondary',
    'entry' => 'primary',
    'published' => 'success',
];

$default_status = 'published';
$selected_status = strtolower((string) get_param('result_status', $default_status));
if (!array_key_exists($selected_status, $status_filter_options)) {
    $selected_status = $default_status;
}

$events = [];
$query_error = null;

$sql = "SELECT em.id,
               COALESCE(NULLIF(em.label, ''), em.name) AS event_label,
               em.gender,
               ac.name AS age_category,
               COALESCE(st.status, 'pending') AS result_status
          FROM event_master em
          INNER JOIN age_categories ac ON ac.id = em.age_category_id
          LEFT JOIN institution_event_result_statuses st ON st.event_master_id = em.id
         WHERE em.event_id = ?
           AND em.event_type = 'Institution'";

$params = [$event_id];
$types = 'i';

if ($selected_age_category_id > 0) {
    $sql .= ' AND em.age_category_id = ?';
    $types .= 'i';
    $params[] = $selected_age_category_id;
}

if ($selected_status !== 'all') {
    $sql .= " AND COALESCE(st.status, 'pending') = ?";
    $types .= 's';
    $params[] = $selected_status;
}

$sql .= ' ORDER BY ac.name ASC, em.label ASC, em.name ASC';

$event_stmt = $db->prepare($sql);

if ($event_stmt) {
    $event_stmt->bind_param($types, ...$params);
    $event_stmt->execute();
    $events = $event_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $event_stmt->close();
} else {
    $query_error = 'Unable to load institution events. Please try again later.';
}

$results_by_event = [];

if ($events) {
    $event_ids = array_map('intval', array_column($events, 'id'));

    if ($event_ids) {
        $placeholders = implode(',', array_fill(0, count($event_ids), '?'));
        $result_sql = "SELECT res.event_master_id,
                               res.result,
                               res.score,
                               i.name AS institution_name,
                               i.affiliation_number
                          FROM institution_event_results res
                          INNER JOIN institutions i ON i.id = res.institution_id
                         WHERE res.event_master_id IN ($placeholders)
                      ORDER BY FIELD(res.result, 'first_place', 'second_place', 'third_place', 'participant'), i.name ASC";

        $result_stmt = $db->prepare($result_sql);

        if ($result_stmt) {
            $result_stmt->bind_param(str_repeat('i', count($event_ids)), ...$event_ids);
            $result_stmt->execute();
            $result_set = $result_stmt->get_result();

            while ($row = $result_set->fetch_assoc()) {
                $event_id_key = (int) ($row['event_master_id'] ?? 0);
                if ($event_id_key <= 0) {
                    continue;
                }

                $result_key = strtolower(trim((string) ($row['result'] ?? 'participant')));
                if (!isset($results_by_event[$event_id_key])) {
                    $results_by_event[$event_id_key] = [];
                }

                $results_by_event[$event_id_key][$result_key] = [
                    'institution_name' => (string) ($row['institution_name'] ?? ''),
                    'affiliation_number' => (string) ($row['affiliation_number'] ?? ''),
                    'score' => trim((string) ($row['score'] ?? '')),
                ];
            }

            $result_stmt->close();
        }
    }
}

$result_labels = get_result_label_map($db, $event_id);
$result_slots = ['first_place', 'second_place', 'third_place'];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Institution Event Results</h1>
        <p class="text-muted mb-0">Review standings awarded to institutions across published events.</p>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <?php if ($query_error): ?>
            <div class="alert alert-danger mb-0"><?php echo sanitize($query_error); ?></div>
        <?php elseif (!$age_categories): ?>
            <p class="mb-0 text-muted">No institution events are configured for this meet.</p>
        <?php else: ?>
            <form method="get" class="row row-cols-1 row-cols-lg-4 g-3 align-items-end mb-4">
                <div class="col">
                    <label for="age_category_id" class="form-label">Age Category</label>
                    <select id="age_category_id" name="age_category_id" class="form-select">
                        <option value="0" <?php echo $selected_age_category_id === 0 ? 'selected' : ''; ?>>All Age Categories</option>
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
                        <?php foreach ($status_filter_options as $status_key => $status_label): ?>
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
            <?php if (!$events): ?>
                <p class="mb-0 text-muted">No institution event results match the selected filters.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" class="text-center" style="width: 60px;">#</th>
                                <th scope="col">Event</th>
                                <th scope="col" style="width: 120px;">Gender</th>
                                <th scope="col" class="text-center" style="width: 120px;">Status</th>
                                <?php foreach ($result_slots as $slot_key): ?>
                                    <th scope="col" style="min-width: 220px;">
                                        <?php echo sanitize($result_labels[$slot_key] ?? ucwords(str_replace('_', ' ', $slot_key))); ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $index => $event_row): ?>
                                <?php
                                $event_master_id = (int) $event_row['id'];
                                $winners = $results_by_event[$event_master_id] ?? [];
                                $status_key = strtolower((string) ($event_row['result_status'] ?? 'pending'));
                                $badge_class = $status_badges[$status_key] ?? 'secondary';
                                $status_label = $status_labels[$status_key] ?? ucfirst($status_key);
                                ?>
                                <tr>
                                    <td class="text-center"><?php echo number_format($index + 1); ?></td>
                                    <td>
                                        <div class="fw-semibold"><?php echo sanitize($event_row['event_label']); ?></div>
                                        <div class="small text-muted"><?php echo sanitize($event_row['age_category']); ?></div>
                                    </td>
                                    <td><?php echo sanitize($event_row['gender'] ?? ''); ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php echo $badge_class; ?>"><?php echo sanitize($status_label); ?></span>
                                    </td>
                                    <?php foreach ($result_slots as $slot_key): ?>
                                        <?php $slot_result = $winners[$slot_key] ?? null; ?>
                                        <td>
                                            <?php if ($slot_result): ?>
                                                <div class="fw-semibold"><?php echo sanitize($slot_result['institution_name']); ?></div>
                                                <?php if ($slot_result['affiliation_number'] !== ''): ?>
                                                    <div class="small text-muted">Affiliation: <?php echo sanitize($slot_result['affiliation_number']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($slot_result['score'] !== ''): ?>
                                                    <div class="small text-muted">Score: <?php echo sanitize($slot_result['score']); ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not awarded</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
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
