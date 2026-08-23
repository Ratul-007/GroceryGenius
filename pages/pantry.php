<?php
session_start();
require_once '../config/db.php';

// Session guard
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$avatar_letter = strtoupper(substr($user_name, 0, 1));

$success = '';
$error   = '';

// ── DELETE ITEM ──
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM pantry_items WHERE item_id = ? AND user_id = ?");
    $stmt->execute([$del_id, $user_id]);
    $success = 'Item removed from pantry.';
}

// ── ADD ITEM ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $item_name   = trim($_POST['item_name'] ?? '');
    $category    = trim($_POST['category'] ?? '');
    $quantity    = trim($_POST['quantity'] ?? '');
    $unit        = trim($_POST['unit'] ?? '');
    $expiry_date = trim($_POST['expiry_date'] ?? '');

    if (empty($item_name) || empty($quantity)) {
        $error = 'Item name and quantity are required.';
    } else {
        // Insert or get product
        $chk = $pdo->prepare("SELECT product_id FROM products WHERE name = ? AND category = ?");
        $chk->execute([$item_name, $category]);
        $product = $chk->fetch();

        if ($product) {
            $product_id = $product['product_id'];
        } else {
            $ins = $pdo->prepare("INSERT INTO products (name, category, unit) VALUES (?, ?, ?)");
            $ins->execute([$item_name, $category, $unit]);
            $product_id = $pdo->lastInsertId();
        }

        // Insert pantry item
        $stmt = $pdo->prepare("INSERT INTO pantry_items (user_id, product_id, quantity, expiry_date)
            VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $user_id,
            $product_id,
            $quantity,
            !empty($expiry_date) ? $expiry_date : null
        ]);
        $success = '"' . htmlspecialchars($item_name) . '" added to your pantry!';
    }
}

// ── FETCH PANTRY ITEMS ──
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';

$sql = "SELECT pi.item_id, pi.quantity, pi.expiry_date, pi.added_at,
               p.name as product_name, p.category, p.unit,
               DATEDIFF(pi.expiry_date, CURDATE()) as days_left
        FROM pantry_items pi
        JOIN products p ON pi.product_id = p.product_id
        WHERE pi.user_id = ?";

$params = [$user_id];

if (!empty($search)) {
    $sql .= " AND p.name LIKE ?";
    $params[] = "%$search%";
}

if ($filter === 'expiring') {
    $sql .= " AND pi.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND pi.expiry_date >= CURDATE()";
} elseif ($filter === 'expired') {
    $sql .= " AND pi.expiry_date < CURDATE()";
}

$sql .= " ORDER BY pi.expiry_date IS NULL, pi.expiry_date ASC";

$items_stmt = $pdo->prepare($sql);
$items_stmt->execute($params);
$items = $items_stmt->fetchAll();

// ── COUNTS ──
$total = count($items);
$q_exp = $pdo->prepare("SELECT COUNT(*) FROM pantry_items pi
    WHERE pi.user_id = ? AND pi.expiry_date < CURDATE()");
$q_exp->execute([$user_id]);
$expired_count = $q_exp->fetchColumn();

$q_soon = $pdo->prepare("SELECT COUNT(*) FROM pantry_items pi
    WHERE pi.user_id = ? AND pi.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
$q_soon->execute([$user_id]);
$expiring_count = $q_soon->fetchColumn();

$categories = ['Vegetables', 'Fruits', 'Dairy', 'Meat', 'Grains', 'Beverages', 'Snacks', 'Spices', 'Other'];
$units = ['kg', 'g', 'L', 'ml', 'pcs', 'pack', 'dozen', 'bottle', 'box'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Pantry — GroceryGenius</title>
  <link rel="stylesheet" href="../assets/css/style.css"/>
  <style>
    .pantry-stats {
      display: grid; grid-template-columns: repeat(3, 1fr);
      gap: 14px; margin-bottom: 24px;
    }
    .p-stat {
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 16px 20px;
      display: flex; align-items: center; gap: 14px;
    }
    .p-stat-icon {
      width: 42px; height: 42px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center; font-size: 1.2rem;
    }
    .p-stat-val  { font-size: 1.6rem; font-weight: 800; color: var(--text-main); line-height: 1; }
    .p-stat-label{ font-size: 0.76rem; color: var(--text-muted); margin-top: 2px; }

    /* ADD FORM */
    .add-form-wrap {
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 22px 24px; margin-bottom: 24px;
    }
    .add-form-title {
      font-size: 0.95rem; font-weight: 700; color: var(--text-main);
      margin-bottom: 16px; display: flex; align-items: center; gap: 8px;
    }
    .form-grid {
      display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1.5fr auto;
      gap: 12px; align-items: end;
    }

    /* SEARCH + FILTER BAR */
    .toolbar {
      display: flex; gap: 10px; align-items: center;
      margin-bottom: 16px; flex-wrap: wrap;
    }
    .search-wrap { position: relative; flex: 1; min-width: 200px; }
    .search-wrap input { padding-left: 36px; }
    .search-icon {
      position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
      font-size: 0.95rem; pointer-events: none;
    }
    .filter-btns { display: flex; gap: 6px; }
    .filter-btn {
      padding: 8px 14px; border-radius: var(--radius-sm); font-size: 0.8rem;
      font-weight: 600; cursor: pointer; border: 1px solid var(--border);
      background: var(--bg-card); color: var(--text-muted); transition: all 0.2s;
      text-decoration: none;
    }
    .filter-btn:hover { border-color: var(--purple-400); color: var(--purple-300); }
    .filter-btn.active { background: var(--purple-600); color: #fff; border-color: var(--purple-500); }
    .filter-btn.red.active    { background: #ef4444; border-color: #ef4444; }
    .filter-btn.orange.active { background: #f59e0b; border-color: #f59e0b; color: #000; }

    /* TABLE */
    .pantry-table-wrap {
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: var(--radius); overflow: hidden;
    }
    .table-header-row {
      display: flex; align-items: center; justify-content: space-between;
      padding: 16px 20px; border-bottom: 1px solid var(--border);
    }
    .table-header-row h3 { font-size: 0.9rem; font-weight: 700; color: var(--text-main); }
    .item-count { font-size: 0.78rem; color: var(--text-muted); }

    .table { width: 100%; }
    .table th { background: rgba(61,18,120,0.15); }

    /* ROW STATUS COLORS */
    .row-expired td  { background: rgba(239,68,68,0.06) !important; }
    .row-expiring td { background: rgba(245,158,11,0.06) !important; }

    .expiry-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600;
    }
    .expiry-ok      { background: rgba(16,185,129,0.15); color: #6ee7b7; }
    .expiry-soon    { background: rgba(245,158,11,0.2);  color: #fcd34d; }
    .expiry-expired { background: rgba(239,68,68,0.2);   color: #fca5a5; }
    .expiry-none    { background: rgba(100,116,139,0.15); color: #94a3b8; }

    .delete-btn {
      background: rgba(239,68,68,0.1); color: #fca5a5;
      border: 1px solid rgba(239,68,68,0.2); border-radius: 6px;
      padding: 5px 10px; font-size: 0.8rem; cursor: pointer;
      transition: all 0.2s; text-decoration: none; display: inline-block;
    }
    .delete-btn:hover { background: rgba(239,68,68,0.25); color: #fca5a5; }

    .category-chip {
      display: inline-block; padding: 2px 8px; border-radius: 6px;
      font-size: 0.72rem; font-weight: 600;
      background: rgba(124,58,237,0.15); color: var(--purple-300);
    }

    .empty-pantry {
      text-align: center; padding: 48px 20px; color: var(--text-soft);
    }
    .empty-pantry .empty-icon { font-size: 3rem; margin-bottom: 12px; }
    .empty-pantry p { font-size: 0.9rem; margin-bottom: 16px; }

    @media (max-width: 768px) {
      .pantry-stats { grid-template-columns: 1fr; }
      .form-grid { grid-template-columns: 1fr 1fr; }
      .form-grid .btn { grid-column: 1 / -1; }
    }
  </style>
</head>
<body>

<button class="hamburger" onclick="document.querySelector('.sidebar').classList.toggle('open')">
  <span></span><span></span><span></span>
</button>

<div class="app-layout">

  <!-- SIDEBAR -->
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
      <a href="pantry.php" class="nav-item active">
        <span class="nav-icon">🥦</span> Pantry
      </a>
      <a href="recipes.php" class="nav-item">
        <span class="nav-icon">🍳</span> Recipes
      </a>
      <a href="shopping.php" class="nav-item">
        <span class="nav-icon">🛍️</span> Shopping List
      </a>
      <a href="cooking_history.php" class="nav-item">
    <span class="nav-icon">📖</span> Cooking History
</a>

<div class="nav-label">Finance</div>
      <a href="budget.php" class="nav-item">
        <span class="nav-icon">💰</span> Budget
      </a>
      <a href="expense_history.php" class="nav-item">
    <span class="nav-icon">🧾</span> Expense History
</a>
<a href="monthly_report.php" class="nav-item">
    <span class="nav-icon">📊</span> Monthly Report
</a>
<a href="prices.php" class="nav-item">
        <span class="nav-icon">📊</span> Price Tracker
      </a>
      <div class="nav-label">Account</div>
      <a href="profile.php" class="nav-item">
    <span class="nav-icon">👤</span> Profile
</a>
<a href="logout.php" class="nav-item">
        <span class="nav-icon">🚪</span> Logout
      </a>
    </nav>
    <div class="sidebar-footer">
      <div class="user-info">
        <div class="user-avatar"><?= $avatar_letter ?></div>
        <div>
          <div class="user-name"><?= htmlspecialchars($user_name) ?></div>
          <div class="user-role">Member</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="main-content">

    <div class="page-header">
      <div class="page-title">🥦 Pantry Manager</div>
      <div class="page-sub">Track your groceries, expiry dates and quantities</div>
    </div>

    <!-- ALERTS -->
    <?php if ($success): ?>
      <div class="alert alert-success">✅ <?= $success ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <div class="pantry-stats">
      <div class="p-stat">
        <div class="p-stat-icon" style="background:rgba(124,58,237,0.2)">🥦</div>
        <div>
          <div class="p-stat-val"><?= $total ?></div>
          <div class="p-stat-label">Total Items</div>
        </div>
      </div>
      <div class="p-stat">
        <div class="p-stat-icon" style="background:rgba(245,158,11,0.2)">⏰</div>
        <div>
          <div class="p-stat-val"><?= $expiring_count ?></div>
          <div class="p-stat-label">Expiring in 3 Days</div>
        </div>
      </div>
      <div class="p-stat">
        <div class="p-stat-icon" style="background:rgba(239,68,68,0.2)">❌</div>
        <div>
          <div class="p-stat-val"><?= $expired_count ?></div>
          <div class="p-stat-label">Already Expired</div>
        </div>
      </div>
    </div>

    <!-- ADD ITEM FORM -->
    <div class="add-form-wrap">
      <div class="add-form-title">➕ Add New Item</div>
      <form method="POST" action="">
        <div class="form-grid">
          <div class="form-group" style="margin:0">
            <label>Item Name *</label>
            <input type="text" name="item_name" class="form-control"
              placeholder="e.g. Rice, Egg, Milk" required/>
          </div>
          <div class="form-group" style="margin:0">
            <label>Category</label>
            <select name="category" class="form-control">
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat ?>"><?= $cat ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label>Quantity *</label>
            <input type="number" name="quantity" class="form-control"
              placeholder="e.g. 2" step="0.01" min="0" required/>
          </div>
          <div class="form-group" style="margin:0">
            <label>Unit</label>
            <select name="unit" class="form-control">
              <?php foreach ($units as $u): ?>
                <option value="<?= $u ?>"><?= $u ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label>Expiry Date</label>
            <input type="date" name="expiry_date" class="form-control"
              min="<?= date('Y-m-d') ?>"/>
          </div>
          <div>
            <button type="submit" name="add_item" class="btn btn-primary" style="width:auto;padding:11px 20px">
              Add →
            </button>
          </div>
        </div>
      </form>
    </div>

    <!-- SEARCH + FILTER TOOLBAR -->
    <div class="toolbar">
      <form method="GET" action="" class="search-wrap">
        <span class="search-icon">🔍</span>
        <input type="text" name="search" class="form-control"
          placeholder="Search pantry items..."
          value="<?= htmlspecialchars($search) ?>"/>
        <?php if ($filter !== 'all'): ?>
          <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>"/>
        <?php endif; ?>
      </form>
      <div class="filter-btns">
        <a href="pantry.php" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">All (<?= $total ?>)</a>
        <a href="pantry.php?filter=expiring" class="filter-btn orange <?= $filter === 'expiring' ? 'active' : '' ?>">⏰ Expiring (<?= $expiring_count ?>)</a>
        <a href="pantry.php?filter=expired" class="filter-btn red <?= $filter === 'expired' ? 'active' : '' ?>">❌ Expired (<?= $expired_count ?>)</a>
      </div>
    </div>

    <!-- PANTRY TABLE -->
    <div class="pantry-table-wrap">
      <div class="table-header-row">
        <h3>Your Pantry Items</h3>
        <span class="item-count"><?= count($items) ?> item(s) shown</span>
      </div>

      <?php if (empty($items)): ?>
        <div class="empty-pantry">
          <div class="empty-icon">🥦</div>
          <p><?= !empty($search) ? 'No items found for "' . htmlspecialchars($search) . '"' : 'Your pantry is empty!' ?></p>
          <?php if (empty($search)): ?>
            <p style="font-size:0.82rem">Use the form above to add your first grocery item.</p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th>#</th>
              <th>Item Name</th>
              <th>Category</th>
              <th>Quantity</th>
              <th>Expiry Date</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $i => $item):
              $days = $item['days_left'];
              $expiry = $item['expiry_date'];

              if (!$expiry) {
                $row_class = '';
                $badge_class = 'expiry-none';
                $badge_text = '— No expiry';
              } elseif ($days < 0) {
                $row_class = 'row-expired';
                $badge_class = 'expiry-expired';
                $badge_text = '❌ Expired ' . abs($days) . 'd ago';
              } elseif ($days <= 3) {
                $row_class = 'row-expiring';
                $badge_class = 'expiry-soon';
                $badge_text = '⚠️ ' . $days . ' day' . ($days == 1 ? '' : 's') . ' left';
              } else {
                $row_class = '';
                $badge_class = 'expiry-ok';
                $badge_text = '✅ ' . $days . ' days left';
              }
            ?>
            <tr class="<?= $row_class ?>">
              <td style="color:var(--text-soft)"><?= $i + 1 ?></td>
              <td style="font-weight:600"><?= htmlspecialchars($item['product_name']) ?></td>
              <td><span class="category-chip"><?= htmlspecialchars($item['category']) ?></span></td>
              <td><?= $item['quantity'] ?> <?= htmlspecialchars($item['unit'] ?? '') ?></td>
              <td style="font-size:0.85rem"><?= $expiry ?? '—' ?></td>
              <td><span class="expiry-badge <?= $badge_class ?>"><?= $badge_text ?></span></td>
              <td>
                <a href="pantry.php?delete=<?= $item['item_id'] ?>"
                   class="delete-btn"
                   onclick="return confirm('Remove <?= htmlspecialchars($item['product_name']) ?> from pantry?')">
                  🗑️ Remove
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

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
