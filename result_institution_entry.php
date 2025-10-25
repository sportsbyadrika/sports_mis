<?php
require_once __DIR__ . '/includes/header.php';
require_login();
require_role(['event_staff']);

$user = current_user();
$db = get_db_connection();

if (!$user['event_id']) {
    echo '<div class="alert alert-warning">No event assigned to your account. Please contact the event administrator.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$assigned_event_id = (int) $user['event_id'];
$event_master_id = (int) get_param('event_master_id', 0);

if ($event_master_id <= 0) {
    echo '<div class="alert alert-danger">Invalid event selection for result entry.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$event_stmt = $db->prepare("SELECT em.id, em.name, em.label, em.gender, ac.name AS age_category, ev.name AS event_name
    FROM event_master em
    INNER JOIN age_categories ac ON ac.id = em.age_category_id
    INNER JOIN events ev ON ev.id = em.event_id
    WHERE em.id = ? AND em.event_id = ? AND em.event_type = 'Institution'");
$event_stmt->bind_param('ii', $event_master_id, $assigned_event_id);
$event_stmt->execute();
$event_details = $event_stmt->get_result()->fetch_assoc();
$event_stmt->close();

if (!$event_details) {
    echo '<div class="alert alert-danger">The selected event is not available for institution result management.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$result_status_options = [
    'pending' => 'Pending',
    'entry' => 'Entry',
    'published' => 'Published',
];

$institution_result_options = [
    'participant' => 'Participant',
    'first_place' => 'First Place',
    'second_place' => 'Second Place',
    'third_place' => 'Third Place',
];

$errors = [];

if (is_post()) {
    $action = post_param('form_action');

    if ($action === 'update_status') {
        $selected_status = strtolower(trim((string) post_param('result_status')));
        if (!array_key_exists($selected_status, $result_status_options)) {
            $errors[] = 'Select a valid result status to update.';
        } else {
            $status_stmt = $db->prepare("INSERT INTO institution_event_result_statuses (event_master_id, status, updated_by)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by)");
            $updated_by = (int) $user['id'];
            $status_stmt->bind_param('isi', $event_master_id, $selected_status, $updated_by);
            if ($status_stmt->execute()) {
                set_flash('success', 'Event result status updated successfully.');
                redirect('result_institution_entry.php?event_master_id=' . $event_master_id);
            } else {
                $errors[] = 'Failed to update the event result status. Please try again.';
            }
            $status_stmt->close();
        }
    } elseif ($action === 'update_institution') {
        $institution_id = (int) post_param('institution_id');
        $selected_result = strtolower(trim((string) post_param('institution_result')));
        if (!array_key_exists($selected_result, $institution_result_options)) {
            $errors[] = 'Select a valid result for the institution.';
        } else {
            $institution_check = $db->prepare("SELECT i.id
                FROM institution_event_registrations ier
                INNER JOIN institutions i ON i.id = ier.institution_id
                WHERE ier.event_master_id = ? AND ier.institution_id = ? AND i.event_id = ? AND ier.status = 'approved'");
            $institution_check->bind_param('iii', $event_master_id, $institution_id, $assigned_event_id);
            $institution_check->execute();
            $institution_exists = $institution_check->get_result()->fetch_assoc();
            $institution_check->close();

            if (!$institution_exists) {
                $errors[] = 'The selected institution is not approved for this event.';
            } else {
                $institution_score = trim((string) post_param('institution_score'));
                if (mb_strlen($institution_score) > 100) {
                    $errors[] = 'The score must not exceed 100 characters.';
                }

                if (!$errors) {
                    $result_stmt = $db->prepare("INSERT INTO institution_event_results (event_master_id, institution_id, result, score, updated_by)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE result = VALUES(result), score = VALUES(score), updated_by = VALUES(updated_by)");
                }

                if (!$errors && $result_stmt) {
                    $updated_by = (int) $user['id'];
                    $result_stmt->bind_param('iissi', $event_master_id, $institution_id, $selected_result, $institution_score, $updated_by);
                    if ($result_stmt->execute()) {
                        set_flash('success', 'Institution result updated successfully.');
                        redirect('result_institution_entry.php?event_master_id=' . $event_master_id);
                    } else {
                        $errors[] = 'Failed to update institution result. Please try again.';
                    }
                    $result_stmt->close();
                } elseif (!$errors) {
                    $errors[] = 'Failed to prepare the institution result update. Please try again.';
                }
            }
        }
    }
}

$status_stmt = $db->prepare("SELECT status FROM institution_event_result_statuses WHERE event_master_id = ?");
$status_stmt->bind_param('i', $event_master_id);
$status_stmt->execute();
$current_status = $status_stmt->get_result()->fetch_assoc()['status'] ?? 'pending';
$status_stmt->close();

$institutions_stmt = $db->prepare("SELECT i.id, i.name, i.affiliation_number, res.result, res.score
    FROM institution_event_registrations ier
    INNER JOIN institutions i ON i.id = ier.institution_id
    LEFT JOIN institution_event_results res ON res.event_master_id = ier.event_master_id AND res.institution_id = i.id
    WHERE ier.event_master_id = ? AND ier.status = 'approved'
    ORDER BY i.name");
$institutions_stmt->bind_param('i', $event_master_id);
$institutions_stmt->execute();
$institutions = $institutions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$institutions_stmt->close();

$total_institutions = count($institutions);
$results_updated_count = 0;

foreach ($institutions as $institution_row) {
    $raw_result_value = strtolower(trim((string) ($institution_row['result'] ?? '')));
    if ($raw_result_value !== '' && array_key_exists($raw_result_value, $institution_result_options)) {
        $results_updated_count++;
    }
}

$results_badge_class = $results_updated_count === $total_institutions ? 'bg-success' : 'bg-warning text-dark';

$result_badge_classes = [
    'participant' => 'bg-secondary',
    'first_place' => 'bg-success',
    'second_place' => 'bg-primary',
    'third_place' => 'bg-warning text-dark',
];

$flash_success = get_flash('success');
$flash_error = get_flash('error');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Update Institution Results</h1>
        <p class="text-muted mb-0">Manage placements and scores for the selected institution-level event.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="result_institution_events.php" class="btn btn-outline-secondary">Back to Institution Events</a>
    </div>
</div>
<?php if ($flash_success): ?>
    <div class="alert alert-success"><?php echo sanitize($flash_success); ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
    <div class="alert alert-danger"><?php echo sanitize($flash_error); ?></div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo sanitize($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="text-muted small">Event</div>
                <div class="fw-semibold"><?php echo sanitize($event_details['label'] ?: $event_details['name']); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Age Category</div>
                <div class="fw-semibold"><?php echo sanitize($event_details['age_category']); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Gender</div>
                <div class="fw-semibold"><?php echo sanitize($event_details['gender']); ?></div>
            </div>
        </div>
    </div>
</div>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="h6 mb-0">Event Result Status</h2>
            <form method="post" class="d-flex gap-2 align-items-center mb-0">
                <input type="hidden" name="form_action" value="update_status">
                <select name="result_status" class="form-select" required>
                    <?php foreach ($result_status_options as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo $key === $current_status ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary" title="Update Status">
                    <i class="bi bi-arrow-repeat"></i>
                    <span class="visually-hidden">Update Status</span>
                </button>
            </form>
        </div>
        <p class="text-muted mb-0">Set the publication status for this institution-level event.</p>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h6 mb-3">Approved Institutions</h2>
        <div class="row g-3 mb-3">
            <div class="col-md-3 col-sm-6">
                <div class="text-muted small">Total Approved Institutions</div>
                <div class="fw-semibold"><?php echo number_format($total_institutions); ?></div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="text-muted small">Results Updated</div>
                <div class="fw-semibold">
                    <span class="badge rounded-pill <?php echo sanitize($results_badge_class); ?>"><?php echo number_format($results_updated_count); ?></span>
                </div>
            </div>
        </div>
        <?php $form_placeholders = []; ?>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th scope="col">Sl No</th>
                        <th scope="col">Institution</th>
                        <th scope="col">Affiliation No</th>
                        <th scope="col">Saved Result</th>
                        <th scope="col">Saved Score</th>
                        <th scope="col">Update Result</th>
                        <th scope="col">Update Score</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$institutions): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No approved institutions available for this event.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($institutions as $index => $institution): ?>
                            <?php
                            $form_id = 'institution-result-form-' . (int) $institution['id'];
                            $raw_result_value = strtolower(trim((string) ($institution['result'] ?? '')));
                            $has_saved_result = $raw_result_value !== '' && array_key_exists($raw_result_value, $institution_result_options);
                            $saved_result_label = $has_saved_result ? (string) $institution_result_options[$raw_result_value] : '';
                            $saved_score = trim((string) ($institution['score'] ?? ''));

                            $current_result = $has_saved_result ? $raw_result_value : 'participant';
                            if (!array_key_exists($current_result, $institution_result_options)) {
                                $current_result = 'participant';
                            }
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <?php echo sanitize($institution['name']); ?>
                                    <input type="hidden" name="form_action" value="update_institution" form="<?php echo $form_id; ?>">
                                    <input type="hidden" name="institution_id" value="<?php echo (int) $institution['id']; ?>" form="<?php echo $form_id; ?>">
                                </td>
                                <td><?php echo sanitize($institution['affiliation_number'] ?? ''); ?></td>
                                <td>
                                    <?php if ($saved_result_label !== ''): ?>
                                        <?php $badge_class = $result_badge_classes[$raw_result_value] ?? 'bg-secondary'; ?>
                                        <span class="badge rounded-pill <?php echo sanitize($badge_class); ?>"><?php echo sanitize($saved_result_label); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">Not updated</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($saved_score !== ''): ?>
                                        <span class="fw-semibold"><?php echo sanitize($saved_score); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">Not updated</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <select name="institution_result" class="form-select form-select-sm" form="<?php echo $form_id; ?>">
                                        <?php foreach ($institution_result_options as $value => $label): ?>
                                            <option value="<?php echo $value; ?>" <?php echo $current_result === $value ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="institution_score" maxlength="100" class="form-control form-control-sm" value="<?php echo sanitize($saved_score); ?>" form="<?php echo $form_id; ?>" placeholder="Enter score">
                                </td>
                                <td class="text-end">
                                    <button type="submit" class="btn btn-sm btn-outline-primary" form="<?php echo $form_id; ?>">Update</button>
                                </td>
                            </tr>
                            <?php $form_placeholders[] = '<form id="' . $form_id . '" method="post"></form>'; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
if (!empty($form_placeholders)) {
    foreach ($form_placeholders as $placeholder) {
        echo $placeholder;
    }
}
?>
<?php include __DIR__ . '/includes/footer.php'; ?>
