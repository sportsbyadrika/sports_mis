<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/participant_helpers.php';
require_login();
require_role(['event_staff']);

$user = current_user();
if (!$user['event_id']) {
    http_response_code(403);
    echo '<p>Unable to load participant details. No event assigned to your account.</p>';
    exit;
}

$db = get_db_connection();
$event_id = (int) $user['event_id'];
$participant_id = (int) get_param('participant_id', 0);

if ($participant_id <= 0) {
    http_response_code(400);
    echo '<p>Invalid participant reference provided.</p>';
    exit;
}

$participant = load_participant_with_access($db, $participant_id, null, $event_id, 'event_staff');
if (!$participant) {
    http_response_code(404);
    echo '<p>Participant not found or access denied.</p>';
    exit;
}

$participant_age = calculate_age($participant['date_of_birth'] ?? null);
$age_categories = fetch_age_categories($db);
$participant_age_category = determine_age_category_label($participant_age, $age_categories);

$stmt = $db->prepare("SELECT em.name AS event_name, em.code AS event_code, em.label AS event_label, em.fees, ac.name AS age_category_name\n    FROM participant_events pe\n    JOIN event_master em ON em.id = pe.event_master_id\n    JOIN age_categories ac ON ac.id = em.age_category_id\n    WHERE pe.participant_id = ? AND em.event_type = 'Individual'\n    ORDER BY em.name");
$stmt->bind_param('i', $participant_id);
$stmt->execute();
$participant_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_fees = 0.0;
foreach ($participant_events as $event) {
    $total_fees += (float) ($event['fees'] ?? 0);
}

$status_classes = [
    'draft' => 'secondary',
    'submitted' => 'primary',
    'approved' => 'success',
    'rejected' => 'danger',
];
$status = $participant['status'] ?? 'draft';
$status_class = $status_classes[$status] ?? 'secondary';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Participant Profile - <?php echo sanitize($participant['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #ffffff;
        }
        .print-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem;
        }
        .profile-photo {
            max-width: 180px;
            border-radius: 0.5rem;
        }
        .print-actions {
            margin-bottom: 1.5rem;
        }
        @media print {
            .print-actions {
                display: none !important;
            }
            body {
                background: #ffffff;
            }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <div class="print-actions d-flex justify-content-end gap-2">
            <a href="event_staff_participant_view.php?participant_id=<?php echo (int) $participant_id; ?>" class="btn btn-outline-secondary">
                Back to Details
            </a>
            <button type="button" class="btn btn-primary" onclick="window.print();">
                Print Profile
            </button>
        </div>
        <div class="text-center mb-4">
            <h1 class="h4 mb-1">Participant Profile</h1>
            <div class="badge bg-<?php echo $status_class; ?> text-uppercase"><?php echo sanitize($status); ?></div>
            <?php if (!empty($participant['chest_number'])): ?>
                <div class="mt-2"><span class="badge bg-primary">Chest No: <?php echo sanitize((string) $participant['chest_number']); ?></span></div>
            <?php endif; ?>
        </div>
        <div class="row g-4 mb-4">
            <div class="col-md-4 text-center">
                <?php if (!empty($participant['photo_path'])): ?>
                    <img src="<?php echo sanitize($participant['photo_path']); ?>" alt="Passport photo" class="img-fluid profile-photo border">
                <?php else: ?>
                    <div class="border rounded py-5 text-muted">No photo available</div>
                <?php endif; ?>
            </div>
            <div class="col-md-8">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="text-muted small">Full Name</div>
                        <div class="fw-semibold"><?php echo sanitize($participant['name']); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Gender</div>
                        <div class="fw-semibold"><?php echo sanitize($participant['gender'] ?? ''); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Date of Birth</div>
                        <div class="fw-semibold"><?php echo $participant['date_of_birth'] ? sanitize(format_date($participant['date_of_birth'])) : '<span class="text-muted">Not available</span>'; ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Age</div>
                        <div class="fw-semibold"><?php echo $participant_age !== null ? (int) $participant_age . ' years' : '<span class="text-muted">Not available</span>'; ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Age Category</div>
                        <div class="fw-semibold"><?php echo $participant_age_category ? sanitize($participant_age_category) : '<span class="text-muted">No age category</span>'; ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Guardian</div>
                        <div class="fw-semibold"><?php echo sanitize($participant['guardian_name'] ?? ''); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Contact Number</div>
                        <div class="fw-semibold"><?php echo sanitize($participant['contact_number'] ?? ''); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Email</div>
                        <div class="fw-semibold"><?php echo sanitize($participant['email'] ?? ''); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Aadhaar Number</div>
                        <div class="fw-semibold"><?php echo sanitize($participant['aadhaar_number'] ?? ''); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Institution</div>
                        <div class="fw-semibold"><?php echo sanitize($participant['institution_name'] ?? ''); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="mb-4">
            <div class="text-muted small">Address</div>
            <div class="fw-semibold"><?php echo nl2br(sanitize($participant['address'] ?? '')); ?></div>
        </div>
        <div class="card">
            <div class="card-body">
                <h2 class="h6 mb-3">Participation Events</h2>
                <?php if (empty($participant_events)): ?>
                    <p class="text-muted mb-0">No individual events assigned.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Code</th>
                                    <th scope="col">Event Name</th>
                                    <th scope="col">Label</th>
                                    <th scope="col">Age Category</th>
                                    <th scope="col" class="text-end">Fees (₹)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($participant_events as $event): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo sanitize($event['event_code']); ?></td>
                                        <td><?php echo sanitize($event['event_name']); ?></td>
                                        <td><?php echo $event['event_label'] ? sanitize($event['event_label']) : '<span class="text-muted">-</span>'; ?></td>
                                        <td><?php echo sanitize($event['age_category_name']); ?></td>
                                        <td class="text-end"><?php echo number_format((float) ($event['fees'] ?? 0), 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="4" class="text-end">Total Fees</th>
                                    <th class="text-end">₹<?php echo number_format($total_fees, 2); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
