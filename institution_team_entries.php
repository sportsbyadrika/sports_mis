<?php
$page_title = 'Team Entries';
require_once __DIR__ . '/includes/header.php';

require_login();
require_role(['institution_admin']);

$user = current_user();
$db = get_db_connection();

if (!$user['institution_id']) {
    echo '<div class="alert alert-warning">No institution assigned to your account. Please contact the event administrator.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$institution_id = (int) $user['institution_id'];

$stmt = $db->prepare('SELECT i.name, e.name AS event_name, e.id AS event_id FROM institutions i JOIN events e ON e.id = i.event_id WHERE i.id = ? LIMIT 1');
$stmt->bind_param('i', $institution_id);
$stmt->execute();
$institution_context = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$institution_context) {
    echo '<div class="alert alert-danger">Unable to load institution information.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$event_id = (int) $institution_context['event_id'];

$form_errors = [];
$old_team_name = trim((string) post_param('team_name', ''));
$old_event_master_id = (int) post_param('event_master_id', 0);
$old_selected_participants = (array) post_param('participants', []);
$old_selected_participants_ids = array_map('intval', $old_selected_participants);
$global_error_message = null;

if (is_post()) {
    $action = post_param('action');

    if ($action === 'create') {
        $team_name = trim((string) post_param('team_name'));
        $event_master_id = (int) post_param('event_master_id');
        $participant_ids = post_param('participants');
        $participant_ids = is_array($participant_ids) ? array_map('intval', $participant_ids) : [];

        if ($team_name === '') {
            $form_errors['team_name'] = 'Team name is required.';
        }

        if ($event_master_id <= 0) {
            $form_errors['event_master_id'] = 'Select a team event.';
        }

        if (!$participant_ids) {
            $form_errors['participants'] = 'Select at least one participant for the team.';
        }

        if (!$form_errors) {
            $stmt = $db->prepare("SELECT id, name FROM event_master WHERE id = ? AND event_id = ? AND event_type = 'Team'");
            $stmt->bind_param('ii', $event_master_id, $event_id);
            $stmt->execute();
            $team_event = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$team_event) {
                $form_errors['event_master_id'] = 'Invalid team event selected.';
            }
        }

        $valid_participants = [];
        if (!$form_errors && $participant_ids) {
            $placeholders = implode(',', array_fill(0, count($participant_ids), '?'));
            $types = str_repeat('i', count($participant_ids) + 1);
            $params = array_merge([$institution_id], $participant_ids);

            $stmt = $db->prepare(
                "SELECT id, name, status FROM participants WHERE institution_id = ? AND id IN ($placeholders) AND status IN ('submitted', 'approved')"
            );
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $valid_participants[(int) $row['id']] = $row;
            }
            $stmt->close();

            if (count($valid_participants) !== count($participant_ids)) {
                $form_errors['participants'] = 'Some selected participants could not be verified. Ensure they belong to your institution and are submitted.';
            }
        }

        if (!$form_errors) {
            try {
                $db->begin_transaction();

                $submitted_by = (int) $user['id'];
                $stmt = $db->prepare('INSERT INTO team_entries (institution_id, event_master_id, team_name, status, submitted_by) VALUES (?, ?, ?, "pending", ?)');
                $stmt->bind_param('iisi', $institution_id, $event_master_id, $team_name, $submitted_by);
                $stmt->execute();
                $team_entry_id = (int) $stmt->insert_id;
                $stmt->close();

                $stmt = $db->prepare('INSERT INTO team_entry_members (team_entry_id, participant_id) VALUES (?, ?)');
                foreach ($participant_ids as $participant_id) {
                    $stmt->bind_param('ii', $team_entry_id, $participant_id);
                    $stmt->execute();
                }
                $stmt->close();

                $db->commit();

                set_flash('success', 'Team entry submitted successfully for approval.');
                redirect('institution_team_entries.php');
            } catch (Throwable $e) {
                $db->rollback();
                $global_error_message = 'An error occurred while saving the team entry. Please try again.';
            }
        } else {
            $global_error_message = 'Please correct the highlighted errors and try again.';
        }
    } elseif ($action === 'delete') {
        $team_entry_id = (int) post_param('id');
        $stmt = $db->prepare("DELETE FROM team_entries WHERE id = ? AND institution_id = ? AND status IN ('pending', 'rejected')");
        $stmt->bind_param('ii', $team_entry_id, $institution_id);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();

        if ($deleted) {
            set_flash('success', 'Team entry removed successfully.');
        } else {
            set_flash('error', 'Only pending or rejected team entries can be removed.');
        }
        redirect('institution_team_entries.php');
    }
}

$stmt = $db->prepare("SELECT id, code, name, label FROM event_master WHERE event_id = ? AND event_type = 'Team' ORDER BY name");
$stmt->bind_param('i', $event_id);
$stmt->execute();
$team_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $db->prepare("SELECT id, name, chest_number, status FROM participants WHERE institution_id = ? ORDER BY name");
$stmt->bind_param('i', $institution_id);
$stmt->execute();
$participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$selectable_statuses = ['submitted', 'approved'];

$stmt = $db->prepare('SELECT te.id, te.team_name, te.status, te.submitted_at, te.reviewed_at, em.name AS event_name, em.code, em.label, u1.name AS submitted_by_name, u2.name AS reviewed_by_name
    FROM team_entries te
    JOIN event_master em ON em.id = te.event_master_id
    LEFT JOIN users u1 ON u1.id = te.submitted_by
    LEFT JOIN users u2 ON u2.id = te.reviewed_by
    WHERE te.institution_id = ?
    ORDER BY te.created_at DESC');
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
        "SELECT tem.team_entry_id, p.id AS participant_id, p.name, p.chest_number
         FROM team_entry_members tem
         JOIN participants p ON p.id = tem.participant_id
         WHERE tem.team_entry_id IN ($placeholders)
         ORDER BY p.name"
    );
    $stmt->bind_param($types, ...$team_entry_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $team_members[(int) $row['team_entry_id']][] = $row;
    }
    $stmt->close();
}

$success_message = get_flash('success');
$error_message = get_flash('error');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Team Entries</h1>
        <p class="text-muted mb-0">Create and manage team entries for <?php echo sanitize($institution_context['event_name']); ?>.</p>
    </div>
    <div class="text-end text-muted small">
        Institution: <?php echo sanitize($institution_context['name']); ?>
    </div>
</div>
<?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo sanitize($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo sanitize($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if ($global_error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo sanitize($global_error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0">Add Team Entry</h2>
            </div>
            <div class="card-body">
                <?php if ($team_events): ?>
                    <form method="post" novalidate>
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label class="form-label" for="team_name">Team Name</label>
                            <input type="text" class="form-control <?php echo isset($form_errors['team_name']) ? 'is-invalid' : ''; ?>" id="team_name" name="team_name" value="<?php echo sanitize($old_team_name); ?>" required>
                            <?php if (isset($form_errors['team_name'])): ?>
                                <div class="invalid-feedback"><?php echo sanitize($form_errors['team_name']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="event_master_id">Team Event</label>
                            <select class="form-select <?php echo isset($form_errors['event_master_id']) ? 'is-invalid' : ''; ?>" id="event_master_id" name="event_master_id" required>
                                <option value="">-- Select Team Event --</option>
                                <?php foreach ($team_events as $event): ?>
                                    <option value="<?php echo (int) $event['id']; ?>" <?php echo $old_event_master_id === (int) $event['id'] ? 'selected' : ''; ?>>
                                        <?php echo sanitize($event['name']); ?>
                                        <?php if (!empty($event['label'])): ?>
                                            (<?php echo sanitize($event['label']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($form_errors['event_master_id'])): ?>
                                <div class="invalid-feedback"><?php echo sanitize($form_errors['event_master_id']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Participants</label>
                            <div class="border rounded p-2 participant-list-scroll" style="max-height: 240px; overflow-y: auto;">
                                <?php foreach ($participants as $participant): ?>
                                    <?php $is_selectable = in_array($participant['status'], $selectable_statuses, true); ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="participants[]" value="<?php echo (int) $participant['id']; ?>" id="participant_<?php echo (int) $participant['id']; ?>" <?php echo $is_selectable ? '' : 'disabled'; ?> <?php echo ($is_selectable && in_array((int) $participant['id'], $old_selected_participants_ids, true)) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="participant_<?php echo (int) $participant['id']; ?>">
                                            <?php echo sanitize($participant['name']); ?>
                                            <?php if (!empty($participant['chest_number'])): ?>
                                                <span class="text-muted">&middot; Chest <?php echo sanitize((string) $participant['chest_number']); ?></span>
                                            <?php endif; ?>
                                            <span class="badge bg-light text-dark border ms-1 text-uppercase"><?php echo sanitize($participant['status']); ?></span>
                                            <?php if (!$is_selectable): ?>
                                                <span class="text-muted small ms-1">(Not eligible yet)</span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!$participants): ?>
                                    <div class="text-muted small">No participants registered for this institution yet.</div>
                                <?php endif; ?>
                            </div>
                            <?php if (isset($form_errors['participants'])): ?>
                                <div class="text-danger small mt-2"><?php echo sanitize($form_errors['participants']); ?></div>
                            <?php endif; ?>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Submit Team Entry</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0">No team events are configured for this event.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Submitted Team Entries</h2>
                <span class="badge bg-secondary text-uppercase">Pending Approval</span>
            </div>
            <div class="card-body">
                <?php if ($team_entries): ?>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Team</th>
                                    <th>Event</th>
                                    <th>Participants</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($team_entries as $entry): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo sanitize($entry['team_name']); ?></div>
                                            <div class="small text-muted">Submitted on <?php echo sanitize(date('d M Y H:i', strtotime($entry['submitted_at']))); ?><?php if (!empty($entry['submitted_by_name'])): ?> by <?php echo sanitize($entry['submitted_by_name']); ?><?php endif; ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo sanitize($entry['event_name']); ?></div>
                                            <div class="text-muted small"><?php echo sanitize($entry['code']); ?><?php if (!empty($entry['label'])): ?> &middot; <?php echo sanitize($entry['label']); ?><?php endif; ?></div>
                                        </td>
                                        <td>
                                            <?php $members = $team_members[$entry['id']] ?? []; ?>
                                            <?php if ($members): ?>
                                                <ul class="list-unstyled mb-0 small">
                                                    <?php foreach ($members as $member): ?>
                                                        <li>
                                                            <?php echo sanitize($member['name']); ?>
                                                            <?php if (!empty($member['chest_number'])): ?>
                                                                <span class="text-muted">(Chest <?php echo sanitize((string) $member['chest_number']); ?>)</span>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <span class="text-muted small">No participants linked.</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status = $entry['status'];
                                            $badge_class = match ($status) {
                                                'approved' => 'bg-success',
                                                'rejected' => 'bg-danger',
                                                default => 'bg-warning text-dark',
                                            };
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?> text-uppercase"><?php echo sanitize($status); ?></span>
                                            <?php if (!empty($entry['reviewed_at'])): ?>
                                                <div class="small text-muted mt-1">Reviewed on <?php echo sanitize(date('d M Y H:i', strtotime($entry['reviewed_at']))); ?><?php if (!empty($entry['reviewed_by_name'])): ?> by <?php echo sanitize($entry['reviewed_by_name']); ?><?php endif; ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if (in_array($entry['status'], ['pending', 'rejected'], true)): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Remove this team entry?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo (int) $entry['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No team entries have been submitted yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
