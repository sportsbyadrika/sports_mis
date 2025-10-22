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
$certificate_type = strtolower(trim((string) get_param('type', '')));

if ($event_master_id <= 0 || !in_array($certificate_type, ['merit', 'participation'], true)) {
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Invalid request</title></head><body><p>Invalid certificate request.</p></body></html>';
    return;
}

$db = get_db_connection();

$event_stmt = $db->prepare(
    "SELECT em.id, em.name, em.label, em.code AS event_code, ac.name AS age_category, ev.name AS event_name\n    FROM event_master em\n    INNER JOIN age_categories ac ON ac.id = em.age_category_id\n    INNER JOIN events ev ON ev.id = em.event_id\n    WHERE em.id = ? AND em.event_id = ? AND em.event_type = 'Team'"
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
    ],
    'first_place' => [
        'label' => 'First Place',
    ],
    'second_place' => [
        'label' => 'Second Place',
    ],
    'third_place' => [
        'label' => 'Third Place',
    ],
];

$result_master_rows = [];
$result_master_stmt = $db->prepare(
    "SELECT result_key, result_label FROM result_master_settings WHERE event_id = ? ORDER BY sort_order ASC, id ASC"
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
        if (!array_key_exists($key, $team_result_options)) {
            continue;
        }

        $label = trim((string) ($row['result_label'] ?? ''));
        if ($label === '') {
            $label = $team_result_options[$key]['label'];
        }

        $team_result_options[$key]['label'] = $label;
    }
}

$participants_stmt = $db->prepare(
    "SELECT p.name, p.chest_number, i.name AS institution_name, res.result, te.team_name\n    FROM team_entries te\n    INNER JOIN team_entry_members tem ON tem.team_entry_id = te.id\n    INNER JOIN participants p ON p.id = tem.participant_id\n    INNER JOIN institutions i ON i.id = p.institution_id\n    LEFT JOIN team_event_results res ON res.event_master_id = te.event_master_id AND res.team_entry_id = te.id\n    WHERE te.event_master_id = ? AND te.status = 'approved' AND p.status = 'approved'\n    ORDER BY\n        CASE res.result\n            WHEN 'first_place' THEN 1\n            WHEN 'second_place' THEN 2\n            WHEN 'third_place' THEN 3\n            ELSE 4\n        END,\n        te.team_name,\n        p.name"
);
$participants_stmt->bind_param('i', $event_master_id);
$participants_stmt->execute();
$participants = $participants_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$participants_stmt->close();

$certificates = [];

foreach ($participants as $participant) {
    $result_key = strtolower(trim((string) ($participant['result'] ?? '')));
    $is_merit_result = in_array($result_key, ['first_place', 'second_place', 'third_place'], true);

    if ($certificate_type === 'merit' && !$is_merit_result) {
        continue;
    }

    if ($certificate_type === 'participation' && $is_merit_result) {
        continue;
    }

    $position_label = '';
    if ($certificate_type === 'merit' && $is_merit_result) {
        $position_label = $team_result_options[$result_key]['label'] ?? ucfirst(str_replace('_', ' ', $result_key));
    }

    $certificates[] = [
        'name' => (string) ($participant['name'] ?? ''),
        'institution' => (string) ($participant['institution_name'] ?? ''),
        'team_name' => (string) ($participant['team_name'] ?? ''),
        'position_label' => $position_label,
        'chest_number' => isset($participant['chest_number']) ? (string) $participant['chest_number'] : null,
    ];
}

if (!$certificates) {
    $title = $certificate_type === 'merit' ? 'Certificates of Merit' : 'Certificates of Participation';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . sanitize($title) . '</title><style>body{font-family:Arial,sans-serif;background:#fff;color:#000;margin:0;padding:2rem;}main{max-width:720px;margin:0 auto;}p{font-size:1rem;line-height:1.5;}</style></head><body><main><p>No certificates are available to generate for this event.</p></main></body></html>';
    return;
}

$event_label = trim((string) ($event_details['label'] ?: $event_details['name'] ?: ''));
$event_label = $event_label !== '' ? $event_label : ($event_details['event_name'] ?? '');
$event_label = (string) $event_label;
$event_code = trim((string) ($event_details['event_code'] ?? ''));
$certificate_year = '2025';
$certificate_date = date('d-M-Y');

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>South Zone Sahodaya Complex 2025 Certificates</title>
    <style>
        :root {
            color-scheme: only light;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            background: #ffffff;
            color: #000000;
            font-family: "Times New Roman", Times, serif;
        }
        .certificate-page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            position: relative;
            page-break-after: always;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            background: #ffffff;
        }
        .certificate-page:last-of-type {
            page-break-after: auto;
        }
        .certificate-content {
            width: 100%;
            padding: 12cm 2cm 2cm;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .certificate-text {
            font-size: 1.5rem;
            line-height: 1.6;
            margin: 0 auto;
            max-width: 80%;
        }
        .participant-name {
            font-weight: bold;
            display: inline-block;
        }
        .institution-name {
            font-weight: bold;
            display: inline-block;
        }
        .event-name {
            font-weight: bold;
            display: inline-block;
        }
        .team-name {
            font-weight: bold;
            display: inline-block;
        }
        .achievement-label {
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .certificate-meta {
            margin-top: 2rem;
            font-size: 1.1rem;
            line-height: 1.4;
        }
        .certificate-meta .meta-label {
            font-weight: bold;
        }
        @media print {
            body {
                margin: 0;
            }
            .certificate-page {
                width: 100%;
                min-height: 100vh;
            }
            .certificate-content {
                padding-left: 15mm;
                padding-right: 15mm;
            }
        }
    </style>
</head>
<body>
<?php foreach ($certificates as $certificate): ?>
    <section class="certificate-page">
        <div class="certificate-content">
            <p class="certificate-text">
                This Certifies that <span class="participant-name"><?php echo sanitize($certificate['name']); ?></span>
                of <span class="institution-name"><?php echo sanitize($certificate['institution']); ?></span>
                participated in the the South Zone Sahodaya Complex 2025 conducted at
                Sree Padam Stadium, Attingal, from October 23rd to 25th, 2025 in the
                <span class="event-name"><?php echo sanitize($event_label); ?></span> event
                as a member of the team <span class="team-name"><?php echo sanitize($certificate['team_name']); ?></span>.
                <?php if ($certificate_type === 'merit' && $certificate['position_label']): ?>
                    <br><br>
                    <span class="achievement-label">Achieved: <?php echo sanitize($certificate['position_label']); ?></span>
                <?php endif; ?>
            </p>
            <?php if ($certificate_type === 'merit'): ?>
                <?php
                    $chest_segment = ($certificate['chest_number'] ?? '') !== '' ? (string) $certificate['chest_number'] : 'N/A';
                    $event_segment = $event_code !== '' ? $event_code : 'N/A';
                    $certificate_number = $chest_segment . ' / ' . $event_segment . ' / ' . $certificate_year;
                ?>
                <div class="certificate-meta">
                    <div><span class="meta-label">Certificate No:</span> <?php echo sanitize($certificate_number); ?></div>
                    <div><span class="meta-label">Date:</span> <?php echo sanitize($certificate_date); ?></div>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endforeach; ?>
</body>
</html>
