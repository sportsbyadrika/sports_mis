<?php
require_once __DIR__ . '/includes/header.php';
require_login();
require_role(['super_admin']);

$db = get_db_connection();
$errors = [];
$edit_category = null;

if (is_post()) {
    $action = post_param('action');
    if ($action === 'create' || $action === 'update') {
        $data = [
            'name' => trim((string) post_param('name', '')),
            'min_age' => post_param('min_age') !== null && post_param('min_age') !== '' ? (int) post_param('min_age') : null,
            'max_age' => post_param('max_age') !== null && post_param('max_age') !== '' ? (int) post_param('max_age') : null,
        ];

        validate_required(['name' => 'Category name'], $errors, $data);

        if ($data['min_age'] !== null && $data['min_age'] < 0) {
            $errors['min_age'] = 'Minimum age must be zero or greater.';
        }
        if ($data['max_age'] !== null && $data['max_age'] < 0) {
            $errors['max_age'] = 'Maximum age must be zero or greater.';
        }
        if ($data['min_age'] !== null && $data['max_age'] !== null && $data['min_age'] > $data['max_age']) {
            $errors['max_age'] = 'Maximum age must be greater than or equal to minimum age.';
        }

        if (!$errors) {
            if ($action === 'create') {
                $stmt = $db->prepare('INSERT INTO age_categories (name, min_age, max_age) VALUES (?, ?, ?)');
                $stmt->bind_param('sii', $data['name'], $data['min_age'], $data['max_age']);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Age category created successfully.');
            } else {
                $category_id = (int) post_param('id');
                $stmt = $db->prepare('UPDATE age_categories SET name = ?, min_age = ?, max_age = ?, updated_at = NOW() WHERE id = ?');
                $stmt->bind_param('siii', $data['name'], $data['min_age'], $data['max_age'], $category_id);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Age category updated successfully.');
            }
            redirect('age_categories.php');
        }

        $edit_category = $data;
        $edit_category['id'] = (int) post_param('id');
    } elseif ($action === 'delete') {
        $category_id = (int) post_param('id');
        $stmt = $db->prepare('DELETE FROM age_categories WHERE id = ?');
        $stmt->bind_param('i', $category_id);
        if ($stmt->execute()) {
            set_flash('success', 'Age category removed.');
        } else {
            set_flash('error', 'Unable to delete age category. Remove dependent records first.');
        }
        $stmt->close();
        redirect('age_categories.php');
    }
}

if (!$edit_category && ($edit_id = (int) get_param('edit', 0))) {
    $stmt = $db->prepare('SELECT * FROM age_categories WHERE id = ?');
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $edit_category = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$result = $db->query('SELECT * FROM age_categories ORDER BY COALESCE(min_age, 0), name');
$categories = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$result?->close();

$flash_success = get_flash('success');
$flash_error = get_flash('error');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">Age Categories</h1>
        <p class="text-muted mb-0">Maintain age brackets available for event registrations.</p>
    </div>
</div>
<?php if ($flash_success): ?>
    <div class="alert alert-success"><?php echo sanitize($flash_success); ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
    <div class="alert alert-danger"><?php echo sanitize($flash_error); ?></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><?php echo $edit_category ? 'Edit Age Category' : 'Add Age Category'; ?></h2>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="<?php echo $edit_category ? 'update' : 'create'; ?>">
                    <input type="hidden" name="id" value="<?php echo (int) ($edit_category['id'] ?? 0); ?>">
                    <div class="mb-3">
                        <label for="name" class="form-label">Category Name</label>
                        <input type="text" class="form-control <?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" id="name" name="name" value="<?php echo sanitize($edit_category['name'] ?? ''); ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['name']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="min_age" class="form-label">Minimum Age</label>
                        <input type="number" min="0" class="form-control <?php echo isset($errors['min_age']) ? 'is-invalid' : ''; ?>" id="min_age" name="min_age" value="<?php echo isset($edit_category['min_age']) && $edit_category['min_age'] !== null ? (int) $edit_category['min_age'] : ''; ?>">
                        <?php if (isset($errors['min_age'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['min_age']); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="max_age" class="form-label">Maximum Age</label>
                        <input type="number" min="0" class="form-control <?php echo isset($errors['max_age']) ? 'is-invalid' : ''; ?>" id="max_age" name="max_age" value="<?php echo isset($edit_category['max_age']) && $edit_category['max_age'] !== null ? (int) $edit_category['max_age'] : ''; ?>">
                        <?php if (isset($errors['max_age'])): ?><div class="invalid-feedback"><?php echo sanitize($errors['max_age']); ?></div><?php endif; ?>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary"><?php echo $edit_category ? 'Update Category' : 'Create Category'; ?></button>
                        <?php if ($edit_category): ?>
                            <a href="age_categories.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Minimum Age</th>
                                <th>Maximum Age</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($categories as $category): ?>
                            <tr>
                                <td><?php echo sanitize($category['name']); ?></td>
                                <td><?php echo $category['min_age'] !== null ? (int) $category['min_age'] : '<span class="text-muted">Any</span>'; ?></td>
                                <td><?php echo $category['max_age'] !== null ? (int) $category['max_age'] : '<span class="text-muted">Any</span>'; ?></td>
                                <td class="text-end">
                                    <div class="table-actions justify-content-end">
                                        <a href="age_categories.php?edit=<?php echo (int) $category['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                        <form method="post" onsubmit="return confirm('Delete this age category?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $category['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$categories): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No age categories defined.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
