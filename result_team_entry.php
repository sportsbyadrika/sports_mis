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
    WHERE em.id = ? AND em.event_id = ? AND em.event_type = 'Team'");
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
$result_master_stmt = $db->prepare("SELECT result_key, result_label, team_points FROM result_master_settings WHERE event_id = ? ORDER BY sort_order ASC, id ASC");
if ($result_master_stmt) {
    $result_master_stmt->bind_param('i', $assigned_event_id);
    $result_master_stmt->execute();
    $result_master_rows = $result_master_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $result_master_stmt->close();
}

$team_result_options = [];

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

        $team_result_options[$key] = [
            'label' => $label,
            'team_points' => (float) ($row['team_points'] ?? 0),
        ];
    }

    foreach ($default_result_options as $key => $default_option) {
        if (!isset($team_result_options[$key])) {
            $team_result_options[$key] = $default_option;
        }
    }
} else {
    $team_result_options = $default_result_options;
}

$errors = [];

if (is_post()) {
    $action = post_param('form_action');

    if ($action === 'update_status') {
        $selected_status = strtolower(trim((string) post_param('result_status')));
        if (!array_key_exists($selected_status, $result_status_options)) {
            $errors[] = 'Select a valid result status to update.';
        } else {
            $status_stmt = $db->prepare("INSERT INTO team_event_result_statuses (event_master_id, status, updated_by)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by)");
            $updated_by = (int) $user['id'];
            $status_stmt->bind_param('isi', $event_master_id, $selected_status, $updated_by);
            if ($status_stmt->execute()) {
                set_flash('success', 'Event result status updated successfully.');
                redirect('result_team_entry.php?event_master_id=' . $event_master_id);
            } else {
                $errors[] = 'Failed to update the event result status. Please try again.';
            }
            $status_stmt->close();
        }
    } elseif ($action === 'update_team_entry') {
        $team_entry_id = (int) post_param('team_entry_id');
        $selected_result = strtolower(trim((string) post_param('team_result')));
        $team_score_input = post_param('team_score');
        $team_score = $team_score_input !== null ? trim((string) $team_score_input) : '';
        if ($team_score === '') {
            $team_score = null;
        } else {
            $team_score = mb_substr($team_score, 0, 255);
        }
        if (!array_key_exists($selected_result, $team_result_options)) {
            $errors[] = 'Select a valid result for the team.';
        } else {
            $team_entry_check = $db->prepare("SELECT id FROM team_entries WHERE id = ? AND event_master_id = ? AND status = 'approved'");
            $team_entry_check->bind_param('ii', $team_entry_id, $event_master_id);
            $team_entry_check->execute();
            $team_entry_exists = $team_entry_check->get_result()->fetch_assoc();
            $team_entry_check->close();

            if (!$team_entry_exists) {
                $errors[] = 'The selected team is not linked with this event.';
            } else {
                $team_points = round((float) ($team_result_options[$selected_result]['team_points'] ?? 0), 2);
                $result_stmt = $db->prepare("INSERT INTO team_event_results (event_master_id, team_entry_id, result, team_points, team_score, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE result = VALUES(result), team_points = VALUES(team_points), team_score = VALUES(team_score), updated_by = VALUES(updated_by)");
                $updated_by = (int) $user['id'];
                $result_stmt->bind_param('iisdsi', $event_master_id, $team_entry_id, $selected_result, $team_points, $team_score, $updated_by);
                if ($result_stmt->execute()) {
                    set_flash('success', 'Team result updated successfully.');
                    redirect('result_team_entry.php?event_master_id=' . $event_master_id);
                } else {
                    $errors[] = 'Failed to update team result. Please try again.';
                }
                $result_stmt->close();
            }
        }
    }
}

$status_stmt = $db->prepare("SELECT status FROM team_event_result_statuses WHERE event_master_id = ?");
$status_stmt->bind_param('i', $event_master_id);
$status_stmt->execute();
$current_status = $status_stmt->get_result()->fetch_assoc()['status'] ?? 'pending';
$status_stmt->close();

$team_entries_stmt = $db->prepare("SELECT te.id, te.team_name, i.name AS institution_name, res.result, res.team_score
    FROM team_entries te
    INNER JOIN institutions i ON i.id = te.institution_id
    LEFT JOIN team_event_results res ON res.event_master_id = te.event_master_id AND res.team_entry_id = te.id
    WHERE te.event_master_id = ? AND te.status = 'approved'
    ORDER BY te.team_name, te.id");
$team_entries_stmt->bind_param('i', $event_master_id);
$team_entries_stmt->execute();
$team_entries = $team_entries_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$team_entries_stmt->close();

$team_members = [];
if ($team_entries) {
    $team_entry_ids = array_map(static fn(array $entry): int => (int) $entry['id'], $team_entries);
    $team_entry_ids = array_values(array_unique($team_entry_ids));
    if ($team_entry_ids) {
        $id_list = implode(',', array_map('intval', $team_entry_ids));
        $members_sql = "SELECT tem.team_entry_id, p.name, p.chest_number
            FROM team_entry_members tem
            INNER JOIN participants p ON p.id = tem.participant_id
            WHERE tem.team_entry_id IN ($id_list)
            ORDER BY tem.team_entry_id, CAST(NULLIF(p.chest_number, '') AS UNSIGNED), p.chest_number, p.name";
        $members_result = $db->query($members_sql);
        if ($members_result) {
            while ($row = $members_result->fetch_assoc()) {
                $team_id = (int) $row['team_entry_id'];
                if (!isset($team_members[$team_id])) {
                    $team_members[$team_id] = [];
                }
                $team_members[$team_id][] = [
                    'name' => (string) ($row['name'] ?? ''),
                    'chest_number' => $row['chest_number'] !== null ? (string) $row['chest_number'] : null,
                ];
            }
            $members_result->free();
        }
    }
}

$total_teams = count($team_entries);
$results_updated_count = 0;

foreach ($team_entries as $team_entry) {
    $raw_result_value = strtolower(trim((string) ($team_entry['result'] ?? '')));
    if ($raw_result_value !== '' && array_key_exists($raw_result_value, $team_result_options)) {
        $results_updated_count++;
    }
}

$results_badge_class = $results_updated_count === $total_teams ? 'bg-success' : 'bg-warning text-dark';

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
        <h1 class="h4 mb-0">Update Team Results</h1>
        <p class="text-muted mb-0">Manage team placements and scores for the selected event.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a
            href="result_team_certificates.php?event_master_id=<?php echo (int) $event_master_id; ?>&amp;type=merit"
            class="btn btn-outline-success"
            target="_blank"
            rel="noopener"
            title="Generate certificates of merit"
        >
            <i class="bi bi-award"></i>
        </a>
        <a
            href="result_team_certificates.php?event_master_id=<?php echo (int) $event_master_id; ?>&amp;type=participation"
            class="btn btn-outline-primary"
            target="_blank"
            rel="noopener"
            title="Generate certificates of participation"
        >
            <i class="bi bi-people"></i>
        </a>
        <a href="result_team_events.php" class="btn btn-outline-secondary">Back to Team Events</a>
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
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <h2 class="h6 mb-0">Event Result Status</h2>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                    <input type="hidden" name="form_action" value="update_status">
                    <select name="result_status" class="form-select" required>
                        <?php foreach ($result_status_options as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $key === $current_status ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary" title="Update result status">
                        <i class="bi bi-arrow-repeat"></i>
                        <span class="visually-hidden">Update Status</span>
                    </button>
                </form>
                <a
                    href="result_team_final_report.php?event_master_id=<?php echo (int) $event_master_id; ?>"
                    class="btn btn-outline-secondary"
                    target="_blank"
                    rel="noopener"
                    title="Open final result report"
                >
                    <i class="bi bi-file-earmark-text"></i>
                    <span class="visually-hidden">View final result report</span>
                </a>
            </div>
        </div>
        <p class="text-muted mb-0">Set the publication status for this event's team results.</p>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h6 mb-3">Approved Teams</h2>
        <div class="row g-3 mb-3">
            <div class="col-md-3 col-sm-6">
                <div class="text-muted small">Total Approved Teams</div>
                <div class="fw-semibold"><?php echo number_format($total_teams); ?></div>
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
                        <th scope="col">Team</th>
                        <th scope="col">Institution</th>
                        <th scope="col">Participants</th>
                        <th scope="col">Saved Result</th>
                        <th scope="col">Update Result &amp; Score</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$team_entries): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No approved teams available for this event.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($team_entries as $index => $team_entry): ?>
                            <?php
                            $form_id = 'team-result-form-' . (int) $team_entry['id'];
                            $raw_result_value = strtolower(trim((string) ($team_entry['result'] ?? '')));
                            $has_saved_result = $raw_result_value !== '' && array_key_exists($raw_result_value, $team_result_options);
                            $saved_result_label = $has_saved_result ? (string) $team_result_options[$raw_result_value]['label'] : '';
                            $saved_score = trim((string) ($team_entry['team_score'] ?? ''));
                            $current_result = $has_saved_result ? $raw_result_value : 'participant';
                            if (!array_key_exists($current_result, $team_result_options)) {
                                $current_result = 'participant';
                            }
                            $current_score = $saved_score;
                            $members = $team_members[(int) $team_entry['id']] ?? [];
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <?php echo sanitize($team_entry['team_name']); ?>
                                    <input type="hidden" name="form_action" value="update_team_entry" form="<?php echo $form_id; ?>">
                                    <input type="hidden" name="team_entry_id" value="<?php echo (int) $team_entry['id']; ?>" form="<?php echo $form_id; ?>">
                                </td>
                                <td><?php echo sanitize($team_entry['institution_name']); ?></td>
                                <td>
                                    <?php if (!$members): ?>
                                        <span class="text-muted small">No participants listed</span>
                                    <?php else: ?>
                                        <?php foreach ($members as $member): ?>
                                            <div>
                                                <?php echo sanitize($member['name']); ?>
                                                <?php if ($member['chest_number'] !== null && $member['chest_number'] !== ''): ?>
                                                    <span class="text-muted">(<?php echo sanitize($member['chest_number']); ?>)</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($saved_result_label !== ''): ?>
                                        <?php $badge_class = $result_badge_classes[$raw_result_value] ?? 'bg-secondary'; ?>
                                        <span class="badge rounded-pill <?php echo sanitize($badge_class); ?>"><?php echo sanitize($saved_result_label); ?></span>
                                        <?php if ($saved_score !== ''): ?>
                                            <div class="small text-muted mt-1">Score: <?php echo sanitize($saved_score); ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">Not updated</span>
                                        <?php if ($saved_score !== ''): ?>
                                            <div class="small text-muted">Score: <?php echo sanitize($saved_score); ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="mb-2">
                                        <select name="team_result" class="form-select form-select-sm" form="<?php echo $form_id; ?>">
                                            <?php foreach ($team_result_options as $value => $option): ?>
                                                <option value="<?php echo $value; ?>" <?php echo $current_result === $value ? 'selected' : ''; ?>><?php echo sanitize($option['label']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <input
                                        type="text"
                                        name="team_score"
                                        value="<?php echo sanitize($current_score); ?>"
                                        class="form-control form-control-sm"
                                        placeholder="Enter score"
                                        maxlength="255"
                                        form="<?php echo $form_id; ?>"
                                    >
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
