<?php
$page_title = 'Top Individual Points Report';
require_once __DIR__ . '/includes/auth.php';

require_login();
require_role(['event_staff']);

$is_print_view = (int) get_param('print', 0) === 1;

if (!$is_print_view) {
    require_once __DIR__ . '/includes/header.php';
}

$user = current_user();
$db = get_db_connection();

if (!$user['event_id']) {
    if ($is_print_view) {
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo sanitize($page_title); ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="assets/css/app.css" rel="stylesheet">
    <style>
        body { background-color: #ffffff; }
    </style>
</head>
<body class="bg-white">
<main class="container-fluid my-4">
        <?php
    }

    echo '<div class="alert alert-warning">No event assigned to your account. Please contact the event administrator.</div>';

    if ($is_print_view) {
        ?>
</main>
</body>
</html>
        <?php
    } else {
        include __DIR__ . '/includes/footer.php';
    }

    return;
}

if ($is_print_view) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo sanitize($page_title); ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="assets/css/app.css" rel="stylesheet">
    <style>
        body { background-color: #ffffff; }
        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="bg-white">
<main class="container-fluid my-4">
    <?php
}

$event_id = (int) $user['event_id'];

$age_categories = [];
$age_category_stmt = $db->prepare("SELECT DISTINCT ac.id, ac.name
    FROM event_master em
    INNER JOIN age_categories ac ON ac.id = em.age_category_id
    WHERE em.event_id = ? AND em.event_type = 'Individual'
    ORDER BY ac.name");

if ($age_category_stmt) {
    $age_category_stmt->bind_param('i', $event_id);
    $age_category_stmt->execute();
    $age_categories = $age_category_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $age_category_stmt->close();
}

$selected_age_category_id = null;
$selected_age_category_name = '';

if ($age_categories) {
    $default_age_category_id = (int) ($age_categories[0]['id'] ?? 0);
    $selected_age_category_id = (int) get_param('age_category_id', $default_age_category_id);
    $age_category_ids = array_map(static fn ($row) => (int) $row['id'], $age_categories);
    if (!in_array($selected_age_category_id, $age_category_ids, true)) {
        $selected_age_category_id = $default_age_category_id;
    }

    foreach ($age_categories as $category) {
        if ((int) $category['id'] === $selected_age_category_id) {
            $selected_age_category_name = (string) $category['name'];
            break;
        }
    }
}

$gender_options = [
    'Male' => 'Boys',
    'Female' => 'Girls',
];

$default_gender = array_key_first($gender_options) ?? 'Male';
$selected_gender = (string) get_param('gender', $default_gender);
if (!array_key_exists($selected_gender, $gender_options)) {
    $selected_gender = $default_gender;
}

$top_participants = [];

if ($selected_age_category_id !== null) {
    $stmt = $db->prepare("SELECT p.id,
           p.name AS participant_name,
           i.name AS institution_name,
           COALESCE(SUM(ier.individual_points), 0) AS total_points
        FROM individual_event_results ier
        INNER JOIN event_master em ON em.id = ier.event_master_id
        INNER JOIN participants p ON p.id = ier.participant_id
        INNER JOIN institutions i ON i.id = p.institution_id
        WHERE em.event_id = ?
          AND em.event_type = 'Individual'
          AND em.age_category_id = ?
          AND p.gender = ?
          AND p.event_id = ?
        GROUP BY p.id, p.name, i.name
        ORDER BY total_points DESC, p.name ASC
        LIMIT 10");

    if ($stmt) {
        $stmt->bind_param('iisi', $event_id, $selected_age_category_id, $selected_gender, $event_id);
        $stmt->execute();
        $top_participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

$selected_gender_label = $gender_options[$selected_gender] ?? $selected_gender;
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Top Individual Points</h1>
        <p class="text-muted mb-0">Top scoring participants by age category and gender.</p>
    </div>
    <?php if (!$is_print_view && $age_categories): ?>
        <?php
        $print_params = [
            'print' => 1,
            'age_category_id' => $selected_age_category_id,
            'gender' => $selected_gender,
        ];
        $print_url = 'result_individual_top_participants.php?' . http_build_query($print_params);
        ?>
        <a href="<?php echo sanitize($print_url); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary" title="Open print view">
            <i class="bi bi-printer"></i>
        </a>
    <?php endif; ?>
</div>
<?php if (!$age_categories): ?>
    <div class="alert alert-info">No individual age categories available for this event.</div>
<?php else: ?>
    <?php if (!$is_print_view): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="get" class="row row-cols-1 row-cols-md-4 g-3 align-items-end">
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
                        <label for="gender" class="form-label">Gender</label>
                        <select id="gender" name="gender" class="form-select">
                            <?php foreach ($gender_options as $gender_key => $label): ?>
                                <option value="<?php echo sanitize($gender_key); ?>" <?php echo $gender_key === $selected_gender ? 'selected' : ''; ?>>
                                    <?php echo sanitize($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm<?php echo $is_print_view ? ' border-0' : ''; ?>">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h2 class="h5 mb-1">Age Category: <?php echo sanitize($selected_age_category_name); ?></h2>
                    <div class="text-muted">Gender: <?php echo sanitize($selected_gender_label); ?></div>
                </div>
                <?php if ($is_print_view): ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()">
                        <i class="bi bi-printer"></i> Print
                    </button>
                <?php endif; ?>
            </div>
            <?php if (count($top_participants) === 0): ?>
                <div class="alert alert-info mb-0">No participant points available for the selected filters.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center" scope="col" style="width: 60px;">Sl. No</th>
                                <th scope="col">Institution</th>
                                <th scope="col">Participant</th>
                                <th class="text-end" scope="col">Individual Points</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_participants as $index => $participant): ?>
                                <tr>
                                    <td class="text-center"><?php echo number_format($index + 1); ?></td>
                                    <td><?php echo sanitize($participant['institution_name']); ?></td>
                                    <td><?php echo sanitize($participant['participant_name']); ?></td>
                                    <td class="text-end"><?php echo number_format((float) $participant['total_points'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php
if ($is_print_view) {
    ?>
</main>
<script>
    window.addEventListener('load', function () {
        window.print();
    });
</script>
</body>
</html>
    <?php
} else {
    include __DIR__ . '/includes/footer.php';
}
