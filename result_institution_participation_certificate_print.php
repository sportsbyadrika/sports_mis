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

$event_id = (int) $user['event_id'];
$institution_id = (int) get_param('institution_id', 0);

if ($institution_id <= 0) {
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Invalid request</title></head><body><p>Invalid institution selection for certificate generation.</p></body></html>';
    return;
}

$db = get_db_connection();

$institution_stmt = $db->prepare(
    "SELECT id, name, affiliation_number\n    FROM institutions\n    WHERE id = ? AND event_id = ?"
);

if (!$institution_stmt) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Server error</title></head><body><p>Unable to prepare institution lookup.</p></body></html>';
    return;
}

$institution_stmt->bind_param('ii', $institution_id, $event_id);
$institution_stmt->execute();
$institution = $institution_stmt->get_result()->fetch_assoc();
$institution_stmt->close();

if (!$institution) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Institution unavailable</title></head><body><p>The requested institution could not be found for your event.</p></body></html>';
    return;
}

$participants_stmt = $db->prepare(
    "SELECT name\n    FROM participants\n    WHERE institution_id = ? AND event_id = ? AND status = 'approved'\n    ORDER BY name ASC"
);

if (!$participants_stmt) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Server error</title></head><body><p>Unable to prepare participant lookup.</p></body></html>';
    return;
}

$participants_stmt->bind_param('ii', $institution_id, $event_id);
$participants_stmt->execute();
$participants = $participants_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$participants_stmt->close();

if (!$participants) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Institution Wise Participation Certificate</title><style>body{font-family:Arial,sans-serif;background:#fff;color:#000;margin:0;padding:2rem;}main{max-width:720px;margin:0 auto;}p{font-size:1rem;line-height:1.5;}</style></head><body><main><p>No approved participants available to generate certificates for this institution.</p></main></body></html>';
    return;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Institution Wise Participation Certificate</title>
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
        .affiliation-code {
            font-weight: normal;
            display: inline-block;
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
<?php foreach ($participants as $participant): ?>
    <section class="certificate-page">
        <div class="certificate-content">
            <p class="certificate-text">
                This Certifies that <span class="participant-name"><?php echo sanitize($participant['name']); ?></span>
                of <span class="institution-name"><?php echo sanitize($institution['name']); ?></span><?php if (!empty($institution['affiliation_number'])): ?>
                    (Affiliation: <span class="affiliation-code"><?php echo sanitize($institution['affiliation_number']); ?></span>)<?php endif; ?>
                participated in the South Zone Sahodaya Complex 2025 conducted at
                Sree Padam Stadium, Attingal, from October 23rd to 25th, 2025.
            </p>
        </div>
    </section>
<?php endforeach; ?>
</body>
</html>
