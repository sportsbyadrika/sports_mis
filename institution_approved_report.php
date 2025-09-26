<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/participant_helpers.php';

require_login();
require_role(['institution_admin']);

$user = current_user();
$db = get_db_connection();

if (!$user['institution_id']) {
    http_response_code(403);
    echo '<p>No institution assigned to your account.</p>';
    return;
}

$institution_id = (int) $user['institution_id'];

$stmt = $db->prepare('SELECT i.name, e.name AS event_name FROM institutions i JOIN events e ON e.id = i.event_id WHERE i.id = ?');
$stmt->bind_param('i', $institution_id);
$stmt->execute();
$context = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$context) {
    http_response_code(404);
    echo '<p>Unable to load institution details.</p>';
    return;
}

$stats_subquery = 'SELECT participant_id, COUNT(*) AS total_events, COALESCE(SUM(fees), 0) AS total_fees FROM participant_events GROUP BY participant_id';
$sql = "SELECT p.id, p.name, p.date_of_birth, p.gender, p.aadhaar_number, p.chest_number, p.photo_path, p.status,
        stats.total_events, stats.total_fees
        FROM participants p
        LEFT JOIN ($stats_subquery) stats ON stats.participant_id = p.id
        WHERE p.institution_id = ? AND p.status = 'approved'
        ORDER BY p.name";

$stmt = $db->prepare($sql);
$stmt->bind_param('i', $institution_id);
$stmt->execute();
$participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $db->prepare("SELECT te.id, te.team_name, te.submitted_at, te.reviewed_at, em.code, em.name\n    FROM team_entries te\n    JOIN event_master em ON em.id = te.event_master_id\n    WHERE te.institution_id = ? AND te.status = 'approved'\n    ORDER BY em.name, te.team_name");
$stmt->bind_param('i', $institution_id);
$stmt->execute();
$team_entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$team_entry_ids = array_map(static fn(array $entry): int => (int) $entry['id'], $team_entries);
$team_members = [];

if ($team_entry_ids) {
    $placeholders = implode(',', array_fill(0, count($team_entry_ids), '?'));
    $types = str_repeat('i', count($team_entry_ids));

    $stmt = $db->prepare(
        "SELECT tem.team_entry_id, p.name, p.chest_number\n         FROM team_entry_members tem\n         JOIN participants p ON p.id = tem.participant_id\n         WHERE tem.team_entry_id IN ($placeholders)\n         ORDER BY p.name"
    );
    $stmt->bind_param($types, ...$team_entry_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $team_members[(int) $row['team_entry_id']][] = $row;
    }
    $stmt->close();
}

$stmt = $db->prepare("SELECT em.code, em.name, em.fees, ier.status\n    FROM institution_event_registrations ier\n    JOIN event_master em ON em.id = ier.event_master_id\n    WHERE ier.institution_id = ? AND em.event_type = 'Institution'\n    ORDER BY em.name");
$stmt->bind_param('i', $institution_id);
$stmt->execute();
$institution_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $db->prepare('SELECT transfer_date, mode, amount, transaction_number, status, reviewed_at FROM fund_transfers WHERE institution_id = ? ORDER BY transfer_date DESC, created_at DESC');
$stmt->bind_param('i', $institution_id);
$stmt->execute();
$fund_transfers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$age_categories = fetch_age_categories($db);
$report_date = date('d M Y H:i');
$total_events_sum = 0;
$total_fees_sum = 0.0;
$institution_events_total_fees = 0.0;

foreach ($participants as &$participant) {
    $participant['age'] = calculate_age($participant['date_of_birth']);
    $participant['age_category_label'] = determine_age_category_label($participant['age'], $age_categories);
    $participant['total_events'] = (int) ($participant['total_events'] ?? 0);
    $participant['total_fees'] = (float) ($participant['total_fees'] ?? 0);
    $total_events_sum += $participant['total_events'];
    $total_fees_sum += $participant['total_fees'];
}
unset($participant);

foreach ($institution_events as &$institution_event) {
    $institution_event['fees'] = (float) ($institution_event['fees'] ?? 0);
    $institution_events_total_fees += $institution_event['fees'];
}
unset($institution_event);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Approved Participants Report - <?php echo sanitize($context['institution_name'] ?? $context['name']); ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 30px;
            color: #212529;
            background: #fff;
        }
        h1, h2 {
            margin: 0;
        }
        .header {
            text-align: center;
            margin-bottom: 24px;
        }
        .header h1 {
            font-size: 26px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .header h2 {
            font-size: 20px;
            margin-top: 6px;
            color: #495057;
        }
        .meta {
            margin-bottom: 24px;
            font-size: 14px;
            color: #495057;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            border: 1px solid #adb5bd;
            padding: 8px;
            vertical-align: top;
        }
        th {
            background: #f1f3f5;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: .5px;
        }
        td.photo-cell {
            width: 90px;
            text-align: center;
        }
        td.photo-cell img {
            width: 80px;
            height: 100px;
            object-fit: cover;
            border: 1px solid #ced4da;
        }
        .totals-row {
            font-weight: 600;
            background: #f8f9fa;
        }
        .declaration {
            margin-top: 32px;
            font-size: 14px;
            line-height: 1.6;
        }
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 48px;
        }
        .signature-block {
            text-align: center;
            flex: 1;
        }
        .signature-line {
            border-top: 1px solid #212529;
            margin: 50px auto 12px;
            width: 240px;
        }
        .signature-label {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: .5px;
        }
        .seal-box {
            border: 1px dashed #495057;
            width: 140px;
            height: 140px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
        }
        @media print {
            body {
                margin: 10mm;
            }
            .page-break {
                page-break-before: always;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><?php echo sanitize($context['event_name']); ?></h1>
        <h2><?php echo sanitize($context['name']); ?></h2>
    </div>
    <div class="meta">Report generated on: <?php echo sanitize($report_date); ?></div>

    <h2 style="margin-top:0; margin-bottom:12px;">Institution Event Registrations</h2>
    <table style="margin-bottom:32px;">
        <thead>
            <tr>
                <th>Sl. No</th>
                <th>Event Code</th>
                <th>Event Name</th>
                <th>Status</th>
                <th>Fees (₹)</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($institution_events): ?>
            <?php foreach ($institution_events as $index => $institution_event): ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo sanitize($institution_event['code']); ?></td>
                    <td><?php echo sanitize($institution_event['name']); ?></td>
                    <td style="text-transform:uppercase; text-align:center;"><strong><?php echo sanitize($institution_event['status']); ?></strong></td>
                    <td style="text-align:right;">₹<?php echo number_format($institution_event['fees'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="totals-row">
                <td colspan="4" style="text-align:right;">Total Institution Event Fees</td>
                <td style="text-align:right;">₹<?php echo number_format($institution_events_total_fees, 2); ?></td>
            </tr>
        <?php else: ?>
            <tr>
                <td colspan="5" style="text-align:center; padding:24px; color:#6c757d;">No institution-level event registrations found.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h2 style="margin-top:0; margin-bottom:12px;">Approved Team Entries</h2>
    <table style="margin-bottom:32px;">
        <thead>
            <tr>
                <th>Sl. No</th>
                <th>Event Code</th>
                <th>Event Name</th>
                <th>Team Name</th>
                <th>Participants</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($team_entries): ?>
            <?php foreach ($team_entries as $index => $team_entry): ?>
                <?php $members = $team_members[$team_entry['id']] ?? []; ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo sanitize($team_entry['code']); ?></td>
                    <td><?php echo sanitize($team_entry['name']); ?></td>
                    <td><?php echo sanitize($team_entry['team_name']); ?></td>
                    <td>
                        <?php if ($members): ?>
                            <ul style="margin:0; padding-left:16px;">
                                <?php foreach ($members as $member): ?>
                                    <li>
                                        <?php echo sanitize($member['name']); ?>
                                        <?php if (!empty($member['chest_number'])): ?>
                                            <span style="color:#6c757d;">(Chest <?php echo sanitize((string) $member['chest_number']); ?>)</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <span style="color:#6c757d;">No participants listed.</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="5" style="text-align:center; padding:24px; color:#6c757d;">No team entries have been approved.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <table>
        <thead>
            <tr>
                <th>Sl. No</th>
                <th>Chest No.</th>
                <th>Photo</th>
                <th>Name of Participant</th>
                <th>DOB / Age Category</th>
                <th>Gender</th>
                <th>Aadhaar Number</th>
                <th>Total Events</th>
                <th>Total Fees (₹)</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($participants): ?>
            <?php foreach ($participants as $index => $participant): ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo $participant['chest_number'] ? sanitize((string) $participant['chest_number']) : '<span style="color:#6c757d;">N/A</span>'; ?></td>
                    <td class="photo-cell">
                        <?php if (!empty($participant['photo_path'])): ?>
                            <img src="<?php echo sanitize($participant['photo_path']); ?>" alt="Photo">
                        <?php else: ?>
                            <span style="color:#6c757d;">No Photo</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo sanitize($participant['name']); ?></td>
                    <td>
                        <?php echo sanitize(format_date($participant['date_of_birth'])); ?><br>
                        <?php if ($participant['age'] !== null): ?>
                            <span>Age: <?php echo (int) $participant['age']; ?> yrs</span><br>
                        <?php endif; ?>
                        <?php if ($participant['age_category_label']): ?>
                            <span>Category: <?php echo sanitize($participant['age_category_label']); ?></span>
                        <?php else: ?>
                            <span style="color:#6c757d;">No category</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo sanitize($participant['gender']); ?></td>
                    <td><?php echo sanitize($participant['aadhaar_number']); ?></td>
                    <td style="text-align:center;"><?php echo (int) $participant['total_events']; ?></td>
                    <td style="text-align:right;"><?php echo number_format($participant['total_fees'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="totals-row">
                <td colspan="7" style="text-align:right;">Grand Total</td>
                <td style="text-align:center;"><?php echo (int) $total_events_sum; ?></td>
                <td style="text-align:right;"><?php echo number_format($total_fees_sum, 2); ?></td>
            </tr>
        <?php else: ?>
            <tr>
                <td colspan="9" style="text-align:center; padding:24px; color:#6c757d;">No approved participants found.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
    <div class="page-break"></div>
    <h2 style="margin-top:32px; margin-bottom:16px;">Fund Transfer Submissions</h2>
    <table>
        <thead>
            <tr>
                <th>Sl. No</th>
                <th>Transfer Date</th>
                <th>Mode</th>
                <th>Amount (₹)</th>
                <th>Transaction Number</th>
                <th>Status</th>
                <th>Reviewed On</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($fund_transfers): ?>
            <?php foreach ($fund_transfers as $index => $transfer): ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo $transfer['transfer_date'] ? sanitize(date('d M Y', strtotime($transfer['transfer_date']))) : '<span style="color:#6c757d;">N/A</span>'; ?></td>
                    <td><?php echo sanitize($transfer['mode']); ?></td>
                    <td>₹<?php echo number_format((float) $transfer['amount'], 2); ?></td>
                    <td><?php echo sanitize($transfer['transaction_number']); ?></td>
                    <td style="text-transform:uppercase;"><strong><?php echo sanitize($transfer['status']); ?></strong></td>
                    <td><?php echo $transfer['reviewed_at'] ? sanitize(date('d M Y', strtotime($transfer['reviewed_at']))) : '<span style="color:#6c757d;">Pending</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="7" style="text-align:center; padding:24px; color:#6c757d;">No fund transfers have been submitted.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
    <div class="declaration">
        I hereby declare that all the details, especially the age, date of birth, and Aadhaar number of the participants, have been verified by the Head of Institution.
    </div>
    <div class="signatures">
        <div class="signature-block">
            <div class="signature-line"></div>
            <div class="signature-label">Head of Institution</div>
        </div>
        <div class="signature-block">
            <div class="seal-box">Institution Seal</div>
        </div>
    </div>
</body>
</html>
