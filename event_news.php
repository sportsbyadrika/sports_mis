<?php
$page_title = 'Event News';
require_once __DIR__ . '/includes/header.php';
require_login();
require_role(['event_admin']);

$db = get_db_connection();
$user = current_user();

if (!$user['event_id']) {
    echo '<div class="alert alert-warning">No event assigned to your account. Please contact the super administrator.</div>';
    include __DIR__ . '/includes/footer.php';
    return;
}

$event_id = (int) $user['event_id'];
$errors = [];
$flash = null;
$edit_news = null;

if (is_post()) {
    $action = post_param('action', 'create');
    $news_id = (int) post_param('id', 0);

    if ($action === 'delete' && $news_id > 0) {
        $stmt = $db->prepare('DELETE FROM event_news WHERE id = ? AND event_id = ?');
        $stmt->bind_param('ii', $news_id, $event_id);
        $stmt->execute();
        $stmt->close();
        set_flash('success', 'News item removed successfully.');
        redirect('event_news.php');
    }

    if (in_array($action, ['create', 'update'], true)) {
        $data = [
            'title' => trim((string) post_param('title', '')),
            'content' => trim((string) post_param('content', '')),
            'url' => trim((string) post_param('url', '')),
            'status' => post_param('status') === 'inactive' ? 'inactive' : 'active',
        ];

        validate_required([
            'title' => 'Heading',
            'content' => 'Content',
        ], $errors, $data);

        if ($data['url'] && !filter_var($data['url'], FILTER_VALIDATE_URL)) {
            $errors['url'] = 'Please provide a valid URL (including http/https).';
        }

        if (!$errors) {
            if ($action === 'create') {
                $stmt = $db->prepare('INSERT INTO event_news (event_id, title, content, url, status, created_by) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('issssi', $event_id, $data['title'], $data['content'], $data['url'], $data['status'], $user['id']);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'News item created successfully.');
            } else {
                $stmt = $db->prepare('UPDATE event_news SET title = ?, content = ?, url = ?, status = ? WHERE id = ? AND event_id = ?');
                $stmt->bind_param('ssssii', $data['title'], $data['content'], $data['url'], $data['status'], $news_id, $event_id);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'News item updated successfully.');
            }

            redirect('event_news.php');
        }

        $edit_news = $data;
        $edit_news['id'] = $news_id;
    }
}

if (!$edit_news && ($edit_id = (int) get_param('edit', 0))) {
    $stmt = $db->prepare('SELECT id, title, content, url, status FROM event_news WHERE id = ? AND event_id = ?');
    $stmt->bind_param('ii', $edit_id, $event_id);
    $stmt->execute();
    $edit_news = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$stmt = $db->prepare('SELECT id, title, content, url, status, created_at FROM event_news WHERE event_id = ? ORDER BY created_at DESC');
$stmt->bind_param('i', $event_id);
$stmt->execute();
$news_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flash = get_flash('success');

$form_defaults = [
    'title' => '',
    'content' => '',
    'url' => '',
    'status' => 'active',
];
$form_data = array_merge($form_defaults, $edit_news ?? []);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Event News</h1>
        <p class="text-muted mb-0">Share the latest updates with participating institutions and staff.</p>
    </div>
</div>
<?php if ($flash): ?>
    <div class="alert alert-success"><?php echo sanitize($flash); ?></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><?php echo $edit_news ? 'Edit News Item' : 'Add News Item'; ?></h2>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="<?php echo $edit_news ? 'update' : 'create'; ?>">
                    <input type="hidden" name="id" value="<?php echo (int) ($form_data['id'] ?? 0); ?>">
                    <div class="mb-3">
                        <label for="title" class="form-label">Heading</label>
                        <input type="text" class="form-control <?php echo isset($errors['title']) ? 'is-invalid' : ''; ?>" id="title" name="title" value="<?php echo sanitize($form_data['title']); ?>" required>
                        <?php if (isset($errors['title'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['title']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="content" class="form-label">Content</label>
                        <textarea class="form-control <?php echo isset($errors['content']) ? 'is-invalid' : ''; ?>" id="content" name="content" rows="5" required><?php echo sanitize($form_data['content']); ?></textarea>
                        <?php if (isset($errors['content'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['content']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="url" class="form-label">Link <span class="text-muted">(optional)</span></label>
                        <input type="url" class="form-control <?php echo isset($errors['url']) ? 'is-invalid' : ''; ?>" id="url" name="url" value="<?php echo sanitize($form_data['url']); ?>" placeholder="https://example.com">
                        <?php if (isset($errors['url'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['url']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="active" <?php echo ($form_data['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($form_data['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary"><?php echo $edit_news ? 'Update News' : 'Add News'; ?></button>
                        <?php if ($edit_news): ?>
                            <a href="event_news.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Published News</h2>
                <span class="badge bg-secondary"><?php echo count($news_items); ?> items</span>
            </div>
            <div class="card-body">
                <?php if (!$news_items): ?>
                    <p class="text-muted mb-0">No news items found. Use the form to add updates for institutions and staff.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($news_items as $item): ?>
                            <div class="list-group-item py-3">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h3 class="h6 mb-1"><?php echo sanitize($item['title']); ?></h3>
                                        <div class="small text-muted">Posted on <?php echo date('d M Y, h:i A', strtotime($item['created_at'])); ?></div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <span class="badge <?php echo $item['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo ucfirst($item['status']); ?></span>
                                        <a href="event_news.php?edit=<?php echo (int) $item['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                        <form method="post" onsubmit="return confirm('Are you sure you want to delete this news item?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </div>
                                </div>
                                <p class="mb-2 mt-2"><?php echo nl2br(sanitize($item['content'])); ?></p>
                                <?php if (!empty($item['url'])): ?>
                                    <a href="<?php echo sanitize($item['url']); ?>" class="small" target="_blank" rel="noopener">Visit link</a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
