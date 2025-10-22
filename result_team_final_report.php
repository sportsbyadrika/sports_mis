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
    "SELECT em.id, em.name, em.label, em.gender, ac.name AS age_category, ev.name AS event_name,\n            ev.location, ev.description, ev.start_date, ev.end_date\n       FROM event_master em\n       INNER JOIN age_categories ac ON ac.id = em.age_category_id\n       INNER JOIN events ev ON ev.id = em.event_id\n      WHERE em.id = ? AND em.event_id = ? AND em.event_type = 'Team'"
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
        'team_points' => 0,
    ],
    'first_place' => [
        'label' => 'First Place',
        'team_points' => 0,
    ],
    'second_place' => [
        'label' => 'Second Place',
        'team_points' => 0,
    ],
    'third_place' => [
        'label' => 'Third Place',
        'team_points' => 0,
    ],
    'fourth_place' => [
        'label' => 'Fourth Place',
        'team_points' => 0,
    ],
    'fifth_place' => [
        'label' => 'Fifth Place',
        'team_points' => 0,
    ],
    'sixth_place' => [
        'label' => 'Sixth Place',
        'team_points' => 0,
    ],
    'seventh_place' => [
        'label' => 'Seventh Place',
        'team_points' => 0,
    ],
    'eighth_place' => [
        'label' => 'Eighth Place',
        'team_points' => 0,
    ],
    'absent' => [
        'label' => 'Absent',
        'team_points' => 0,
    ],
    'withheld' => [
        'label' => 'Withheld',
        'team_points' => 0,
    ],
];

$result_master_rows = [];
$result_master_stmt = $db->prepare(
    "SELECT result_key, result_label, team_points FROM result_master_settings WHERE event_id = ? ORDER BY sort_order ASC, id ASC"
);
if ($result_master_stmt) {
    $result_master_stmt->bind_param('i', $assigned_event_id);
    $result_master_stmt->execute();
    $result_master_rows = $result_master_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $result_master_stmt->close();
}

$team_result_options = $default_result_options;
if ($result_master_rows) {
    foreach ($result_master_rows as $row) {
        $key = strtolower(trim((string) ($row['result_key'] ?? '')));
        if ($key === '' || !array_key_exists($key, $team_result_options)) {
            continue;
        }

        $label = trim((string) ($row['result_label'] ?? ''));
        if ($label !== '') {
            $team_result_options[$key]['label'] = $label;
        }

        $team_result_options[$key]['team_points'] = (float) ($row['team_points'] ?? 0);
    }
}

$teams_stmt = $db->prepare(
    "SELECT te.id, te.team_name, i.name AS institution_name, res.result, res.team_score, res.team_points\n       FROM team_entries te\n       INNER JOIN institutions i ON i.id = te.institution_id\n       LEFT JOIN team_event_results res ON res.event_master_id = te.event_master_id AND res.team_entry_id = te.id\n      WHERE te.event_master_id = ? AND te.status = 'approved'\n      ORDER BY te.team_name, te.id"
);
$teams_stmt->bind_param('i', $event_master_id);
$teams_stmt->execute();
$team_rows = $teams_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$teams_stmt->close();

$team_ids = array_map(static fn(array $row): int => (int) $row['id'], $team_rows);
$team_ids = array_values(array_unique($team_ids));

$team_members = [];
if ($team_ids) {
    $id_list = implode(',', array_map('intval', $team_ids));
    $members_sql = "SELECT tem.team_entry_id, p.name, p.chest_number\n          FROM team_entry_members tem\n          INNER JOIN participants p ON p.id = tem.participant_id\n         WHERE tem.team_entry_id IN ($id_list)\n         ORDER BY tem.team_entry_id, CAST(NULLIF(p.chest_number, '') AS UNSIGNED), p.chest_number, p.name";
    $members_result = $db->query($members_sql);
    if ($members_result) {
        while ($member = $members_result->fetch_assoc()) {
            $team_id = (int) $member['team_entry_id'];
            if (!isset($team_members[$team_id])) {
                $team_members[$team_id] = [];
            }

            $member_name = (string) ($member['name'] ?? '');
            $chest_number = $member['chest_number'];
            $formatted = $member_name;
            if ($chest_number !== null && $chest_number !== '') {
                $formatted .= ' (' . $chest_number . ')';
            }

            $team_members[$team_id][] = $formatted;
        }
        $members_result->free();
    }
}

$rows = [];
foreach ($team_rows as $row) {
    $result_key = strtolower(trim((string) ($row['result'] ?? '')));
    if ($result_key === '' || !array_key_exists($result_key, $team_result_options)) {
        $result_key = 'participant';
    }

    $result_label = $team_result_options[$result_key]['label'] ?? ucfirst(str_replace('_', ' ', $result_key));
    $score = trim((string) ($row['team_score'] ?? ''));
    $points = $row['team_points'];
    if ($points === null && isset($team_result_options[$result_key]['team_points'])) {
        $points = (float) $team_result_options[$result_key]['team_points'];
    }
    if ($points === null) {
        $points = 0;
    }

    $members_list = $team_members[(int) $row['id']] ?? [];

    $rows[] = [
        'team_name' => (string) ($row['team_name'] ?? ''),
        'institution' => (string) ($row['institution_name'] ?? ''),
        'members' => $members_list,
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
    'participant' => 4,
    'absent' => 5,
    'withheld' => 6,
];

usort($rows, static function (array $a, array $b) use ($order_priority): int {
    $aPriority = $order_priority[$a['result_key']] ?? 90;
    $bPriority = $order_priority[$b['result_key']] ?? 90;

    if ($aPriority === $bPriority) {
        return strcasecmp($a['team_name'], $b['team_name']);
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
    <title><?php echo sanitize($event_name !== '' ? $event_name : 'Team Event Final Results'); ?></title>
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
        }
        header .event-meta {
            margin-top: 0.75rem;
            font-size: 1rem;
            color: #555555;
        }
        header .event-description {
            margin-top: 0.35rem;
            font-size: 0.95rem;
            color: #666666;
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
            vertical-align: top;
        }
        th {
            background: #f0f0f0;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.03em;
        }
        th.text-center, td.text-center {
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
        .participants-list {
            margin: 0;
            padding-left: 1rem;
        }
        .participants-list li {
            margin-bottom: 0.2rem;
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
            <h1><?php echo sanitize($event_name !== '' ? $event_name : 'Team Event Final Results'); ?></h1>
            <?php if ($event_dates !== ''): ?>
                <div class="event-meta"><?php echo sanitize($event_dates); ?></div>
            <?php endif; ?>
            <?php if ($location !== ''): ?>
                <div class="event-meta"><?php echo sanitize($location); ?></div>
            <?php endif; ?>
            <?php if ($description !== ''): ?>
                <div class="event-description"><?php echo sanitize($description); ?></div>
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
        <?php if (!$rows): ?>
            <div class="no-data">No approved teams available for this event.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th scope="col" class="text-center" style="width: 60px;">Sl No</th>
                        <th scope="col" style="width: 140px;">Relay Letter</th>
                        <th scope="col">Institution Name</th>
                        <th scope="col">Participants</th>
                        <th scope="col" style="width: 140px;">Result</th>
                        <th scope="col" style="width: 110px;">Score</th>
                        <th scope="col" style="width: 90px;">Points</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $index => $row): ?>
                        <tr>
                            <td class="text-center"><?php echo $index + 1; ?></td>
                            <td><?php echo sanitize($row['team_name']); ?></td>
                            <td><?php echo sanitize($row['institution']); ?></td>
                            <td>
                                <?php if ($row['members']): ?>
                                    <ul class="participants-list">
                                        <?php foreach ($row['members'] as $member): ?>
                                            <li><?php echo sanitize($member); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
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
