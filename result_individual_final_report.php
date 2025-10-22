<?php
require_once __DIR__ . '/includes/auth.php';

require_login();
require_role(['event_staff']);

$user = current_user();
if (!$user || !$user['event_id']) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Access denied</title></head><body><p>Event access is not configured for your account.</p></body></html>';
    return;
}

$assigned_event_id = (int) $user['event_id'];
$event_master_id = (int) get_param('event_master_id', 0);

if ($event_master_id <= 0) {
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Invalid request</title></head><body><p>Invalid event selection for final report.</p></body></html>';
    return;
}

$db = get_db_connection();

$event_stmt = $db->prepare(
    "SELECT em.id, em.name, em.label, em.gender, em.code AS event_code, ac.name AS age_category, ev.name AS event_name,\n            ev.location, ev.description, ev.start_date, ev.end_date\n     FROM event_master em\n     INNER JOIN age_categories ac ON ac.id = em.age_category_id\n     INNER JOIN events ev ON ev.id = em.event_id\n     WHERE em.id = ? AND em.event_id = ? AND em.event_type = 'Individual'"
);
$event_stmt->bind_param('ii', $event_master_id, $assigned_event_id);
$event_stmt->execute();
$event_details = $event_stmt->get_result()->fetch_assoc();
$event_stmt->close();

if (!$event_details) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Event unavailable</title></head><body><p>The requested event is not available.</p></body></html>';
    return;
}

$default_result_options = [
    'participant' => [
        'label' => 'Participant',
        'individual_points' => 0,
    ],
    'first_place' => [
        'label' => 'First Place',
        'individual_points' => 0,
    ],
    'second_place' => [
        'label' => 'Second Place',
        'individual_points' => 0,
    ],
    'third_place' => [
        'label' => 'Third Place',
        'individual_points' => 0,
    ],
    'fourth_place' => [
        'label' => 'Fourth Place',
        'individual_points' => 0,
    ],
    'fifth_place' => [
        'label' => 'Fifth Place',
        'individual_points' => 0,
    ],
    'sixth_place' => [
        'label' => 'Sixth Place',
        'individual_points' => 0,
    ],
    'seventh_place' => [
        'label' => 'Seventh Place',
        'individual_points' => 0,
    ],
    'eighth_place' => [
        'label' => 'Eighth Place',
        'individual_points' => 0,
    ],
    'absent' => [
        'label' => 'Absent',
        'individual_points' => 0,
    ],
    'withheld' => [
        'label' => 'Withheld',
        'individual_points' => 0,
    ],
];

$result_master_rows = [];
$result_master_stmt = $db->prepare(
    "SELECT result_key, result_label, individual_points FROM result_master_settings WHERE event_id = ? ORDER BY sort_order ASC, id ASC"
);
if ($result_master_stmt) {
    $result_master_stmt->bind_param('i', $assigned_event_id);
    $result_master_stmt->execute();
    $result_master_rows = $result_master_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $result_master_stmt->close();
}

$participant_result_options = $default_result_options;
if ($result_master_rows) {
    foreach ($result_master_rows as $row) {
        $key = strtolower(trim((string) ($row['result_key'] ?? '')));
        if ($key === '' || !array_key_exists($key, $participant_result_options)) {
            continue;
        }

        $label = trim((string) ($row['result_label'] ?? ''));
        if ($label !== '') {
            $participant_result_options[$key]['label'] = $label;
        }

        if (array_key_exists('individual_points', $participant_result_options[$key])) {
            $participant_result_options[$key]['individual_points'] = (float) ($row['individual_points'] ?? 0);
        }
    }
}

$participants_stmt = $db->prepare(
    "SELECT p.name, p.chest_number, i.name AS institution_name, res.result, res.score, res.individual_points\n     FROM participant_events pe\n     INNER JOIN participants p ON p.id = pe.participant_id\n     INNER JOIN institutions i ON i.id = p.institution_id\n     LEFT JOIN individual_event_results res ON res.event_master_id = pe.event_master_id AND res.participant_id = p.id\n     WHERE pe.event_master_id = ? AND p.status = 'approved'"
);
$participants_stmt->bind_param('i', $event_master_id);
$participants_stmt->execute();
$participants = $participants_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$participants_stmt->close();

$participant_rows = [];
foreach ($participants as $participant) {
    $result_key = strtolower(trim((string) ($participant['result'] ?? '')));
    if ($result_key === '' || !array_key_exists($result_key, $participant_result_options)) {
        $result_key = 'participant';
    }

    $result_label = $participant_result_options[$result_key]['label'] ?? ucfirst(str_replace('_', ' ', $result_key));
    $score = trim((string) ($participant['score'] ?? ''));

    $points = $participant['individual_points'];
    if ($points === null && isset($participant_result_options[$result_key]['individual_points'])) {
        $points = $participant_result_options[$result_key]['individual_points'];
    }
    if ($points === null) {
        $points = 0;
    }

    $participant_rows[] = [
        'name' => (string) ($participant['name'] ?? ''),
        'institution' => (string) ($participant['institution_name'] ?? ''),
        'chest_number' => isset($participant['chest_number']) ? (string) $participant['chest_number'] : '',
        'result_key' => $result_key,
        'result_label' => (string) $result_label,
        'score' => $score,
        'points' => (float) $points,
    ];
}

$order_priority = [
    'first_place' => 1,
    'second_place' => 2,
    'third_place' => 3,
    'fourth_place' => 4,
    'fifth_place' => 5,
    'sixth_place' => 6,
    'seventh_place' => 7,
    'eighth_place' => 8,
    'participant' => 9,
    'absent' => 10,
    'withheld' => 11,
];

usort($participant_rows, static function (array $a, array $b) use ($order_priority): int {
    $aPriority = $order_priority[$a['result_key']] ?? 99;
    $bPriority = $order_priority[$b['result_key']] ?? 99;

    if ($aPriority === $bPriority) {
        $aChest = $a['chest_number'];
        $bChest = $b['chest_number'];

        if ($aChest !== '' && $bChest !== '') {
            $aNumeric = is_numeric($aChest) ? (float) $aChest : null;
            $bNumeric = is_numeric($bChest) ? (float) $bChest : null;
            if ($aNumeric !== null && $bNumeric !== null && $aNumeric !== $bNumeric) {
                return $aNumeric <=> $bNumeric;
            }
        }

        return strcasecmp($a['name'], $b['name']);
    }

    return $aPriority <=> $bPriority;
});

$event_name = trim((string) ($event_details['event_name'] ?? ''));
$event_label = trim((string) ($event_details['label'] ?: $event_details['name'] ?: ''));
$age_category = trim((string) ($event_details['age_category'] ?? ''));
$gender = trim((string) ($event_details['gender'] ?? ''));
$location = trim((string) ($event_details['location'] ?? ''));
$description = trim((string) ($event_details['description'] ?? ''));

$start_date = $event_details['start_date'] ? format_date($event_details['start_date']) : '';
$end_date = $event_details['end_date'] ? format_date($event_details['end_date']) : '';
$event_dates = '';
if ($start_date && $end_date) {
    $event_dates = $start_date === $end_date ? $start_date : $start_date . ' - ' . $end_date;
} elseif ($start_date) {
    $event_dates = $start_date;
} elseif ($end_date) {
    $event_dates = $end_date;
}

$meta_segments = [];
if ($event_dates !== '') {
    $meta_segments[] = sanitize($event_dates);
}
if ($location !== '') {
    $meta_segments[] = sanitize($location);
}
if ($description !== '') {
    $meta_segments[] = sanitize($description);
}
$meta_text = implode(' &bull; ', $meta_segments);

function format_points_display(float $value): string
{
    $formatted = number_format($value, 2, '.', '');
    $trimmed = rtrim(rtrim($formatted, '0'), '.');

    return $trimmed === '' ? '0' : $trimmed;
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo sanitize($event_name !== '' ? $event_name : 'Event Final Results'); ?></title>
    <style>
        :root {
            color-scheme: only light;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            padding: 2rem;
            font-family: "Segoe UI", Arial, sans-serif;
            background: #ffffff;
            color: #000000;
        }
        main {
            max-width: 960px;
            margin: 0 auto;
        }
        header {
            text-align: center;
            margin-bottom: 2rem;
        }
        header h1 {
            margin: 0;
            font-size: 2rem;
            text-transform: uppercase;
        }
        header .event-meta {
            margin-top: 0.5rem;
            font-size: 0.95rem;
            color: #555555;
        }
        header .event-label {
            margin-top: 1.5rem;
            font-size: 1.35rem;
            font-weight: 600;
        }
        header .event-subtext {
            margin-top: 0.35rem;
            font-size: 0.95rem;
            color: #555555;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            font-size: 0.95rem;
        }
        th, td {
            border: 1px solid #000000;
            padding: 0.6rem;
            text-align: left;
        }
        th {
            background: #f0f0f0;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.03em;
        }
        td.text-center, th.text-center {
            text-align: center;
        }
        .no-data {
            text-align: center;
            padding: 2rem;
            font-style: italic;
            color: #555555;
        }
        .print-actions {
            text-align: right;
            margin-bottom: 1rem;
        }
        .print-actions button {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            border: 1px solid #000000;
            background: #ffffff;
            cursor: pointer;
        }
        @media print {
            body {
                padding: 1.5rem;
            }
            .print-actions {
                display: none;
            }
            th {
                background: #e0e0e0 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <main>
        <div class="print-actions">
            <button type="button" onclick="window.print()">Print</button>
        </div>
        <header>
            <h1><?php echo sanitize($event_name !== '' ? $event_name : 'Event Final Results'); ?></h1>
            <?php if ($meta_text !== ''): ?>
                <div class="event-meta"><?php echo $meta_text; ?></div>
            <?php endif; ?>
            <?php if ($event_label !== ''): ?>
                <div class="event-label"><?php echo sanitize($event_label); ?></div>
            <?php endif; ?>
            <?php if ($age_category !== '' || $gender !== ''): ?>
                <div class="event-subtext">
                    <?php if ($age_category !== ''): ?>
                        <span><?php echo sanitize($age_category); ?></span>
                    <?php endif; ?>
                    <?php if ($age_category !== '' && $gender !== ''): ?>
                        <span>&nbsp;|&nbsp;</span>
                    <?php endif; ?>
                    <?php if ($gender !== ''): ?>
                        <span><?php echo sanitize($gender); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </header>
        <?php if (!$participant_rows): ?>
            <div class="no-data">No approved participants available for this event.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th scope="col" class="text-center" style="width: 60px;">Sl No</th>
                        <th scope="col" style="width: 120px;">Chest No</th>
                        <th scope="col">Participant Name</th>
                        <th scope="col">Institution Name</th>
                        <th scope="col" style="width: 140px;">Saved Result</th>
                        <th scope="col" style="width: 110px;">Score</th>
                        <th scope="col" style="width: 90px;">Points</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($participant_rows as $index => $row): ?>
                        <tr>
                            <td class="text-center"><?php echo $index + 1; ?></td>
                            <td><?php echo $row['chest_number'] !== '' ? sanitize($row['chest_number']) : '&mdash;'; ?></td>
                            <td><?php echo sanitize($row['name']); ?></td>
                            <td><?php echo sanitize($row['institution']); ?></td>
                            <td><?php echo sanitize($row['result_label']); ?></td>
                            <td><?php echo $row['score'] !== '' ? sanitize($row['score']) : '&mdash;'; ?></td>
                            <td class="text-center"><?php echo sanitize(format_points_display($row['points'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>
    <script>
        window.addEventListener('load', function () {
            window.print();
        });
    </script>
</body>
</html>
