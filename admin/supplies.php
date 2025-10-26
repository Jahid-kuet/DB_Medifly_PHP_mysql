<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$editingSupply = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $unitPrice = isset($_POST['unit_price']) ? (float) $_POST['unit_price'] : 0.0;
    $supplyId = isset($_POST['supply_id']) ? (int) $_POST['supply_id'] : 0;

    if ($name === '') {
        setFlash('Supply name is required.', 'danger');
        header('Location: ' . BASE_PATH . '/admin/supplies.php');
        exit;
    }

    if ($supplyId > 0) {
        $stmt = $conn->prepare('UPDATE Supplies SET name = ?, quantity = ?, unit_price = ? WHERE supply_id = ?');
        $stmt->bind_param('sidi', $name, $quantity, $unitPrice, $supplyId);
        $stmt->execute();
        setFlash('Supply updated successfully.');
    } else {
        $stmt = $conn->prepare('INSERT INTO Supplies (name, quantity, unit_price) VALUES (?, ?, ?)');
        $stmt->bind_param('sid', $name, $quantity, $unitPrice);
        $stmt->execute();
        setFlash('Supply created successfully.');
    }

    header('Location: ' . BASE_PATH . '/admin/supplies.php');
    exit;
}

if (isset($_GET['delete'])) {
    $supplyId = (int) $_GET['delete'];
    $stmt = $conn->prepare('DELETE FROM Supplies WHERE supply_id = ?');
    $stmt->bind_param('i', $supplyId);
    $stmt->execute();
    setFlash('Supply deleted.', 'info');
    header('Location: ' . BASE_PATH . '/admin/supplies.php');
    exit;
}

if (isset($_GET['edit'])) {
    $supplyId = (int) $_GET['edit'];
    $stmt = $conn->prepare('SELECT * FROM Supplies WHERE supply_id = ?');
    $stmt->bind_param('i', $supplyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $editingSupply = $result->fetch_assoc();
    if (!$editingSupply) {
        setFlash('Supply not found.', 'danger');
        header('Location: ' . BASE_PATH . '/admin/supplies.php');
        exit;
    }
}

$supplies = [];
$result = $conn->query('SELECT * FROM Supplies ORDER BY name');
while ($row = $result->fetch_assoc()) {
    $supplies[] = $row;
}

$pageTitle = 'Manage Supplies | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><?php echo $editingSupply ? 'Edit Supply' : 'Add Supply'; ?></h2>
                <form method="post" novalidate>
                    <input type="hidden" name="supply_id" value="<?php echo $editingSupply ? (int) $editingSupply['supply_id'] : ''; ?>">
                    <div class="mb-3">
                        <label for="name" class="form-label">Supply Name</label>
                        <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($editingSupply['name'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="quantity" class="form-label">Quantity</label>
                        <input type="number" class="form-control" id="quantity" name="quantity" value="<?php echo htmlspecialchars((string) ($editingSupply['quantity'] ?? '')); ?>" min="0">
                    </div>
                    <div class="mb-3">
                        <label for="unit_price" class="form-label">Unit Price (৳)</label>
                        <input type="number" step="0.01" class="form-control" id="unit_price" name="unit_price" value="<?php echo htmlspecialchars((string) ($editingSupply['unit_price'] ?? '0')); ?>" min="0">
                        <small class="text-muted">This amount will be used to auto-calculate hospital invoices.</small>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><?php echo $editingSupply ? 'Update Supply' : 'Add Supply'; ?></button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">Inventory</h2>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$supplies): ?>
                                <tr>
                                    <td colspan="3" class="text-center py-4">No supplies recorded.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($supplies as $supply): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($supply['name']); ?></td>
                                        <td><?php echo htmlspecialchars((string) $supply['quantity']); ?></td>
                                        <td>৳<?php echo number_format((float) ($supply['unit_price'] ?? 0), 2); ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="/admin/supplies.php?edit=<?php echo (int) $supply['supply_id']; ?>">Edit</a>
                                            <a class="btn btn-sm btn-outline-danger" href="/admin/supplies.php?delete=<?php echo (int) $supply['supply_id']; ?>" onclick="return confirm('Delete this supply?');">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
