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

$event_id = (int) $user['event_id'];

$institutions_stmt = $db->prepare(
    "SELECT i.id, i.name, i.affiliation_number, COUNT(p.id) AS participant_count\n    FROM institutions i\n    LEFT JOIN participants p ON p.institution_id = i.id AND p.status = 'approved' AND p.event_id = ?\n    WHERE i.event_id = ?\n    GROUP BY i.id, i.name, i.affiliation_number\n    ORDER BY i.name ASC"
);

if (!$institutions_stmt) {
    echo '<div class="alert alert-danger">Unable to load institutions at the moment. Please try again later.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$institutions_stmt->bind_param('ii', $event_id, $event_id);
$institutions_stmt->execute();
$institutions = $institutions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$institutions_stmt->close();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Institution Wise Participation Certificate</h1>
        <p class="text-muted mb-0">Generate participation certificates for all approved participants from each institution.</p>
    </div>
</div>
<?php if (!$institutions): ?>
    <div class="alert alert-info">No institutions found for your event.</div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="text-center" style="width: 60px;">#</th>
                            <th scope="col">Institution</th>
                            <th scope="col">Affiliation Code</th>
                            <th scope="col" class="text-end">Participants</th>
                            <th scope="col" class="text-center" style="width: 120px;">Certificates</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($institutions as $index => $institution): ?>
                            <tr>
                                <td class="text-center"><?php echo number_format($index + 1); ?></td>
                                <td><?php echo sanitize($institution['name']); ?></td>
                                <td><?php echo sanitize($institution['affiliation_number'] ?? ''); ?></td>
                                <td class="text-end"><?php echo number_format((int) ($institution['participant_count'] ?? 0)); ?></td>
                                <td class="text-center">
                                    <?php if ((int) ($institution['participant_count'] ?? 0) > 0): ?>
                                        <a
                                            class="btn btn-sm btn-outline-primary"
                                            href="result_institution_participation_certificate_print.php?institution_id=<?php echo (int) $institution['id']; ?>"
                                            target="_blank"
                                            rel="noopener"
                                            title="Generate participation certificates"
                                        >
                                            <i class="bi bi-printer"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted" title="No approved participants available">
                                            <i class="bi bi-printer"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php
include __DIR__ . '/includes/footer.php';
