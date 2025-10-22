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
    WHERE em.id = ? AND em.event_id = ? AND em.event_type = 'Individual'");
$event_stmt->bind_param('ii', $event_master_id, $assigned_event_id);
$event_stmt->execute();
$event_details = $event_stmt->get_result()->fetch_assoc();
$event_stmt->close();

if (!$event_details) {
    echo '<div class="alert alert-danger">The selected event is not available for result management.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$result_status_options = [
    'pending' => 'Pending',
    'entry' => 'Entry',
    'published' => 'Published',
];

$default_result_options = [
    'participant' => [
        'label' => 'Participant',
        'individual_points' => 0,
        'team_points' => 0,
    ],
    'first_place' => [
        'label' => 'First Place',
        'individual_points' => 0,
        'team_points' => 0,
    ],
    'second_place' => [
        'label' => 'Second Place',
        'individual_points' => 0,
        'team_points' => 0,
    ],
    'third_place' => [
        'label' => 'Third Place',
        'individual_points' => 0,
        'team_points' => 0,
    ],
    'fourth_place' => [
        'label' => 'Fourth Place',
        'individual_points' => 0,
        'team_points' => 0,
    ],
    'fifth_place' => [
        'label' => 'Fifth Place',
        'individual_points' => 0,
        'team_points' => 0,
    ],
    'sixth_place' => [
        'label' => 'Sixth Place',
        'individual_points' => 0,
        'team_points' => 0,
    ],
    'seventh_place' => [
        'label' => 'Seventh Place',
        'individual_points' => 0,
        'team_points' => 0,
    ],
    'eighth_place' => [
        'label' => 'Eighth Place',
        'individual_points' => 0,
        'team_points' => 0,
    ],
    'absent' => [
        'label' => 'Absent',
        'individual_points' => 0,
        'team_points' => 0,
    ],
    'withheld' => [
        'label' => 'Withheld',
        'individual_points' => 0,
        'team_points' => 0,
    ],
];

$result_master_rows = [];
$result_master_stmt = $db->prepare("SELECT result_key, result_label, individual_points, team_points FROM result_master_settings WHERE event_id = ? ORDER BY sort_order ASC, id ASC");
if ($result_master_stmt) {
    $result_master_stmt->bind_param('i', $assigned_event_id);
    $result_master_stmt->execute();
    $result_master_rows = $result_master_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $result_master_stmt->close();
}

$participant_result_options = [];

if ($result_master_rows) {
    foreach ($result_master_rows as $row) {
        $key = strtolower(trim((string) ($row['result_key'] ?? '')));
        if (!array_key_exists($key, $default_result_options)) {
            continue;
        }

        $label = trim((string) ($row['result_label'] ?? ''));
        if ($label === '') {
            $label = $default_result_options[$key]['label'];
        }

        $participant_result_options[$key] = [
            'label' => $label,
            'individual_points' => (float) ($row['individual_points'] ?? 0),
            'team_points' => (float) ($row['team_points'] ?? 0),
        ];
    }

    foreach ($default_result_options as $key => $default_option) {
        if (!isset($participant_result_options[$key])) {
            $participant_result_options[$key] = $default_option;
        }
    }
} else {
    $participant_result_options = $default_result_options;
}

$errors = [];

if (is_post()) {
    $action = post_param('form_action');

    if ($action === 'update_status') {
        $selected_status = strtolower(trim((string) post_param('result_status')));
        if (!array_key_exists($selected_status, $result_status_options)) {
            $errors[] = 'Select a valid result status to update.';
        } else {
            $status_stmt = $db->prepare("INSERT INTO individual_event_result_statuses (event_master_id, status, updated_by)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by)");
            $updated_by = (int) $user['id'];
            $status_stmt->bind_param('isi', $event_master_id, $selected_status, $updated_by);
            if ($status_stmt->execute()) {
                set_flash('success', 'Event result status updated successfully.');
                redirect('result_individual_entry.php?event_master_id=' . $event_master_id);
            } else {
                $errors[] = 'Failed to update the event result status. Please try again.';
            }
            $status_stmt->close();
        }
    } elseif ($action === 'update_participant') {
        $participant_id = (int) post_param('participant_id');
        $selected_result = strtolower(trim((string) post_param('participant_result')));
        if (!array_key_exists($selected_result, $participant_result_options)) {
            $errors[] = 'Select a valid result for the participant.';
        } else {
            $individual_points = round((float) ($participant_result_options[$selected_result]['individual_points'] ?? 0), 2);
            $team_points = round((float) ($participant_result_options[$selected_result]['team_points'] ?? 0), 2);

            $participant_check = $db->prepare("SELECT p.id
                FROM participant_events pe
                INNER JOIN participants p ON p.id = pe.participant_id
                WHERE pe.event_master_id = ? AND pe.participant_id = ? AND p.event_id = ? AND p.status = 'approved'");
            $participant_check->bind_param('iii', $event_master_id, $participant_id, $assigned_event_id);
            $participant_check->execute();
            $participant_exists = $participant_check->get_result()->fetch_assoc();
            $participant_check->close();

            if (!$participant_exists) {
                $errors[] = 'The selected participant is not linked with this event.';
            } else {
                $participant_score = trim((string) post_param('participant_score'));
                if (mb_strlen($participant_score) > 100) {
                    $errors[] = 'The score must not exceed 100 characters.';
                }

                if (!$errors) {
                    $result_stmt = $db->prepare("INSERT INTO individual_event_results (event_master_id, participant_id, result, score, individual_points, team_points, updated_by)"
                        . " VALUES (?, ?, ?, ?, ?, ?, ?)"
                        . " ON DUPLICATE KEY UPDATE result = VALUES(result), score = VALUES(score), individual_points = VALUES(individual_points), team_points = VALUES(team_points), updated_by = VALUES(updated_by)");
                }

                if (!$errors && $result_stmt) {
                    $updated_by = (int) $user['id'];
                    $result_stmt->bind_param('iissddi', $event_master_id, $participant_id, $selected_result, $participant_score, $individual_points, $team_points, $updated_by);
                    if ($result_stmt->execute()) {
                        set_flash('success', 'Participant result updated successfully.');
                        redirect('result_individual_entry.php?event_master_id=' . $event_master_id);
                    } else {
                        $errors[] = 'Failed to update participant result. Please try again.';
                    }
                    $result_stmt->close();
                } elseif (!$errors) {
                    $errors[] = 'Failed to prepare the participant result update. Please try again.';
                }
            }
        }
    }
}

$status_stmt = $db->prepare("SELECT status FROM individual_event_result_statuses WHERE event_master_id = ?");
$status_stmt->bind_param('i', $event_master_id);
$status_stmt->execute();
$current_status = $status_stmt->get_result()->fetch_assoc()['status'] ?? 'pending';
$status_stmt->close();

$participants_stmt = $db->prepare("SELECT p.id, p.name, p.gender, p.chest_number, i.name AS institution_name, res.result, res.score
    FROM participant_events pe
    INNER JOIN participants p ON p.id = pe.participant_id
    INNER JOIN institutions i ON i.id = p.institution_id
    LEFT JOIN individual_event_results res ON res.event_master_id = pe.event_master_id AND res.participant_id = p.id
    WHERE pe.event_master_id = ? AND p.status = 'approved'
    ORDER BY CAST(NULLIF(p.chest_number, '') AS UNSIGNED), p.chest_number, p.name");
$participants_stmt->bind_param('i', $event_master_id);
$participants_stmt->execute();
$participants = $participants_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$participants_stmt->close();

$total_participants = count($participants);
$results_updated_count = 0;

foreach ($participants as $participant_row) {
    $raw_result_value = strtolower(trim((string) ($participant_row['result'] ?? '')));
    if ($raw_result_value !== '' && array_key_exists($raw_result_value, $participant_result_options)) {
        $results_updated_count++;
    }
}

$results_badge_class = $results_updated_count === $total_participants ? 'bg-success' : 'bg-warning text-dark';

$result_badge_classes = [
    'participant' => 'bg-secondary',
    'first_place' => 'bg-success',
    'second_place' => 'bg-primary',
    'third_place' => 'bg-warning text-dark',
    'fourth_place' => 'bg-info text-dark',
    'fifth_place' => 'bg-info text-dark',
    'sixth_place' => 'bg-info text-dark',
    'seventh_place' => 'bg-info text-dark',
    'eighth_place' => 'bg-info text-dark',
    'absent' => 'bg-danger',
    'withheld' => 'bg-dark',
];

$flash_success = get_flash('success');
$flash_error = get_flash('error');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Update Individual Results</h1>
        <p class="text-muted mb-0">Manage participant placements and scores for the selected event.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a
            href="result_individual_certificates.php?event_master_id=<?php echo (int) $event_master_id; ?>&amp;type=merit"
            class="btn btn-outline-success"
            target="_blank"
            rel="noopener"
            title="Generate certificates of merit"
        >
            <i class="bi bi-award"></i>
        </a>
        <a
            href="result_individual_certificates.php?event_master_id=<?php echo (int) $event_master_id; ?>&amp;type=participation"
            class="btn btn-outline-primary"
            target="_blank"
            rel="noopener"
            title="Generate certificates of participation"
        >
            <i class="bi bi-people"></i>
        </a>
        <a href="result_individual_events.php" class="btn btn-outline-secondary">Back to Individual Events</a>
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
            <div class="d-flex align-items-center gap-2">
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
                <a
                    href="result_individual_final_report.php?event_master_id=<?php echo (int) $event_master_id; ?>"
                    class="btn btn-outline-secondary"
                    target="_blank"
                    rel="noopener"
                    title="Open final result report"
                >
                    <i class="bi bi-file-earmark-text"></i>
                    <span class="visually-hidden">Open final result report</span>
                </a>
            </div>
        </div>
        <p class="text-muted mb-0">Set the publication status for this event's individual results.</p>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h6 mb-3">Approved Participants</h2>
        <div class="row g-3 mb-3">
            <div class="col-md-3 col-sm-6">
                <div class="text-muted small">Total Approved Participants</div>
                <div class="fw-semibold"><?php echo number_format($total_participants); ?></div>
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
                        <th scope="col">Chest No</th>
                        <th scope="col">Participant</th>
                        <th scope="col">Institution</th>
                        <th scope="col">Gender</th>
                        <th scope="col">Saved Result</th>
                        <th scope="col">Saved Score</th>
                        <th scope="col">Update Result</th>
                        <th scope="col">Update Score</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$participants): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">No approved participants available for this event.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($participants as $index => $participant): ?>
                            <?php
                            $form_id = 'participant-result-form-' . (int) $participant['id'];
                            $raw_result_value = strtolower(trim((string) ($participant['result'] ?? '')));
                            $has_saved_result = $raw_result_value !== '' && array_key_exists($raw_result_value, $participant_result_options);
                            $saved_result_label = $has_saved_result ? (string) $participant_result_options[$raw_result_value]['label'] : '';
                            $saved_score = trim((string) ($participant['score'] ?? ''));

                            $current_result = $has_saved_result ? $raw_result_value : 'participant';
                            if (!array_key_exists($current_result, $participant_result_options)) {
                                $current_result = 'participant';
                            }
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo $participant['chest_number'] !== null ? sanitize((string) $participant['chest_number']) : sanitize('N/A'); ?></td>
                                <td>
                                    <?php echo sanitize($participant['name']); ?>
                                    <input type="hidden" name="form_action" value="update_participant" form="<?php echo $form_id; ?>">
                                    <input type="hidden" name="participant_id" value="<?php echo (int) $participant['id']; ?>" form="<?php echo $form_id; ?>">
                                </td>
                                <td><?php echo sanitize($participant['institution_name']); ?></td>
                                <td><?php echo sanitize($participant['gender']); ?></td>
                                <td>
                                    <?php if ($saved_result_label !== ''): ?>
                                        <?php
                                        $badge_class = $result_badge_classes[$raw_result_value] ?? 'bg-secondary';
                                        ?>
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
                                    <select name="participant_result" class="form-select form-select-sm" form="<?php echo $form_id; ?>">
                                        <?php foreach ($participant_result_options as $value => $option): ?>
                                            <option value="<?php echo $value; ?>" <?php echo $current_result === $value ? 'selected' : ''; ?>><?php echo sanitize($option['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="participant_score" maxlength="100" class="form-control form-control-sm" value="<?php echo sanitize($saved_score); ?>" form="<?php echo $form_id; ?>" placeholder="Enter score">
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
