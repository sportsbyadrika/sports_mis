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
                            <th class="text-center" scope="col" style="width: 70px;">Details</th>
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
                                <td class="text-center">
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary btn-sm institution-details-btn"
                                        data-institution-id="<?php echo (int) $row['id']; ?>"
                                        data-institution-name="<?php echo sanitize($row['name']); ?>"
                                        data-affiliation-number="<?php echo sanitize($row['affiliation_number'] ?? ''); ?>"
                                        data-individual-points="<?php echo number_format((float) $row['individual_points'], 2, '.', ''); ?>"
                                        data-team-points="<?php echo number_format((float) $row['team_points'], 2, '.', ''); ?>"
                                        data-grand-total="<?php echo number_format((float) $row['grand_total'], 2, '.', ''); ?>"
                                    >
                                        <i class="bi bi-people-fill"></i>
                                        <span class="visually-hidden">View participants and teams</span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3" class="text-end">Totals</th>
                            <th class="text-end"><?php echo number_format($total_individual_points, 2); ?></th>
                            <th class="text-end"><?php echo number_format($total_team_points, 2); ?></th>
                            <th class="text-end"><?php echo number_format($total_grand_points, 2); ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
<div class="modal fade" id="institutionDetailsModal" tabindex="-1" aria-labelledby="institutionDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title h5 mb-1" id="institutionDetailsModalLabel">Institution Summary</h2>
                    <div class="text-muted" data-field="institution-affiliation"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <h3 class="h5 mb-0" data-field="institution-name"></h3>
                    </div>
                    <div class="col-md-6">
                        <div class="row text-center g-2">
                            <div class="col-4">
                                <div class="text-uppercase text-muted small">Individual Points</div>
                                <div class="fw-semibold" data-field="individual-points">0.00</div>
                            </div>
                            <div class="col-4">
                                <div class="text-uppercase text-muted small">Team Points</div>
                                <div class="fw-semibold" data-field="team-points">0.00</div>
                            </div>
                            <div class="col-4">
                                <div class="text-uppercase text-muted small">Grand Total</div>
                                <div class="fw-semibold" data-field="grand-total">0.00</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <h3 class="h6">Participants</h3>
                    <div class="table-responsive border rounded">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" class="text-center" style="width: 70px;">Sl. No</th>
                                    <th scope="col">Participant</th>
                                    <th scope="col">Participating Event</th>
                                    <th scope="col">Position</th>
                                    <th scope="col">Score</th>
                                    <th scope="col" class="text-end">Points</th>
                                </tr>
                            </thead>
                            <tbody data-role="participants-body">
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">Select an institution to view participant results.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <h3 class="h6">Teams</h3>
                    <div class="table-responsive border rounded">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" class="text-center" style="width: 70px;">Sl. No</th>
                                    <th scope="col">Team</th>
                                    <th scope="col">Event</th>
                                    <th scope="col">Position</th>
                                    <th scope="col">Score</th>
                                    <th scope="col" class="text-end">Points</th>
                                </tr>
                            </thead>
                            <tbody data-role="teams-body">
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">Select an institution to view team results.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const modalElement = document.getElementById('institutionDetailsModal');
        if (!modalElement) {
            return;
        }

        const modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
        const participantsBody = modalElement.querySelector('[data-role="participants-body"]');
        const teamsBody = modalElement.querySelector('[data-role="teams-body"]');
        const nameField = modalElement.querySelector('[data-field="institution-name"]');
        const affiliationField = modalElement.querySelector('[data-field="institution-affiliation"]');
        const individualPointsField = modalElement.querySelector('[data-field="individual-points"]');
        const teamPointsField = modalElement.querySelector('[data-field="team-points"]');
        const grandTotalField = modalElement.querySelector('[data-field="grand-total"]');
        const numberFormatter = new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const tempContainer = document.createElement('div');

        function escapeHtml(value) {
            tempContainer.textContent = value == null ? '' : String(value);
            return tempContainer.innerHTML;
        }

        function setLoadingState() {
            participantsBody.innerHTML = '<tr><td colspan="6" class="text-center py-3">Loading participant details…</td></tr>';
            teamsBody.innerHTML = '<tr><td colspan="6" class="text-center py-3">Loading team details…</td></tr>';
        }

        function renderRows(container, items, emptyMessage) {
            if (!items || items.length === 0) {
                container.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">' + emptyMessage + '</td></tr>';
                return;
            }

            container.innerHTML = items.map(function (item, index) {
                const score = item.score ? item.score : '';
                const points = item.points ? item.points : '0.00';
                return '<tr>' +
                    '<td class="text-center">' + escapeHtml(index + 1) + '</td>' +
                    '<td>' + escapeHtml(item.name || '') + '</td>' +
                    '<td>' + escapeHtml(item.eventLabel || '') + '</td>' +
                    '<td>' + escapeHtml(item.position || '') + '</td>' +
                    '<td>' + escapeHtml(score) + '</td>' +
                    '<td class="text-end">' + escapeHtml(points) + '</td>' +
                '</tr>';
            }).join('');
        }

        function sanitizeDatasetValue(value) {
            return typeof value === 'string' ? value : '';
        }

        function formatPoints(value) {
            const numericValue = Number.parseFloat(value);
            if (Number.isFinite(numericValue)) {
                return numberFormatter.format(numericValue);
            }
            return '0.00';
        }

        document.querySelectorAll('.institution-details-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                const institutionId = button.getAttribute('data-institution-id');
                if (!institutionId) {
                    return;
                }

                const institutionName = sanitizeDatasetValue(button.getAttribute('data-institution-name'));
                const affiliationNumber = sanitizeDatasetValue(button.getAttribute('data-affiliation-number'));
                const individualPoints = formatPoints(button.getAttribute('data-individual-points'));
                const teamPoints = formatPoints(button.getAttribute('data-team-points'));
                const grandTotal = formatPoints(button.getAttribute('data-grand-total'));

                nameField.textContent = institutionName || 'Institution';
                affiliationField.textContent = affiliationNumber ? 'Affiliation Number: ' + affiliationNumber : '';
                individualPointsField.textContent = individualPoints;
                teamPointsField.textContent = teamPoints;
                grandTotalField.textContent = grandTotal;

                setLoadingState();
                modalInstance.show();

                const url = 'result_institution_points_details.php?institution_id=' + encodeURIComponent(institutionId);

                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('Failed to load institution details.');
                        }
                        return response.json();
                    })
                    .then(function (data) {
                        renderRows(participantsBody, data.participants || [], 'No participant results available.');
                        renderRows(teamsBody, data.teams || [], 'No team results available.');
                    })
                    .catch(function () {
                        participantsBody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-3">Unable to load participant details.</td></tr>';
                        teamsBody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-3">Unable to load team details.</td></tr>';
                    });
            });
        });
    });
</script>
<?php
include __DIR__ . '/includes/footer.php';
