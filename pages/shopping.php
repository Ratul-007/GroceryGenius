<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Member';
$avatar_letter = strtoupper(substr($user_name, 0, 1));
$message = '';
$error = '';

// Add a product to the pending shopping list.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $quantity = isset($_POST['quantity']) ? trim($_POST['quantity']) : '';

    if ($product_id <= 0 || $quantity === '' || !is_numeric($quantity) || (float) $quantity <= 0) {
        $error = 'Please select a product and enter a valid quantity.';
    } else {
        $check = $pdo->prepare(
            'SELECT list_item_id FROM shopping_list
             WHERE user_id = :user_id AND product_id = :product_id AND is_purchased = 0
             LIMIT 1'
        );
        $check->execute([
            ':user_id' => $user_id,
            ':product_id' => $product_id
        ]);

        if ($check->fetch()) {
            $error = 'This product is already in your pending shopping list.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO shopping_list (user_id, product_id, quantity, is_purchased)
                 VALUES (:user_id, :product_id, :quantity, 0)'
            );
            $stmt->execute([
                ':user_id' => $user_id,
                ':product_id' => $product_id,
                ':quantity' => $quantity
            ]);
            $message = 'Item added to your shopping list.';
        }
    }
}

// Mark an item as purchased.
if (isset($_GET['purchase'])) {
    $list_item_id = (int) $_GET['purchase'];
    $stmt = $pdo->prepare(
        'UPDATE shopping_list SET is_purchased = 1
         WHERE list_item_id = :list_item_id AND user_id = :user_id'
    );
    $stmt->execute([
        ':list_item_id' => $list_item_id,
        ':user_id' => $user_id
    ]);
    header('Location: shopping.php?status=purchased');
    exit;
}

// Move a purchased item back to pending.
if (isset($_GET['undo'])) {
    $list_item_id = (int) $_GET['undo'];
    $stmt = $pdo->prepare(
        'UPDATE shopping_list SET is_purchased = 0
         WHERE list_item_id = :list_item_id AND user_id = :user_id'
    );
    $stmt->execute([
        ':list_item_id' => $list_item_id,
        ':user_id' => $user_id
    ]);
    header('Location: shopping.php?status=restored');
    exit;
}

// Delete an item belonging to the logged-in user.
if (isset($_GET['delete'])) {
    $list_item_id = (int) $_GET['delete'];
    $stmt = $pdo->prepare(
        'DELETE FROM shopping_list
         WHERE list_item_id = :list_item_id AND user_id = :user_id'
    );
    $stmt->execute([
        ':list_item_id' => $list_item_id,
        ':user_id' => $user_id
    ]);
    header('Location: shopping.php?status=deleted');
    exit;
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

// Products available for manual additions.
$products = $pdo->query(
    'SELECT product_id, name, category, unit FROM products ORDER BY name ASC'
)->fetchAll();

// Pending items.
$pending_stmt = $pdo->prepare(
    'SELECT sl.list_item_id, sl.quantity, p.name, p.category, p.unit
     FROM shopping_list sl
     INNER JOIN products p ON sl.product_id = p.product_id
     WHERE sl.user_id = :user_id AND sl.is_purchased = 0
     ORDER BY p.name ASC'
);
$pending_stmt->execute([':user_id' => $user_id]);
$pending_items = $pending_stmt->fetchAll();

// Purchased items.
$purchased_stmt = $pdo->prepare(
    'SELECT sl.list_item_id, sl.quantity, p.name, p.category, p.unit
     FROM shopping_list sl
     INNER JOIN products p ON sl.product_id = p.product_id
     WHERE sl.user_id = :user_id AND sl.is_purchased = 1
     ORDER BY p.name ASC'
);
$purchased_stmt->execute([':user_id' => $user_id]);
$purchased_items = $purchased_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping List — GroceryGenius</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .shopping-header {
            margin-bottom: 24px;
        }
        .shopping-header h1 {
            margin-bottom: 6px;
        }
        .shopping-header p {
            margin: 0;
            color: var(--text-muted);
        }
        .notice, .error {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 18px;
            font-size: .88rem;
        }
        .notice {
            background: rgba(16,185,129,.12);
            border: 1px solid rgba(16,185,129,.3);
            color: #86efac;
        }
        .error {
            background: rgba(239,68,68,.12);
            border: 1px solid rgba(239,68,68,.3);
            color: #fca5a5;
        }
        .shopping-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 22px;
            margin-bottom: 20px;
        }
        .shopping-card-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .shopping-card-title h2 {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-main);
        }
        .shopping-count {
            color: var(--text-muted);
            font-size: .82rem;
        }
        .add-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 180px auto;
            gap: 12px;
            align-items: end;
        }
        .form-group label {
            display: block;
            margin-bottom: 7px;
            color: var(--text-muted);
            font-size: .8rem;
            font-weight: 600;
        }
        .form-group select,
        .form-group input {
            width: 100%;
            box-sizing: border-box;
            padding: 11px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg-input, var(--bg-card));
            color: var(--text-main);
            outline: none;
        }
        .form-group select:focus,
        .form-group input:focus {
            border-color: var(--purple-400);
        }
        .shopping-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: 0;
            border-radius: var(--radius-sm);
            padding: 10px 15px;
            font-size: .84rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: .2s;
            white-space: nowrap;
        }
        .shopping-btn:hover {
            transform: translateY(-1px);
            opacity: .92;
        }
        .btn-primary { background: var(--purple-600); color: #fff; }
        .btn-success { background: #198754; color: #fff; }
        .btn-secondary { background: #6c757d; color: #fff; }
        .btn-danger { background: #dc3545; color: #fff; }
        .item-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .shopping-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 14px 15px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: rgba(255,255,255,.015);
        }
        .item-info strong {
            display: block;
            color: var(--text-main);
            font-size: .9rem;
        }
        .item-meta {
            color: var(--text-muted);
            font-size: .76rem;
            margin-top: 4px;
        }
        .item-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .empty-state {
            text-align: center;
            padding: 24px;
            color: var(--text-soft);
            font-size: .84rem;
            border: 1px dashed var(--border);
            border-radius: var(--radius-sm);
        }
        @media (max-width: 768px) {
            .add-form { grid-template-columns: 1fr; }
            .shopping-item { align-items: flex-start; flex-direction: column; }
            .item-actions { width: 100%; }
        }
    </style>
</head>
<body>

<!-- Mobile hamburger -->
<button class="hamburger" onclick="document.querySelector('.sidebar').classList.toggle('open')">
    <span></span><span></span><span></span>
</button>

<div class="app-layout">

    <!-- Shared GroceryGenius sidebar -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <div class="logo-text">🛒 GroceryGenius</div>
            <div class="logo-sub">Smart grocery management</div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Main</div>
            <a href="dashboard.php" class="nav-item">
                <span class="nav-icon">🏠</span> Dashboard
            </a>
            <a href="pantry.php" class="nav-item">
                <span class="nav-icon">🥦</span> Pantry
            </a>
            <a href="recipes.php" class="nav-item">
                <span class="nav-icon">🍳</span> Recipes
            </a>
            <a href="shopping.php" class="nav-item active">
                <span class="nav-icon">🛍️</span> Shopping List
            </a>

            <div class="nav-label">Finance</div>
            <a href="budget.php" class="nav-item">
                <span class="nav-icon">💰</span> Budget
            </a>
            <a href="prices.php" class="nav-item">
                <span class="nav-icon">📊</span> Price Tracker
            </a>

            <div class="nav-label">Account</div>
            <a href="logout.php" class="nav-item">
                <span class="nav-icon">🚪</span> Logout
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar"><?= htmlspecialchars($avatar_letter) ?></div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($user_name) ?></div>
                    <div class="user-role">Member</div>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main content -->
    <main class="main-content">
        <div class="shopping-header">
            <h1>Shopping List</h1>
            <p>Keep track of groceries you need to buy and mark them off as you shop.</p>
        </div>

        <?php if ($message): ?>
            <div class="notice"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <section class="shopping-card">
            <div class="shopping-card-title">
                <h2>➕ Add Item</h2>
            </div>
            <form method="POST" class="add-form">
                <div class="form-group">
                    <label for="product_id">Product</label>
                    <select id="product_id" name="product_id" required>
                        <option value="">Select a product</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?= (int) $product['product_id'] ?>">
                                <?= htmlspecialchars($product['name']) ?><?= !empty($product['category']) ? ' — ' . htmlspecialchars($product['category']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="quantity">Quantity</label>
                    <input type="number" id="quantity" name="quantity" min="0.01" step="0.01" placeholder="e.g. 2" required>
                </div>
                <button class="shopping-btn btn-primary" type="submit" name="add_item">+ Add to List</button>
            </form>
        </section>

        <section class="shopping-card">
            <div class="shopping-card-title">
                <h2>Pending Items</h2>
                <span class="shopping-count"><?= count($pending_items) ?> item(s)</span>
            </div>

            <div class="item-list">
                <?php if (empty($pending_items)): ?>
                    <div class="empty-state">Your shopping list is empty. Add an item or use a recipe's missing ingredients option.</div>
                <?php else: ?>
                    <?php foreach ($pending_items as $item): ?>
                        <div class="shopping-item">
                            <div class="item-info">
                                <strong><?= htmlspecialchars($item['name']) ?></strong>
                                <div class="item-meta">
                                    Quantity: <?= htmlspecialchars($item['quantity']) ?><?= !empty($item['unit']) ? ' ' . htmlspecialchars($item['unit']) : '' ?><?= !empty($item['category']) ? ' · ' . htmlspecialchars($item['category']) : '' ?>
                                </div>
                            </div>
                            <div class="item-actions">
                                <a class="shopping-btn btn-success" href="shopping.php?purchase=<?= (int) $item['list_item_id'] ?>">✓ Purchased</a>
                                <a class="shopping-btn btn-danger" href="shopping.php?delete=<?= (int) $item['list_item_id'] ?>" onclick="return confirm('Remove this item from your shopping list?');">Delete</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="shopping-card">
            <div class="shopping-card-title">
                <h2>Purchased Items</h2>
                <span class="shopping-count"><?= count($purchased_items) ?> item(s)</span>
            </div>

            <div class="item-list">
                <?php if (empty($purchased_items)): ?>
                    <div class="empty-state">No purchased items yet.</div>
                <?php else: ?>
                    <?php foreach ($purchased_items as $item): ?>
                        <div class="shopping-item">
                            <div class="item-info">
                                <strong>✓ <?= htmlspecialchars($item['name']) ?></strong>
                                <div class="item-meta">
                                    Quantity: <?= htmlspecialchars($item['quantity']) ?><?= !empty($item['unit']) ? ' ' . htmlspecialchars($item['unit']) : '' ?><?= !empty($item['category']) ? ' · ' . htmlspecialchars($item['category']) : '' ?>
                                </div>
                            </div>
                            <div class="item-actions">
                                <a class="shopping-btn btn-secondary" href="shopping.php?undo=<?= (int) $item['list_item_id'] ?>">↩ Undo</a>
                                <a class="shopping-btn btn-danger" href="shopping.php?delete=<?= (int) $item['list_item_id'] ?>" onclick="return confirm('Delete this purchased item?');">Delete</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<script>
document.addEventListener('click', function(e) {
    const sidebar = document.querySelector('.sidebar');
    const hamburger = document.querySelector('.hamburger');
    if (window.innerWidth <= 768 && !sidebar.contains(e.target) && !hamburger.contains(e.target)) {
        sidebar.classList.remove('open');
    }
});
</script>

</body>
</html>
