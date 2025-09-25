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
                <p><strong>Chest Number:</strong></p>
                <div class="chest-number">#<?php echo sanitize((string) $chest_number); ?></div>
            </div>
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
