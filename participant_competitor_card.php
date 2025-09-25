<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/participant_helpers.php';

require_login();
require_role(['institution_admin', 'event_admin', 'event_staff', 'super_admin']);

$user = current_user();
$db = get_db_connection();
$role = $user['role'];

$event_id = null;
$institution_id = null;
$institution_context = null;

if ($role === 'institution_admin') {
    if (!$user['institution_id']) {
        http_response_code(403);
        echo '<p>Institution not assigned to your account.</p>';
        return;
    }
    $institution_id = (int) $user['institution_id'];
    $stmt = $db->prepare('SELECT i.id, i.name, i.event_id, e.name AS event_name FROM institutions i JOIN events e ON e.id = i.event_id WHERE i.id = ?');
    $stmt->bind_param('i', $institution_id);
    $stmt->execute();
    $institution_context = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$institution_context) {
        http_response_code(404);
        echo '<p>Unable to load institution information.</p>';
        return;
    }
    $event_id = (int) $institution_context['event_id'];
} elseif ($role === 'event_admin' || $role === 'event_staff') {
    if (!$user['event_id']) {
        http_response_code(403);
        echo '<p>No event assigned to your account.</p>';
        return;
    }
    $event_id = (int) $user['event_id'];
    $institution_id = (int) get_param('institution_id', 0) ?: null;
} else {
    $event_id = (int) get_param('event_id', 0) ?: null;
    $institution_id = (int) get_param('institution_id', 0) ?: null;
}

$participant_id = (int) get_param('id', 0);
if (!$participant_id) {
    http_response_code(404);
    echo '<p>Participant not specified.</p>';
    return;
}

$participant = load_participant_with_access($db, $participant_id, $institution_id, $event_id, $role);
if (!$participant || $participant['status'] !== 'approved') {
    http_response_code(403);
    echo '<p>Competitor card is available only for approved participants.</p>';
    return;
}

$event_name = $participant['event_name'];
if (!$event_name && $event_id) {
    $stmt = $db->prepare('SELECT name FROM events WHERE id = ?');
    $stmt->bind_param('i', $event_id);
    $stmt->execute();
    $event_name = ($stmt->get_result()->fetch_assoc()['name'] ?? '') ?: $event_name;
    $stmt->close();
}

$participant_age = calculate_age($participant['date_of_birth']);
$age_categories = fetch_age_categories($db);
$participant_age_category = determine_age_category_label($participant_age, $age_categories);

$stmt = $db->prepare('SELECT em.name, em.code, em.event_type, ac.name AS age_category_name FROM participant_events pe JOIN event_master em ON em.id = pe.event_master_id JOIN age_categories ac ON ac.id = em.age_category_id WHERE pe.participant_id = ? ORDER BY em.name');
$stmt->bind_param('i', $participant_id);
$stmt->execute();
$assigned_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$photo_src = !empty($participant['photo_path']) ? $participant['photo_path'] : null;
$chest_number = $participant['chest_number'];
$participant_name = $participant['name'];
$institution_name = $participant['institution_name'];
$generated_on = date('d M Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Competitor Card - <?php echo sanitize($participant_name); ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 20px;
            color: #212529;
        }
        .card-wrapper {
            border: 2px solid #0d6efd;
            border-radius: 12px;
            padding: 24px;
            max-width: 640px;
            margin: 0 auto;
        }
        .card-header {
            text-align: center;
            margin-bottom: 24px;
        }
        .card-header h1 {
            margin: 0;
            font-size: 24px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .card-header p {
            margin: 4px 0 0;
            font-size: 14px;
            color: #6c757d;
        }
        .details {
            display: flex;
            gap: 24px;
            align-items: center;
        }
        .photo {
            width: 140px;
            height: 170px;
            border: 1px solid #ced4da;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: #f8f9fa;
        }
        .photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .info h2 {
            margin: 0 0 12px;
            font-size: 22px;
        }
        .info p {
            margin: 4px 0;
            font-size: 16px;
        }
        .info-details {
            display: grid;
            gap: 6px;
            margin-top: 8px;
        }
        .info-details p {
            margin: 0;
            font-size: 15px;
        }
        .chest-number {
            font-size: 32px;
            font-weight: 700;
            color: #dc3545;
            margin-top: 12px;
        }
        .footer {
            margin-top: 32px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        .signature {
            text-align: center;
        }
        .signature-line {
            margin-top: 48px;
            border-top: 1px solid #212529;
            width: 220px;
        }
        .signature-label {
            margin-top: 8px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 1px;
        }
        .meta {
            font-size: 14px;
            color: #6c757d;
        }
        .events-section {
            margin-top: 32px;
        }
        .events-section h3 {
            margin: 0 0 12px;
            font-size: 18px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #1e3a5f;
        }
        .events-list {
            list-style: none;
            padding-left: 0;
            margin: 0;
            display: grid;
            gap: 8px;
        }
        .events-list li {
            padding: 12px 16px;
            border: 1px solid #d2dce8;
            border-radius: 8px;
            background: linear-gradient(135deg, rgba(121, 200, 155, 0.08), rgba(30, 58, 95, 0.05));
            font-size: 15px;
        }
        .events-list .event-name {
            font-weight: 600;
            color: #1e3a5f;
        }
        .events-list .event-meta {
            display: block;
            font-size: 13px;
            color: #5c6b80;
            margin-top: 4px;
        }
        .events-empty {
            padding: 12px 16px;
            border: 1px dashed #d2dce8;
            border-radius: 8px;
            background: #f8f9fa;
            color: #6c757d;
            font-size: 15px;
        }
        @media print {
            body {
                margin: 0;
            }
            .card-wrapper {
                border-color: #000;
            }
        }
    </style>
</head>
<body>
    <div class="card-wrapper">
        <div class="card-header">
            <h1><?php echo sanitize($event_name ?: 'Competitor Card'); ?></h1>
            <p>Competitor Card</p>
        </div>
        <div class="details">
            <div class="photo">
                <?php if ($photo_src): ?>
                    <img src="<?php echo sanitize($photo_src); ?>" alt="Participant photo">
                <?php else: ?>
                    <span>No Photo</span>
                <?php endif; ?>
            </div>
            <div class="info">
                <h2><?php echo sanitize($participant_name); ?></h2>
                <?php if ($institution_name): ?>
                    <p><strong>Institution:</strong> <?php echo sanitize($institution_name); ?></p>
                <?php endif; ?>
                <div class="info-details">
                    <p><strong>Age:</strong> <?php echo $participant_age !== null ? (int) $participant_age . ' years' : 'Not available'; ?></p>
                    <p><strong>Age Category:</strong> <?php echo $participant_age_category ? sanitize($participant_age_category) : 'Not available'; ?></p>
                    <p><strong>Gender:</strong> <?php echo sanitize($participant['gender']); ?></p>
                </div>
                <p><strong>Chest Number:</strong></p>
                <div class="chest-number">#<?php echo sanitize((string) $chest_number); ?></div>
            </div>
        </div>
        <div class="events-section">
            <h3>Participating Events</h3>
            <?php if (!empty($assigned_events)): ?>
                <ul class="events-list">
                    <?php foreach ($assigned_events as $event): ?>
                        <li>
                            <span class="event-name"><?php echo sanitize($event['name']); ?></span>
                            <span class="event-meta">
                                <?php echo sanitize($event['code']); ?>
                                <?php if (!empty($event['age_category_name'])): ?>
                                    &bull; <?php echo sanitize($event['age_category_name']); ?>
                                <?php endif; ?>
                                <?php if (!empty($event['event_type'])): ?>
                                    &bull; <?php echo sanitize($event['event_type']); ?> Event
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="events-empty">No events have been assigned to this participant.</div>
            <?php endif; ?>
        </div>
        <div class="footer">
            <div class="meta">Generated on <?php echo sanitize($generated_on); ?></div>
            <div class="signature">
                <div class="signature-line"></div>
                <div class="signature-label">Authorized Signature</div>
            </div>
        </div>
    </div>
</body>
</html>
