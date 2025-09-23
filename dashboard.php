<?php
require_once __DIR__ . '/includes/header.php';
require_login();

$db = get_db_connection();
$user = current_user();

function fetch_count(mysqli $db, string $query, string $types = '', array $params = []): int
{
    $stmt = $db->prepare($query);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return (int) $count;
}

$cards = [];

switch ($user['role']) {
    case 'super_admin':
        $cards[] = [
            'label' => 'Events',
            'icon' => 'bi-calendar-event',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM events'),
            'link' => 'events.php',
        ];
        $cards[] = [
            'label' => 'Event Admins',
            'icon' => 'bi-people',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM users WHERE role = 'event_admin'"),
            'link' => 'event_admins.php',
        ];
        $cards[] = [
            'label' => 'Institutions',
            'icon' => 'bi-building',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM institutions'),
            'link' => 'events.php',
        ];
        $cards[] = [
            'label' => 'Participants',
            'icon' => 'bi-people-fill',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM participants'),
            'link' => 'events.php',
        ];
        break;
    case 'event_admin':
        if (!$user['event_id']) {
            echo '<div class="alert alert-warning">No event assigned to your account. Please contact the super administrator.</div>';
            include __DIR__ . '/includes/footer.php';
            return;
        }
        $event_id = (int) $user['event_id'];
        $stmt = $db->prepare('SELECT name, start_date, end_date, location FROM events WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $event_id);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $cards[] = [
            'label' => 'Participating Institutions',
            'icon' => 'bi-building',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM institutions WHERE event_id = ?', 'i', [$event_id]),
            'link' => 'institutions.php',
        ];
        $cards[] = [
            'label' => 'Institution Admins',
            'icon' => 'bi-person-badge',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM users WHERE role = 'institution_admin' AND event_id = ?", 'i', [$event_id]),
            'link' => 'institution_admins.php',
        ];
        $cards[] = [
            'label' => 'Event Staff',
            'icon' => 'bi-person-gear',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM users WHERE role = 'event_staff' AND event_id = ?", 'i', [$event_id]),
            'link' => 'event_staff.php',
        ];
        $cards[] = [
            'label' => 'Participants',
            'icon' => 'bi-people-fill',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM participants WHERE event_id = ?', 'i', [$event_id]),
            'link' => 'institutions.php',
        ];
        echo '<div class="mb-4">';
        echo '<h1 class="h3 mb-1">' . sanitize($event['name'] ?? 'Event Dashboard') . '</h1>';
        if ($event) {
            echo '<p class="text-muted mb-0">' . sanitize($event['location'] ?? '') . ' | ' . format_date($event['start_date'] ?? null);
            if (!empty($event['end_date'])) {
                echo ' - ' . format_date($event['end_date']);
            }
            echo '</p>';
        }
        echo '</div>';
        break;
    case 'institution_admin':
        if (!$user['institution_id']) {
            echo '<div class="alert alert-warning">No institution assigned to your account. Please contact the event administrator.</div>';
            include __DIR__ . '/includes/footer.php';
            return;
        }
        $institution_id = (int) $user['institution_id'];
        $cards[] = [
            'label' => 'Total Participants',
            'icon' => 'bi-people',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM participants WHERE institution_id = ?', 'i', [$institution_id]),
            'link' => 'participants.php',
        ];
        $cards[] = [
            'label' => 'Submitted Participants',
            'icon' => 'bi-check-circle',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM participants WHERE institution_id = ? AND status = 'submitted'", 'i', [$institution_id]),
            'link' => 'participants.php?status=submitted',
        ];
        $cards[] = [
            'label' => 'Draft Participants',
            'icon' => 'bi-pencil-square',
            'count' => fetch_count($db, "SELECT COUNT(*) FROM participants WHERE institution_id = ? AND status = 'draft'", 'i', [$institution_id]),
            'link' => 'participants.php?status=draft',
        ];
        echo '<div class="mb-4">';
        $stmt = $db->prepare('SELECT name FROM institutions WHERE id = ?');
        $stmt->bind_param('i', $institution_id);
        $stmt->execute();
        $institution = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        echo '<h1 class="h3">' . sanitize($institution['name'] ?? 'Institution Dashboard') . '</h1>';
        echo '</div>';
        break;
    case 'event_staff':
        if (!$user['event_id']) {
            echo '<div class="alert alert-warning">No event assigned to your account. Please contact the event administrator.</div>';
            include __DIR__ . '/includes/footer.php';
            return;
        }
        $event_id = (int) $user['event_id'];
        $cards[] = [
            'label' => 'Participants',
            'icon' => 'bi-people-fill',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM participants WHERE event_id = ?', 'i', [$event_id]),
            'link' => 'participants.php?event=' . $event_id,
        ];
        $cards[] = [
            'label' => 'Institutions',
            'icon' => 'bi-building',
            'count' => fetch_count($db, 'SELECT COUNT(*) FROM institutions WHERE event_id = ?', 'i', [$event_id]),
            'link' => 'institutions.php',
        ];
        echo '<div class="mb-4">';
        $stmt = $db->prepare('SELECT name FROM events WHERE id = ?');
        $stmt->bind_param('i', $event_id);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        echo '<h1 class="h3">' . sanitize($event['name'] ?? 'Event Staff Dashboard') . '</h1>';
        echo '</div>';
        break;
}
?>
<div class="row g-4">
    <?php foreach ($cards as $card): ?>
        <div class="col-12 col-md-6 col-xl-3">
            <a href="<?php echo sanitize($card['link']); ?>" class="text-decoration-none text-reset">
                <div class="card card-dashboard shadow-sm h-100">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted text-uppercase small"><?php echo sanitize($card['label']); ?></div>
                            <div class="display-6 fw-bold"><?php echo (int) $card['count']; ?></div>
                        </div>
                        <i class="<?php echo sanitize($card['icon']); ?> text-primary fs-1"></i>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
