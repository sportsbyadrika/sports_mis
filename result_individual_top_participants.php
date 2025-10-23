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
$participant_event_details = [];

$result_label_defaults = [
    'participant' => 'Participant',
    'first_place' => 'First Place',
    'second_place' => 'Second Place',
    'third_place' => 'Third Place',
    'fourth_place' => 'Fourth Place',
    'fifth_place' => 'Fifth Place',
    'sixth_place' => 'Sixth Place',
    'seventh_place' => 'Seventh Place',
    'eighth_place' => 'Eighth Place',
    'absent' => 'Absent',
    'withheld' => 'Withheld',
];

$result_label_overrides = [];
$result_label_stmt = $db->prepare(
    "SELECT result_key, result_label FROM result_master_settings WHERE event_id = ? ORDER BY sort_order ASC, id ASC"
);
if ($result_label_stmt) {
    $result_label_stmt->bind_param('i', $event_id);
    $result_label_stmt->execute();
    $result_label_overrides = $result_label_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $result_label_stmt->close();
}

if ($result_label_overrides) {
    foreach ($result_label_overrides as $row) {
        $key = strtolower(trim((string) ($row['result_key'] ?? '')));
        if ($key === '' || !array_key_exists($key, $result_label_defaults)) {
            continue;
        }

        $label = trim((string) ($row['result_label'] ?? ''));
        if ($label !== '') {
            $result_label_defaults[$key] = $label;
        }
    }
}

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

$participant_ids = array_map(static fn ($row) => (int) ($row['id'] ?? 0), $top_participants);
$participant_ids = array_filter($participant_ids, static fn ($id) => $id > 0);

if ($participant_ids) {
    $placeholders = implode(',', array_fill(0, count($participant_ids), '?'));
    $query = "SELECT pe.participant_id,
                     COALESCE(NULLIF(em.label, ''), em.name) AS event_label,
                     COALESCE(res.result, 'participant') AS result_key,
                     res.score,
                     COALESCE(res.individual_points, 0) AS individual_points
                FROM participant_events pe
                INNER JOIN event_master em ON em.id = pe.event_master_id
                LEFT JOIN individual_event_results res
                    ON res.event_master_id = pe.event_master_id
                   AND res.participant_id = pe.participant_id
               WHERE pe.participant_id IN ($placeholders)
                 AND em.event_type = 'Individual'
                 AND em.event_id = ?
            ORDER BY em.name, em.id";

    $events_stmt = $db->prepare($query);

    if ($events_stmt) {
        $types = str_repeat('i', count($participant_ids)) . 'i';
        $params = $participant_ids;
        $params[] = $event_id;
        $events_stmt->bind_param($types, ...$params);
        $events_stmt->execute();
        $result = $events_stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $participant_id = (int) ($row['participant_id'] ?? 0);
            if ($participant_id <= 0) {
                continue;
            }

            $event_label = trim((string) ($row['event_label'] ?? ''));
            $result_key = strtolower(trim((string) ($row['result_key'] ?? 'participant')));
            $score = trim((string) ($row['score'] ?? ''));
            $points_value = (float) ($row['individual_points'] ?? 0);

            $participant_event_details[$participant_id][] = [
                'eventLabel' => $event_label !== '' ? $event_label : 'Unnamed Event',
                'position' => $result_label_defaults[$result_key] ?? ucwords(str_replace('_', ' ', $result_key)),
                'score' => $score,
                'points' => number_format($points_value, 2),
            ];
        }

        $events_stmt->close();
    }
}

$participant_modal_payloads = [];
foreach ($top_participants as $participant) {
    $participant_id = (int) ($participant['id'] ?? 0);
    if ($participant_id <= 0) {
        continue;
    }

    $participant_modal_payloads[$participant_id] = [
        'participantName' => $participant['participant_name'] ?? '',
        'institutionName' => $participant['institution_name'] ?? '',
        'events' => $participant_event_details[$participant_id] ?? [],
    ];
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
                                <?php if (!$is_print_view): ?>
                                    <th class="text-center" scope="col" style="width: 80px;">Events</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_participants as $index => $participant): ?>
                                <tr>
                                    <td class="text-center"><?php echo number_format($index + 1); ?></td>
                                    <td><?php echo sanitize($participant['institution_name']); ?></td>
                                    <td><?php echo sanitize($participant['participant_name']); ?></td>
                                    <td class="text-end"><?php echo number_format((float) $participant['total_points'], 2); ?></td>
                                    <?php if (!$is_print_view): ?>
                                        <?php
                                        $participant_id = (int) ($participant['id'] ?? 0);
                                        $modal_payload = $participant_modal_payloads[$participant_id] ?? [
                                            'participantName' => $participant['participant_name'] ?? '',
                                            'institutionName' => $participant['institution_name'] ?? '',
                                            'events' => [],
                                        ];
                                        $modal_json = htmlspecialchars(
                                            (string) json_encode($modal_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        );
                                        ?>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-outline-primary btn-sm participant-events-btn" data-participant-events="<?php echo $modal_json; ?>" title="View participated events">
                                                <i class="bi bi-list-ul"></i>
                                            </button>
                                        </td>
                                    <?php endif; ?>
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
    ?>
    <div class="modal fade" id="participantEventsModal" tabindex="-1" aria-hidden="true" aria-labelledby="participantEventsModalLabel">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="h5 modal-title mb-1" id="participantEventsModalLabel">Participant Events</h2>
                        <div class="text-muted participant-events-institution"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" style="width: 70px;">Sl. No</th>
                                    <th scope="col">Participating Event</th>
                                    <th scope="col">Position</th>
                                    <th scope="col">Score</th>
                                    <th scope="col" class="text-end">Points</th>
                                </tr>
                            </thead>
                            <tbody class="participant-events-table-body">
                                <tr>
                                    <td colspan="5" class="text-center text-muted">Select a participant to view events.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var modalElement = document.getElementById('participantEventsModal');
        if (!modalElement || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return;
        }

        var modalInstance = new bootstrap.Modal(modalElement);
        var modalTitle = modalElement.querySelector('.modal-title');
        var modalInstitution = modalElement.querySelector('.participant-events-institution');
        var tableBody = modalElement.querySelector('.participant-events-table-body');

        function renderEvents(events) {
            if (!Array.isArray(events) || events.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No events available for this participant.</td></tr>';
                return;
            }

            var rows = events.map(function (event, index) {
                var score = event.score ? event.score : '';
                var points = event.points ? event.points : '0.00';

                return '<tr>' +
                    '<td>' + (index + 1) + '</td>' +
                    '<td>' + escapeHtml(event.eventLabel || '') + '</td>' +
                    '<td>' + escapeHtml(event.position || '') + '</td>' +
                    '<td>' + (score !== '' ? escapeHtml(score) : '<span class="text-muted">—</span>') + '</td>' +
                    '<td class="text-end">' + escapeHtml(points) + '</td>' +
                '</tr>';
            }).join('');

            tableBody.innerHTML = rows;
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        document.querySelectorAll('.participant-events-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                var payload = button.getAttribute('data-participant-events');
                if (!payload) {
                    return;
                }

                try {
                    var details = JSON.parse(payload);
                } catch (error) {
                    console.error('Unable to parse participant events payload', error);
                    return;
                }

                modalTitle.textContent = details.participantName || 'Participant Events';
                modalInstitution.textContent = details.institutionName || '';
                renderEvents(details.events || []);

                modalInstance.show();
            });
        });
    });
    </script>
    <?php
    include __DIR__ . '/includes/footer.php';
}
