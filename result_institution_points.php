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

$points_query = $db->prepare(
    "SELECT i.id, i.name, i.affiliation_number,
            COALESCE(individual_points_data.individual_points, 0) AS individual_points,
            (COALESCE(individual_points_data.team_points, 0) + COALESCE(team_points_data.team_points, 0)) AS team_points,
            (COALESCE(individual_points_data.individual_points, 0) + COALESCE(individual_points_data.team_points, 0) + COALESCE(team_points_data.team_points, 0)) AS grand_total
     FROM institutions i
     LEFT JOIN (
         SELECT p.institution_id,
                SUM(ier.individual_points) AS individual_points,
                SUM(ier.team_points) AS team_points
         FROM individual_event_results ier
         INNER JOIN participants p ON p.id = ier.participant_id
         INNER JOIN event_master em ON em.id = ier.event_master_id
         WHERE em.event_id = ? AND p.event_id = ?
         GROUP BY p.institution_id
     ) AS individual_points_data ON individual_points_data.institution_id = i.id
     LEFT JOIN (
         SELECT te.institution_id,
                SUM(ter.team_points) AS team_points
         FROM team_event_results ter
         INNER JOIN team_entries te ON te.id = ter.team_entry_id
         INNER JOIN event_master em ON em.id = ter.event_master_id
         WHERE em.event_id = ?
         GROUP BY te.institution_id
     ) AS team_points_data ON team_points_data.institution_id = i.id
     WHERE i.event_id = ?
     ORDER BY grand_total DESC, i.name ASC"
);

if (!$points_query) {
    echo '<div class="alert alert-danger">Unable to prepare the institution points query. Please try again later.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$points_query->bind_param('iiii', $event_id, $event_id, $event_id, $event_id);
$points_query->execute();
$result = $points_query->get_result();
$institution_points = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$points_query->close();

$total_individual_points = 0.0;
$total_team_points = 0.0;
$total_grand_points = 0.0;

foreach ($institution_points as $row) {
    $total_individual_points += (float) ($row['individual_points'] ?? 0);
    $total_team_points += (float) ($row['team_points'] ?? 0);
    $total_grand_points += (float) ($row['grand_total'] ?? 0);
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Institution Points Summary</h1>
        <p class="text-muted mb-0">Overview of individual and team points earned by each institution.</p>
    </div>
</div>
<?php if (!$institution_points): ?>
    <div class="alert alert-info">No institutions found for your event.</div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" scope="col" style="width: 60px;">#</th>
                            <th scope="col">Institution</th>
                            <th scope="col">Affiliation Code</th>
                            <th class="text-end" scope="col">Individual Points</th>
                            <th class="text-end" scope="col">Team Points</th>
                            <th class="text-end" scope="col">Grand Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($institution_points as $index => $row): ?>
                            <tr>
                                <td class="text-center"><?php echo number_format($index + 1); ?></td>
                                <td><?php echo sanitize($row['name']); ?></td>
                                <td><?php echo sanitize($row['affiliation_number'] ?? ''); ?></td>
                                <td class="text-end"><?php echo number_format((float) $row['individual_points'], 2); ?></td>
                                <td class="text-end"><?php echo number_format((float) $row['team_points'], 2); ?></td>
                                <td class="text-end fw-semibold"><?php echo number_format((float) $row['grand_total'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3" class="text-end">Totals</th>
                            <th class="text-end"><?php echo number_format($total_individual_points, 2); ?></th>
                            <th class="text-end"><?php echo number_format($total_team_points, 2); ?></th>
                            <th class="text-end"><?php echo number_format($total_grand_points, 2); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php
include __DIR__ . '/includes/footer.php';
