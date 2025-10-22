<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();
require_role(['event_staff']);

require_once __DIR__ . '/includes/institution_financial_snapshot.php';

$db = get_db_connection();
$user = current_user();

if (!$user || !isset($user['event_id']) || !$user['event_id']) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Receipt Unavailable</title></head><body>';
    echo '<p>Unable to determine your event context. Please contact the administrator.</p>';
    echo '</body></html>';
    exit;
}

$event_id = (int) $user['event_id'];
$institution_id = (int) get_param('institution_id', 0);

if ($institution_id <= 0) {
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Receipt Unavailable</title></head><body>';
    echo '<p>Invalid institution selection.</p>';
    echo '</body></html>';
    exit;
}

$stmt = $db->prepare('SELECT id, name, affiliation_number FROM institutions WHERE id = ? AND event_id = ? LIMIT 1');
$stmt->bind_param('ii', $institution_id, $event_id);
$stmt->execute();
$institution = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$institution) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Receipt Unavailable</title></head><body>';
    echo '<p>The requested institution could not be found.</p>';
    echo '</body></html>';
    exit;
}

$stmt = $db->prepare('SELECT name, description, location, start_date, end_date, receipt_signature_path, bank_account_number, bank_ifsc, bank_name FROM events WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Receipt Unavailable</title></head><body>';
    echo '<p>The event associated with your account could not be found.</p>';
    echo '</body></html>';
    exit;
}

$snapshot = get_institution_financial_snapshot($db, $event_id, $institution_id);
$generated_at = new DateTimeImmutable('now');
$signature_path = $event['receipt_signature_path'] ?? null;
$signature_url = $signature_path ? ltrim((string) $signature_path, '/') : null;
$event_description = trim((string) ($event['description'] ?? ''));
if ($event_description === '') {
    $event_description = 'N/A';
}
$event_description = preg_replace('/\s+/u', ' ', $event_description);
$event_period = '';
if (!empty($event['start_date']) || !empty($event['end_date'])) {
    $start = !empty($event['start_date']) ? date('d M Y', strtotime((string) $event['start_date'])) : null;
    $end = !empty($event['end_date']) ? date('d M Y', strtotime((string) $event['end_date'])) : null;
    if ($start && $end) {
        $event_period = $start . ' - ' . $end;
    } elseif ($start) {
        $event_period = 'From ' . $start;
    } elseif ($end) {
        $event_period = 'Until ' . $end;
    }
}

function receipt_sanitize(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Financial Receipt - <?php echo receipt_sanitize($institution['name']); ?></title>
    <style>
        :root {
            color-scheme: light;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 24px;
            background-color: #f8f9fa;
            color: #212529;
        }
        .receipt-wrapper {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 12px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.08);
            padding: 40px;
        }
        header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 24px;
            margin-bottom: 32px;
        }
        header h1 {
            margin: 0;
            font-size: 28px;
            color: #0d6efd;
        }
        header .event-details {
            font-size: 14px;
            color: #495057;
            margin-top: 8px;
        }
        .key-value-list {
            margin-bottom: 32px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .key-value {
            display: flex;
            align-items: baseline;
            gap: 16px;
            font-size: 15px;
        }
        .key-value .label {
            font-weight: 600;
            color: #495057;
            min-width: 160px;
        }
        .key-value .value {
            color: #212529;
        }
        h2 {
            font-size: 18px;
            margin: 0 0 16px;
            color: #0c5460;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        th, td {
            padding: 12px 16px;
            border-bottom: 1px solid #dee2e6;
            text-align: left;
        }
        th {
            background-color: #e9ecef;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.08em;
        }
        td.amount {
            text-align: right;
            font-weight: 600;
        }
        .totals {
            margin-top: 24px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .totals .row {
            display: flex;
            justify-content: space-between;
            font-size: 16px;
        }
        .totals .row strong {
            font-size: 18px;
        }
        .signature-section {
            margin-top: 48px;
            display: flex;
            justify-content: flex-end;
        }
        .signature-block {
            text-align: center;
            width: 280px;
        }
        .signature-block img {
            max-width: 100%;
            height: auto;
        }
        .signature-line {
            border-bottom: 1px solid #343a40;
            margin-top: 48px;
            margin-bottom: 8px;
        }
        .small-note {
            font-size: 13px;
            color: #6c757d;
            margin-top: 8px;
        }
        .footer-note {
            margin-top: 32px;
            font-size: 12px;
            color: #868e96;
            line-height: 1.5;
        }
        .footer-note a {
            color: inherit;
            text-decoration: none;
        }
        .print-actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 24px;
        }
        .print-actions button {
            background: #0d6efd;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 10px 16px;
            font-size: 14px;
            cursor: pointer;
        }
        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .receipt-wrapper {
                box-shadow: none;
                border: none;
                border-radius: 0;
            }
            .print-actions {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="print-actions">
        <button type="button" onclick="window.print()">Print</button>
    </div>
    <div class="receipt-wrapper">
        <header>
            <div>
                <h1>Financial Receipt</h1>
                <div class="event-details">
                    <div><strong>Event:</strong> <?php echo receipt_sanitize($event['name']); ?></div>
                    <?php if ($event_period): ?>
                        <div><strong>Event Dates:</strong> <?php echo receipt_sanitize($event_period); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($event['location'])): ?>
                        <div><strong>Location:</strong> <?php echo receipt_sanitize($event['location']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="event-details" style="text-align: right;">
                <div><strong>Receipt Date:</strong> <?php echo receipt_sanitize($generated_at->format('d M Y')); ?></div>
                <div><strong>Generated At:</strong> <?php echo receipt_sanitize($generated_at->format('H:i \h')); ?></div>
                <div><strong>Affiliation Code:</strong> <?php echo receipt_sanitize($institution['affiliation_number'] ?? 'N/A'); ?></div>
            </div>
        </header>

        <section class="key-value-list">
            <div class="key-value">
                <span class="label">Received From</span>
                <span class="value"><?php echo receipt_sanitize($institution['name']); ?></span>
            </div>
        </section>

        <section>
            <h2>Fee Breakdown</h2>
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Details</th>
                        <th class="amount">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($snapshot['fee_breakdown'] as $item): ?>
                        <tr>
                            <td><?php echo receipt_sanitize($item['label']); ?></td>
                            <td>
                                <?php
                                $parts = [];
                                foreach ($item['counts'] as $count) {
                                    $parts[] = number_format((int) $count['value']) . ' ' . $count['label'];
                                }
                                echo receipt_sanitize(implode(' · ', $parts));
                                ?>
                            </td>
                            <td class="amount"><?php echo number_format((float) $item['amount'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section>
            <h2>Fund Receipts</h2>
            <table>
                <tbody>
                    <tr>
                        <td>Pending Approval</td>
                        <td class="amount">₹<?php echo number_format($snapshot['fund_pending'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Approved</td>
                        <td class="amount">₹<?php echo number_format($snapshot['fund_approved'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <div class="totals">
            <div class="row">
                <span>Total Due</span>
                <strong>₹<?php echo number_format($snapshot['total_fee_due'], 2); ?></strong>
            </div>
            <div class="row">
                <span>Total Received</span>
                <strong>₹<?php echo number_format($snapshot['fund_total'], 2); ?></strong>
            </div>
            <div class="row">
                <span>Outstanding Balance</span>
                <strong>₹<?php echo number_format($snapshot['balance'], 2); ?></strong>
            </div>
            <?php if ($snapshot['dues_cleared']): ?>
                <div class="small-note">All dues have been cleared for this institution.</div>
            <?php else: ?>
                <div class="small-note">Outstanding balance reflects the additional approved fund transfers required.</div>
            <?php endif; ?>
        </div>

        <div class="signature-section">
            <div class="signature-block">
                <?php if ($signature_url): ?>
                    <img src="<?php echo receipt_sanitize($signature_url); ?>" alt="Event Head Signature">
                <?php endif; ?>
                <div class="signature-line"></div>
                <div>Event Head Signature</div>
            </div>
        </div>
        <p class="footer-note">
            This is a computer-generated receipt issued via
            <a href="https://sportsmis.com" target="_blank" rel="noopener">https://sportsmis.com</a>, powered by SportsbyA Tech Private Limited,
            for the event-managing institution: <?php echo receipt_sanitize($event_description); ?>.
        </p>
    </div>
    <script>
        window.addEventListener('load', function () {
            if (window.matchMedia) {
                const mediaQuery = window.matchMedia('print');
                if (!mediaQuery || !mediaQuery.matches) {
                    window.print();
                }
            } else {
                window.print();
            }
        });
    </script>
</body>
</html>
