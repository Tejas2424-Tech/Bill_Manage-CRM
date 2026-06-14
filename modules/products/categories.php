<?php
/**
 * Category Management
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireNotCashier();

$pageTitle = 'Categories';
$breadcrumb = '<a href="' . BASE_URL . '/modules/products/index.php">Products</a><span class="sep"><i class="fas fa-chevron-right"></i></span><span class="current">Categories</span>';

// Handle Add/Edit/Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid security token.');
    } else {
        $action = $_POST['action'] ?? '';
        $name = $_POST['name'] ?? '';
        $desc = $_POST['description'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'add' && !empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
            $stmt->execute([$name, $desc]);
            logAudit('add_category', 'products', "Added category: $name");
            flashMessage('success', 'Category added.');
        } elseif ($action === 'edit' && !empty($name) && $id) {
            $stmt = $pdo->prepare("UPDATE categories SET name=?, description=? WHERE id=?");
            $stmt->execute([$name, $desc, $id]);
            logAudit('edit_category', 'products', "Updated category ID: $id");
            flashMessage('success', 'Category updated.');
        } elseif ($action === 'delete' && $id) {
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id=?");
            $stmt->execute([$id]);
            logAudit('delete_category', 'products', "Deleted category ID: $id");
            flashMessage('success', 'Category deleted.');
        }
    }
    redirect('categories.php');
}

$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$csrf_token = generateCSRFToken();

include_once __DIR__ . '/../../includes/header.php';
?>

<div class="row">
    <div class="col-md-4">
        <div class="form-card">
            <h3 class="card-title mb-4">Add New Category</h3>
            <form action="" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label class="form-label">Category Name*</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="form-group mt-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-100 mt-4">Save Category</button>
            </form>
        </div>
    </div>
    
    <div class="col-md-8">
        <div class="table-wrapper">
            <div class="table-toolbar">
                <h3 class="card-title m-0">Existing Categories</h3>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $c): ?>
                        <tr>
                            <td><div class="fw-600"><?php echo sanitize($c['name']); ?></div></td>
                            <td class="text-muted fs-12"><?php echo sanitize($c['description']); ?></td>
                            <td class="text-end">
                                <div class="d-flex justify-end gap-1">
                                    <button class="btn btn-ghost btn-icon btn-sm" onclick="editCat(<?php echo htmlspecialchars(json_encode($c)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form action="" method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-ghost btn-icon btn-sm text-danger" data-confirm="Delete category?">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title">Edit Category</h3>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <div class="form-group">
                <label class="form-label">Category Name*</label>
                <input type="text" name="name" id="editName" class="form-control" required>
            </div>
            <div class="form-group mt-3">
                <label class="form-label">Description</label>
                <textarea name="description" id="editDesc" class="form-control" rows="3"></textarea>
            </div>
            <div class="d-flex justify-end gap-2 mt-4">
                <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Category</button>
            </div>
        </form>
    </div>
</div>

<script>
function editCat(cat) {
    document.getElementById('editId').value = cat.id;
    document.getElementById('editName').value = cat.name;
    document.getElementById('editDesc').value = cat.description;
    openModal('editModal');
}
</script>

<?php include_once __DIR__ . '/../../includes/footer.php'; ?>
