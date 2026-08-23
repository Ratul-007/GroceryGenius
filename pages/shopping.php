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
$prefill_product_id = (int) ($_GET['product_id'] ?? 0);
$current_month = date('Y-m');

/*
 * AJAX purchase workflow.
 * Purchase: capture the current grocery price, create permanent purchase history,
 * update the current month's budget, then mark the shopping item purchased.
 * Undo: reverse the exact recorded amount, remove the history record, and return
 * the item to pending.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['ajax_action'];

    if ($action === 'toggle_purchase') {
        $list_item_id = (int) ($_POST['list_item_id'] ?? 0);

        if ($list_item_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid shopping item.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT sl.list_item_id, sl.product_id, sl.is_purchased, sl.quantity,
                        sl.purchase_amount, p.name AS product_name, p.unit,
                        gp.price_bdt AS current_price
                 FROM shopping_list sl
                 INNER JOIN products p ON p.product_id = sl.product_id
                 LEFT JOIN grocery_prices gp ON gp.product_id = sl.product_id
                 WHERE sl.list_item_id = :item_id AND sl.user_id = :user_id
                 LIMIT 1'
            );
            $stmt->execute([
                ':item_id' => $list_item_id,
                ':user_id' => $user_id
            ]);
            $item = $stmt->fetch();

            if (!$item) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Shopping item not found.']);
                exit;
            }

            $was_purchased = (int) $item['is_purchased'] === 1;

            if (!$was_purchased) {
                if ($item['current_price'] === null) {
                    $pdo->rollBack();
                    echo json_encode([
                        'success' => false,
                        'message' => 'This product has no current tracked price. Add a price before marking it purchased.'
                    ]);
                    exit;
                }

                $quantity = (float) $item['quantity'];
                $price_per_unit = (float) $item['current_price'];
                $purchase_amount = round($quantity * $price_per_unit, 2);

                // A monthly budget must exist before a purchase can affect spending.
                $budget_stmt = $pdo->prepare(
                    'SELECT budget_id
                     FROM budget
                     WHERE user_id = :user_id AND month = :month
                     LIMIT 1'
                );
                $budget_stmt->execute([
                    ':user_id' => $user_id,
                    ':month' => $current_month
                ]);
                $budget = $budget_stmt->fetch();

                if (!$budget) {
                    $pdo->rollBack();
                    echo json_encode([
                        'success' => false,
                        'message' => 'Please set your monthly budget before marking an item as purchased.'
                    ]);
                    exit;
                }

                // Prevent duplicate history rows if the request is repeated.
                $history_check = $pdo->prepare(
                    'SELECT purchase_id
                     FROM purchase_history
                     WHERE shopping_list_id = :shopping_list_id
                       AND user_id = :user_id
                     LIMIT 1'
                );
                $history_check->execute([
                    ':shopping_list_id' => $list_item_id,
                    ':user_id' => $user_id
                ]);

                if ($history_check->fetch()) {
                    $pdo->rollBack();
                    echo json_encode([
                        'success' => false,
                        'message' => 'A purchase history record already exists for this item.'
                    ]);
                    exit;
                }

                // Permanently record the price and amount at purchase time.
                $history_stmt = $pdo->prepare(
                    'INSERT INTO purchase_history
                        (user_id, product_id, product_name, quantity, unit,
                         price_per_unit, total_amount, purchased_at, shopping_list_id)
                     VALUES
                        (:user_id, :product_id, :product_name, :quantity, :unit,
                         :price_per_unit, :total_amount, NOW(), :shopping_list_id)'
                );
                $history_stmt->execute([
                    ':user_id' => $user_id,
                    ':product_id' => (int) $item['product_id'],
                    ':product_name' => $item['product_name'],
                    ':quantity' => $quantity,
                    ':unit' => $item['unit'] ?? 'unit',
                    ':price_per_unit' => $price_per_unit,
                    ':total_amount' => $purchase_amount,
                    ':shopping_list_id' => $list_item_id
                ]);

                // Add exactly the same recorded purchase amount to this month's budget.
                $update_budget = $pdo->prepare(
                    'UPDATE budget
                     SET spent_amount = spent_amount + :amount
                     WHERE budget_id = :budget_id AND user_id = :user_id'
                );
                $update_budget->execute([
                    ':amount' => $purchase_amount,
                    ':budget_id' => (int) $budget['budget_id'],
                    ':user_id' => $user_id
                ]);

                // Finally mark the shopping item purchased.
                $update = $pdo->prepare(
                    'UPDATE shopping_list
                     SET is_purchased = 1, purchase_amount = :purchase_amount
                     WHERE list_item_id = :item_id AND user_id = :user_id'
                );
                $update->execute([
                    ':purchase_amount' => $purchase_amount,
                    ':item_id' => $list_item_id,
                    ':user_id' => $user_id
                ]);

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'is_purchased' => 1,
                    'purchase_amount' => $purchase_amount,
                    'message' => 'Item purchased. ৳' . number_format($purchase_amount, 2) . ' added to this month\'s budget and saved to Purchase History.'
                ]);
                exit;
            }

            // Undo: use the amount recorded at the original purchase, not today's price.
            $purchase_amount = $item['purchase_amount'] !== null
                ? (float) $item['purchase_amount']
                : 0.0;

            $budget_stmt = $pdo->prepare(
                'SELECT budget_id
                 FROM budget
                 WHERE user_id = :user_id AND month = :month
                 LIMIT 1'
            );
            $budget_stmt->execute([
                ':user_id' => $user_id,
                ':month' => $current_month
            ]);
            $budget = $budget_stmt->fetch();

            if ($purchase_amount > 0 && $budget) {
                $update_budget = $pdo->prepare(
                    'UPDATE budget
                     SET spent_amount = GREATEST(0, spent_amount - :amount)
                     WHERE budget_id = :budget_id AND user_id = :user_id'
                );
                $update_budget->execute([
                    ':amount' => $purchase_amount,
                    ':budget_id' => (int) $budget['budget_id'],
                    ':user_id' => $user_id
                ]);
            }

            // Remove only the history record belonging to this active shopping item.
            $history_delete = $pdo->prepare(
                'DELETE FROM purchase_history
                 WHERE shopping_list_id = :shopping_list_id
                   AND user_id = :user_id'
            );
            $history_delete->execute([
                ':shopping_list_id' => $list_item_id,
                ':user_id' => $user_id
            ]);

            $update = $pdo->prepare(
                'UPDATE shopping_list
                 SET is_purchased = 0, purchase_amount = NULL
                 WHERE list_item_id = :item_id AND user_id = :user_id'
            );
            $update->execute([
                ':item_id' => $list_item_id,
                ':user_id' => $user_id
            ]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'is_purchased' => 0,
                'purchase_amount' => null,
                'message' => $purchase_amount > 0
                    ? 'Purchase undone. ৳' . number_format($purchase_amount, 2) . ' removed from this month\'s budget and Purchase History.'
                    : 'Item moved back to pending.'
            ]);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('GroceryGenius shopping purchase error: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Unable to update the purchase. Please try again.'
            ]);
            exit;
        }
    }

    if ($action === 'clear_done') {
        try {
            $stmt = $pdo->prepare(
                'DELETE FROM shopping_list
                 WHERE user_id = :user_id AND is_purchased = 1'
            );
            $stmt->execute([':user_id' => $user_id]);
            $deleted = $stmt->rowCount();

            echo json_encode([
                'success' => true,
                'deleted' => $deleted,
                'message' => $deleted > 0
                    ? 'Purchased items cleared. Purchase History and Budget records were kept.'
                    : 'There are no purchased items to clear.'
            ]);
        } catch (Throwable $e) {
            error_log('GroceryGenius clear done error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Unable to clear purchased items.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// Manual add form.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $product_id = (int) ($_POST['product_id'] ?? 0);
    $quantity = trim($_POST['quantity'] ?? '');

    if ($product_id <= 0 || $quantity === '' || !is_numeric($quantity) || (float) $quantity <= 0) {
        $error = 'Please select a product and enter a valid quantity.';
    } else {
        $check = $pdo->prepare(
            'SELECT list_item_id FROM shopping_list
             WHERE user_id = :user_id AND product_id = :product_id AND is_purchased = 0
             LIMIT 1'
        );
        $check->execute([':user_id' => $user_id, ':product_id' => $product_id]);

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
            $prefill_product_id = $product_id;
        }
    }
}

// Delete a single shopping-list item.
if (isset($_GET['delete'])) {
    $list_item_id = (int) $_GET['delete'];
    $stmt = $pdo->prepare(
        'DELETE FROM shopping_list
         WHERE list_item_id = :item_id AND user_id = :user_id'
    );
    $stmt->execute([':item_id' => $list_item_id, ':user_id' => $user_id]);
    header('Location: shopping.php?status=deleted');
    exit;
}

if (isset($_GET['status']) && $_GET['status'] === 'deleted') {
    $message = 'Item removed from the shopping list.';
}

// Product catalogue uses the current value stored in grocery_prices.
$products = $pdo->query(
    'SELECT p.product_id, p.name, p.category, p.unit,
            gp.price_bdt AS current_price
     FROM products p
     LEFT JOIN grocery_prices gp ON gp.product_id = p.product_id
     ORDER BY p.name ASC'
)->fetchAll();

$pending_stmt = $pdo->prepare(
    'SELECT sl.list_item_id, sl.quantity, sl.is_purchased, sl.purchase_amount,
            p.name, p.category, p.unit,
            gp.price_bdt AS current_price
     FROM shopping_list sl
     INNER JOIN products p ON sl.product_id = p.product_id
     LEFT JOIN grocery_prices gp ON gp.product_id = p.product_id
     WHERE sl.user_id = :user_id AND sl.is_purchased = 0
     ORDER BY p.name ASC'
);
$pending_stmt->execute([':user_id' => $user_id]);
$pending_items = $pending_stmt->fetchAll();

$purchased_stmt = $pdo->prepare(
    'SELECT sl.list_item_id, sl.quantity, sl.is_purchased, sl.purchase_amount,
            p.name, p.category, p.unit,
            gp.price_bdt AS current_price
     FROM shopping_list sl
     INNER JOIN products p ON sl.product_id = p.product_id
     LEFT JOIN grocery_prices gp ON gp.product_id = p.product_id
     WHERE sl.user_id = :user_id AND sl.is_purchased = 1
     ORDER BY p.name ASC'
);
$purchased_stmt->execute([':user_id' => $user_id]);
$purchased_items = $purchased_stmt->fetchAll();

$total_items = count($pending_items) + count($purchased_items);
$pending_estimate = 0.0;
foreach ($pending_items as $item) {
    if ($item['current_price'] !== null) {
        $pending_estimate += (float) $item['current_price'] * (float) $item['quantity'];
    }
}

$purchased_estimate = 0.0;
foreach ($purchased_items as $item) {
    if ($item['purchase_amount'] !== null) {
        $purchased_estimate += (float) $item['purchase_amount'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping List — GroceryGenius</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .shopping-header{margin-bottom:24px}.shopping-header h1{margin-bottom:6px}.shopping-header p{margin:0;color:var(--text-muted)}
        .notice,.error{padding:12px 16px;border-radius:var(--radius-sm);margin-bottom:18px;font-size:.88rem}.notice{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:#86efac}.error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
        .shopping-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:22px;margin-bottom:20px}.shopping-card-title{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px}.shopping-card-title h2{margin:0;font-size:1rem;font-weight:700;color:var(--text-main)}.shopping-count{color:var(--text-muted);font-size:.82rem}
        .add-form{display:grid;grid-template-columns:minmax(0,1fr) 180px auto;gap:12px;align-items:end}.form-group label{display:block;margin-bottom:7px;color:var(--text-muted);font-size:.8rem;font-weight:600}.form-group select,.form-group input{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-input,var(--bg-card));color:var(--text-main);outline:none}.form-group select:focus,.form-group input:focus{border-color:var(--purple-400)}
        .shopping-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:var(--radius-sm);padding:10px 15px;font-size:.84rem;font-weight:700;text-decoration:none;cursor:pointer;transition:.2s;white-space:nowrap}.shopping-btn:hover{transform:translateY(-1px);opacity:.92}.btn-primary{background:var(--purple-600);color:#fff}.btn-danger{background:#dc3545;color:#fff}.btn-clear{background:#7f1d1d;color:#fecaca;border:1px solid #991b1b}.btn-clear:hover{background:#991b1b}
        .item-list{display:flex;flex-direction:column;gap:10px}.shopping-item{display:flex;justify-content:space-between;align-items:center;gap:15px;padding:14px 15px;border:1px solid var(--border);border-radius:var(--radius-sm);background:rgba(255,255,255,.015);transition:.2s}.shopping-item:hover{border-color:var(--purple-400)}.item-main{display:flex;align-items:center;gap:12px;min-width:0}.item-check{width:20px;height:20px;min-width:20px;accent-color:#a855f7;cursor:pointer}.item-info{min-width:0}.item-info strong{display:block;color:var(--text-main);font-size:.9rem;transition:.2s}.item-meta{color:var(--text-muted);font-size:.76rem;margin-top:4px}.item-price{color:#86efac;font-size:.76rem;margin-top:5px}.item-price.missing{color:var(--text-muted)}.shopping-item.purchased{background:rgba(16,185,129,.035);border-color:rgba(16,185,129,.18)}.shopping-item.purchased .item-info strong{color:var(--text-soft);text-decoration:line-through;text-decoration-thickness:2px;opacity:.8}.shopping-item.purchased .item-meta{opacity:.7}.item-actions{display:flex;gap:8px;flex-wrap:wrap}.empty-state{text-align:center;padding:24px;color:var(--text-soft);font-size:.84rem;border:1px dashed var(--border);border-radius:var(--radius-sm)}.section-tools{display:flex;align-items:center;gap:10px}.summary{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}.summary-chip{padding:8px 12px;border:1px solid var(--border);border-radius:999px;color:var(--text-muted);background:rgba(255,255,255,.015);font-size:.8rem}.summary-chip strong{color:var(--text-main)}.price-note{font-size:.75rem;color:var(--text-muted);margin-top:6px}
        @media(max-width:768px){.add-form{grid-template-columns:1fr}.shopping-item{align-items:flex-start}.item-actions{width:100%}.section-tools{align-items:flex-end;flex-direction:column}}
    </style>
</head>
<body>
<button class="hamburger" onclick="document.querySelector('.sidebar').classList.toggle('open')"><span></span><span></span><span></span></button>
<div class="app-layout">
    <aside class="sidebar">
        <div class="sidebar-logo"><div class="logo-text">🛒 GroceryGenius</div><div class="logo-sub">Smart grocery management</div></div>
        <nav class="sidebar-nav">
            <div class="nav-label">Main</div>
            <a href="dashboard.php" class="nav-item"><span class="nav-icon">🏠</span> Dashboard</a>
            <a href="pantry.php" class="nav-item"><span class="nav-icon">🥦</span> Pantry</a>
            <a href="recipes.php" class="nav-item"><span class="nav-icon">🍳</span> Recipes</a>
            <a href="shopping.php" class="nav-item active"><span class="nav-icon">🛍️</span> Shopping List</a>
            <a href="cooking_history.php" class="nav-item">
    <span class="nav-icon">📖</span> Cooking History
</a>

<div class="nav-label">Finance</div>
            <a href="budget.php" class="nav-item"><span class="nav-icon">💰</span> Budget</a>
            <a href="expense_history.php" class="nav-item">
    <span class="nav-icon">🧾</span> Expense History
</a>
<a href="monthly_report.php" class="nav-item">
    <span class="nav-icon">📊</span> Monthly Report
</a>
<a href="prices.php" class="nav-item"><span class="nav-icon">📊</span> Price Tracker</a>
            <div class="nav-label">Account</div>
            <a href="profile.php" class="nav-item">
    <span class="nav-icon">👤</span> Profile
</a>
<a href="logout.php" class="nav-item"><span class="nav-icon">🚪</span> Logout</a>
        </nav>
        <div class="sidebar-footer"><div class="user-info"><div class="user-avatar"><?= htmlspecialchars($avatar_letter) ?></div><div><div class="user-name"><?= htmlspecialchars($user_name) ?></div><div class="user-role">Member</div></div></div></div>
    </aside>

    <main class="main-content">
        <div class="shopping-header"><h1>Shopping List</h1><p>Keep track of groceries you need to buy and see their latest tracked prices.</p></div>
        <div id="ajaxNotice" class="notice" style="display:none"></div>
        <?php if($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="summary">
            <div class="summary-chip">Total items: <strong id="totalCount"><?= $total_items ?></strong></div>
            <div class="summary-chip">To buy: <strong id="pendingCount"><?= count($pending_items) ?></strong></div>
            <div class="summary-chip">Purchased: <strong id="purchasedCount"><?= count($purchased_items) ?></strong></div>
            <div class="summary-chip">Estimated to buy: <strong>৳<?= number_format($pending_estimate, 2) ?></strong></div>
            <div class="summary-chip">Recorded purchases: <strong>৳<?= number_format($purchased_estimate, 2) ?></strong></div>
        </div>

        <section class="shopping-card">
            <div class="shopping-card-title"><h2>➕ Add Item</h2></div>
            <form method="POST" class="add-form">
                <div class="form-group"><label for="product_id">Product</label><select id="product_id" name="product_id" required><option value="">Select a product</option><?php foreach($products as $product): ?><option value="<?= (int)$product['product_id'] ?>" <?= $prefill_product_id === (int)$product['product_id'] ? 'selected' : '' ?>><?= htmlspecialchars($product['name']) ?><?= !empty($product['category'])?' — '.htmlspecialchars($product['category']):'' ?><?= $product['current_price'] !== null ? ' — ৳'.number_format((float)$product['current_price'],2).'/'.htmlspecialchars($product['unit'] ?? 'unit') : '' ?></option><?php endforeach; ?></select><div class="price-note">Prices come from the current Price Tracker value.</div></div>
                <div class="form-group"><label for="quantity">Quantity</label><input type="number" id="quantity" name="quantity" min="0.01" step="0.01" placeholder="e.g. 2" required></div>
                <button class="shopping-btn btn-primary" type="submit" name="add_item">+ Add to List</button>
            </form>
        </section>

        <section class="shopping-card">
            <div class="shopping-card-title"><h2>Pending Items</h2><span class="shopping-count" id="pendingSectionCount"><?= count($pending_items) ?> item(s)</span></div>
            <div class="item-list" id="pendingList">
                <?php if(empty($pending_items)): ?><div class="empty-state" id="pendingEmpty">Your shopping list is empty. Add an item or use a recipe's missing ingredients option.</div><?php else: ?>
                    <?php foreach($pending_items as $item): $line_total = $item['current_price'] !== null ? (float)$item['current_price'] * (float)$item['quantity'] : null; ?><div class="shopping-item" data-item-id="<?= (int)$item['list_item_id'] ?>"><div class="item-main"><input class="item-check" type="checkbox" aria-label="Mark <?= htmlspecialchars($item['name']) ?> as purchased" data-item-id="<?= (int)$item['list_item_id'] ?>"><div class="item-info"><strong><?= htmlspecialchars($item['name']) ?></strong><div class="item-meta">Quantity: <?= htmlspecialchars($item['quantity']) ?><?= !empty($item['unit'])?' '.htmlspecialchars($item['unit']):'' ?><?= !empty($item['category'])?' · '.htmlspecialchars($item['category']):'' ?></div><?php if($item['current_price'] !== null): ?><div class="item-price">Latest price: ৳<?= number_format((float)$item['current_price'],2) ?>/<?= htmlspecialchars($item['unit'] ?? 'unit') ?> · Estimated: ৳<?= number_format($line_total,2) ?></div><?php else: ?><div class="item-price missing">No current price tracked for this product.</div><?php endif; ?></div></div><div class="item-actions"><a class="shopping-btn btn-danger" href="shopping.php?delete=<?= (int)$item['list_item_id'] ?>" onclick="return confirm('Remove this item from your shopping list?');">Delete</a></div></div><?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="shopping-card">
            <div class="shopping-card-title"><div><h2>Purchased Items</h2><span class="shopping-count" id="purchasedSectionCount"><?= count($purchased_items) ?> item(s)</span></div><div class="section-tools"><button type="button" class="shopping-btn btn-clear" id="clearDoneBtn" style="<?= empty($purchased_items)?'display:none':'' ?>">Clear Done</button></div></div>
            <div class="item-list" id="purchasedList">
                <?php if(empty($purchased_items)): ?><div class="empty-state" id="purchasedEmpty">No purchased items yet.</div><?php else: ?>
                    <?php foreach($purchased_items as $item): ?><div class="shopping-item purchased" data-item-id="<?= (int)$item['list_item_id'] ?>"><div class="item-main"><input class="item-check" type="checkbox" checked aria-label="Unmark <?= htmlspecialchars($item['name']) ?> as purchased" data-item-id="<?= (int)$item['list_item_id'] ?>"><div class="item-info"><strong><?= htmlspecialchars($item['name']) ?></strong><div class="item-meta">Quantity: <?= htmlspecialchars($item['quantity']) ?><?= !empty($item['unit'])?' '.htmlspecialchars($item['unit']):'' ?><?= !empty($item['category'])?' · '.htmlspecialchars($item['category']):'' ?></div><?php if($item['purchase_amount'] !== null): ?><div class="item-price">Recorded purchase: ৳<?= number_format((float)$item['purchase_amount'],2) ?><?php if($item['current_price'] !== null): ?> · Current tracked price: ৳<?= number_format((float)$item['current_price'],2) ?>/<?= htmlspecialchars($item['unit'] ?? 'unit') ?><?php endif; ?></div><?php else: ?><div class="item-price missing">Purchased without a tracked price; no budget amount was recorded.</div><?php endif; ?></div></div><div class="item-actions"><a class="shopping-btn btn-danger" href="shopping.php?delete=<?= (int)$item['list_item_id'] ?>" onclick="return confirm('Remove this item from your shopping list?');">Delete</a></div></div><?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<script>
(function(){
    const pendingList=document.getElementById('pendingList');
    const purchasedList=document.getElementById('purchasedList');
    const pendingCount=document.getElementById('pendingCount');
    const purchasedCount=document.getElementById('purchasedCount');
    const totalCount=document.getElementById('totalCount');
    const pendingSectionCount=document.getElementById('pendingSectionCount');
    const purchasedSectionCount=document.getElementById('purchasedSectionCount');
    const clearDoneBtn=document.getElementById('clearDoneBtn');
    const ajaxNotice=document.getElementById('ajaxNotice');

    function notice(message,error=false){ajaxNotice.textContent=message;ajaxNotice.className=error?'error':'notice';ajaxNotice.style.display='block';setTimeout(()=>ajaxNotice.style.display='none',4500)}
    function counts(){const p=pendingList.querySelectorAll('.shopping-item').length;const d=purchasedList.querySelectorAll('.shopping-item').length;pendingCount.textContent=p;purchasedCount.textContent=d;totalCount.textContent=p+d;pendingSectionCount.textContent=p+' item(s)';purchasedSectionCount.textContent=d+' item(s)';clearDoneBtn.style.display=d?'inline-flex':'none'}
    function empty(list,id,text){const has=list.querySelector('.shopping-item');const old=document.getElementById(id);if(!has&&!old){const e=document.createElement('div');e.className='empty-state';e.id=id;e.textContent=text;list.appendChild(e)}if(has&&old)old.remove()}
    function bindCheckboxes(){document.querySelectorAll('.item-check:not([data-bound])').forEach(cb=>{cb.dataset.bound='1';cb.addEventListener('change',()=>toggle(cb))})}

    async function toggle(cb){
        const item=cb.closest('.shopping-item');
        cb.disabled=true;
        const originalChecked=!cb.checked;
        const fd=new FormData();
        fd.append('ajax_action','toggle_purchase');
        fd.append('list_item_id',cb.dataset.itemId);
        try{
            const r=await fetch('shopping.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
            const data=await r.json();
            if(!data.success)throw new Error(data.message||'Unable to update item.');
            const purchased=Number(data.is_purchased)===1;
            cb.checked=purchased;
            item.classList.toggle('purchased',purchased);
            if(purchased)purchasedList.appendChild(item);else pendingList.appendChild(item);
            empty(pendingList,'pendingEmpty',"Your shopping list is empty. Add an item or use a recipe's missing ingredients option.");
            empty(purchasedList,'purchasedEmpty','No purchased items yet.');
            counts();
            bindCheckboxes();
            notice(data.message);
        }catch(e){
            cb.checked=originalChecked;
            notice(e.message||'Something went wrong.',true);
        }finally{cb.disabled=false}
    }

    async function clearDone(){
        const items=[...purchasedList.querySelectorAll('.shopping-item')];
        if(!items.length)return;
        if(!confirm('Clear all purchased items from your shopping list?'))return;
        clearDoneBtn.disabled=true;
        const fd=new FormData();fd.append('ajax_action','clear_done');
        try{
            const r=await fetch('shopping.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
            const data=await r.json();
            if(!data.success)throw new Error(data.message||'Unable to clear purchased items.');
            items.forEach(i=>i.remove());
            empty(purchasedList,'purchasedEmpty','No purchased items yet.');
            counts();
            notice(data.message);
        }catch(e){notice(e.message||'Something went wrong.',true)}
        finally{clearDoneBtn.disabled=false}
    }

    bindCheckboxes();
    clearDoneBtn.addEventListener('click',clearDone);
    counts();
})();
</script>
</body>
</html>
