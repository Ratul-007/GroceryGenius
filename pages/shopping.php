<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$message = '';
$error = '';

// Handle add item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $quantity = isset($_POST['quantity']) ? trim($_POST['quantity']) : '';

    if ($product_id <= 0 || $quantity === '' || !is_numeric($quantity) || (float)$quantity <= 0) {
        $error = 'Please select a product and enter a valid quantity.';
    } else {
        $check = $conn->prepare("SELECT list_item_id FROM shopping_list WHERE user_id = ? AND product_id = ? AND is_purchased = 0 LIMIT 1");
        $check->bind_param('ii', $user_id, $product_id);
        $check->execute();
        $existing = $check->get_result();

        if ($existing->num_rows > 0) {
            $error = 'This product is already in your pending shopping list.';
        } else {
            $stmt = $conn->prepare("INSERT INTO shopping_list (user_id, product_id, quantity, is_purchased) VALUES (?, ?, ?, 0)");
            $stmt->bind_param('iis', $user_id, $product_id, $quantity);
            if ($stmt->execute()) {
                $message = 'Item added to your shopping list.';
            } else {
                $error = 'Unable to add the item. Please try again.';
            }
            $stmt->close();
        }
        $check->close();
    }
}

// Mark pending item as purchased
if (isset($_GET['purchase'])) {
    $list_item_id = (int) $_GET['purchase'];
    $stmt = $conn->prepare("UPDATE shopping_list SET is_purchased = 1 WHERE list_item_id = ? AND user_id = ?");
    $stmt->bind_param('ii', $list_item_id, $user_id);
    $stmt->execute();
    $stmt->close();
    header('Location: shopping.php?status=purchased');
    exit();
}

// Undo purchased item
if (isset($_GET['undo'])) {
    $list_item_id = (int) $_GET['undo'];
    $stmt = $conn->prepare("UPDATE shopping_list SET is_purchased = 0 WHERE list_item_id = ? AND user_id = ?");
    $stmt->bind_param('ii', $list_item_id, $user_id);
    $stmt->execute();
    $stmt->close();
    header('Location: shopping.php?status=restored');
    exit();
}

// Delete item
if (isset($_GET['delete'])) {
    $list_item_id = (int) $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM shopping_list WHERE list_item_id = ? AND user_id = ?");
    $stmt->bind_param('ii', $list_item_id, $user_id);
    $stmt->execute();
    $stmt->close();
    header('Location: shopping.php?status=deleted');
    exit();
}

if (isset($_GET['status'])) {
    $status_messages = [
        'purchased' => 'Item marked as purchased.',
        'restored' => 'Item moved back to pending.',
        'deleted' => 'Item removed from the shopping list.'
    ];
    if (isset($status_messages[$_GET['status']])) {
        $message = $status_messages[$_GET['status']];
    }
}

// Available products for manual additions
$products = $conn->query("SELECT product_id, name, category, unit FROM products ORDER BY name ASC");

// Pending items
$pending = $conn->prepare("SELECT sl.list_item_id, sl.quantity, p.name, p.category, p.unit FROM shopping_list sl INNER JOIN products p ON sl.product_id = p.product_id WHERE sl.user_id = ? AND sl.is_purchased = 0 ORDER BY p.name ASC");
$pending->bind_param('i', $user_id);
$pending->execute();
$pending_items = $pending->get_result();

// Purchased items
$purchased = $conn->prepare("SELECT sl.list_item_id, sl.quantity, p.name, p.category, p.unit FROM shopping_list sl INNER JOIN products p ON sl.product_id = p.product_id WHERE sl.user_id = ? AND sl.is_purchased = 1 ORDER BY p.name ASC");
$purchased->bind_param('i', $user_id);
$purchased->execute();
$purchased_items = $purchased->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping List - GroceryGenius</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .shopping-wrap { max-width: 1200px; margin: 0 auto; padding: 30px; }
        .page-header { margin-bottom: 24px; }
        .page-header h1 { margin-bottom: 6px; }
        .page-header p { margin: 0; opacity: .75; }
        .notice { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; background: #e8f7ee; }
        .error { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; background: #fdeaea; }
        .add-card, .list-card { background: #fff; border-radius: 12px; padding: 22px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,.06); }
        .add-form { display: grid; grid-template-columns: 1fr 180px auto; gap: 12px; align-items: end; }
        .form-group label { display: block; margin-bottom: 7px; font-weight: 600; }
        .form-group select, .form-group input { width: 100%; padding: 10px 12px; border: 1px solid #d9dfe5; border-radius: 7px; box-sizing: border-box; }
        .btn { display: inline-block; padding: 10px 15px; border: 0; border-radius: 7px; cursor: pointer; text-decoration: none; font-weight: 600; }
        .btn-primary { background: #198754; color: #fff; }
        .btn-success { background: #198754; color: #fff; }
        .btn-secondary { background: #6c757d; color: #fff; }
        .btn-danger { background: #dc3545; color: #fff; }
        .item-list { display: flex; flex-direction: column; gap: 10px; }
        .item { display: flex; justify-content: space-between; align-items: center; gap: 15px; padding: 15px; border: 1px solid #e7eaee; border-radius: 9px; }
        .item-info strong { display: block; font-size: 16px; }
        .item-meta { font-size: 13px; opacity: .7; margin-top: 4px; }
        .item-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .empty { padding: 25px; text-align: center; opacity: .65; border: 1px dashed #ccd2d8; border-radius: 9px; }
        .section-title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        @media (max-width: 700px) {
            .shopping-wrap { padding: 18px; }
            .add-form { grid-template-columns: 1fr; }
            .item { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<main class="shopping-wrap">
    <div class="page-header">
        <h1>Shopping List</h1>
        <p>Keep track of groceries you need to buy and mark them off as you shop.</p>
    </div>

    <?php if ($message): ?>
        <div class="notice"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <section class="add-card">
        <div class="section-title"><h2>Add Item</h2></div>
        <form method="POST" class="add-form">
            <div class="form-group">
                <label for="product_id">Product</label>
                <select id="product_id" name="product_id" required>
                    <option value="">Select a product</option>
                    <?php if ($products): while ($product = $products->fetch_assoc()): ?>
                        <option value="<?php echo (int)$product['product_id']; ?>">
                            <?php echo htmlspecialchars($product['name']); ?>
                            <?php if (!empty($product['category'])) echo ' — ' . htmlspecialchars($product['category']); ?>
                        </option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="quantity">Quantity</label>
                <input type="number" id="quantity" name="quantity" min="0.01" step="0.01" placeholder="e.g. 2" required>
            </div>
            <button class="btn btn-primary" type="submit" name="add_item">+ Add to List</button>
        </form>
    </section>

    <section class="list-card">
        <div class="section-title">
            <h2>Pending Items</h2>
            <span><?php echo $pending_items->num_rows; ?> item(s)</span>
        </div>

        <div class="item-list">
            <?php if ($pending_items->num_rows === 0): ?>
                <div class="empty">Your shopping list is empty. Add an item or use a recipe's missing ingredients option.</div>
            <?php else: ?>
                <?php while ($item = $pending_items->fetch_assoc()): ?>
                    <div class="item">
                        <div class="item-info">
                            <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                            <div class="item-meta">
                                Quantity: <?php echo htmlspecialchars($item['quantity']); ?><?php echo !empty($item['unit']) ? ' ' . htmlspecialchars($item['unit']) : ''; ?>
                                <?php if (!empty($item['category'])) echo ' · ' . htmlspecialchars($item['category']); ?>
                            </div>
                        </div>
                        <div class="item-actions">
                            <a class="btn btn-success" href="shopping.php?purchase=<?php echo (int)$item['list_item_id']; ?>">✓ Purchased</a>
                            <a class="btn btn-danger" href="shopping.php?delete=<?php echo (int)$item['list_item_id']; ?>" onclick="return confirm('Remove this item from your shopping list?');">Delete</a>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="list-card">
        <div class="section-title">
            <h2>Purchased Items</h2>
            <span><?php echo $purchased_items->num_rows; ?> item(s)</span>
        </div>

        <div class="item-list">
            <?php if ($purchased_items->num_rows === 0): ?>
                <div class="empty">No purchased items yet.</div>
            <?php else: ?>
                <?php while ($item = $purchased_items->fetch_assoc()): ?>
                    <div class="item">
                        <div class="item-info">
                            <strong>✓ <?php echo htmlspecialchars($item['name']); ?></strong>
                            <div class="item-meta">
                                Quantity: <?php echo htmlspecialchars($item['quantity']); ?><?php echo !empty($item['unit']) ? ' ' . htmlspecialchars($item['unit']) : ''; ?>
                            </div>
                        </div>
                        <div class="item-actions">
                            <a class="btn btn-secondary" href="shopping.php?undo=<?php echo (int)$item['list_item_id']; ?>">↩ Undo</a>
                            <a class="btn btn-danger" href="shopping.php?delete=<?php echo (int)$item['list_item_id']; ?>" onclick="return confirm('Delete this purchased item?');">Delete</a>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </section>
</main>
</body>
</html>

<?php
$pending->close();
$purchased->close();
$conn->close();
?>
