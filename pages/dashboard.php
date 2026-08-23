<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

// ── STAT QUERIES ──

$q1 = $pdo->prepare("SELECT COUNT(*) FROM pantry_items WHERE user_id = ?");
$q1->execute([$user_id]);
$total_pantry = $q1->fetchColumn();

// Expiring within 7 days (not yet expired)
$q2 = $pdo->prepare("SELECT COUNT(*) FROM pantry_items
    WHERE user_id = ? AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
$q2->execute([$user_id]);
$expiring_soon = $q2->fetchColumn();

// Expired items count
$q_expired = $pdo->prepare("SELECT COUNT(*) FROM pantry_items
    WHERE user_id = ? AND expiry_date < CURDATE()");
$q_expired->execute([$user_id]);
$expired_count = $q_expired->fetchColumn();

$q3 = $pdo->prepare("SELECT COUNT(*) FROM shopping_list WHERE user_id = ? AND is_purchased = 0");
$q3->execute([$user_id]);
$shopping_pending = $q3->fetchColumn();

$month = date('Y-m');
$q4 = $pdo->prepare("SELECT limit_amount, spent_amount FROM budget WHERE user_id = ? AND month = ?");
$q4->execute([$user_id, $month]);
$budget = $q4->fetch();
$budget_pct = 0;
if ($budget && $budget['limit_amount'] > 0) {
    $budget_pct = round(($budget['spent_amount'] / $budget['limit_amount']) * 100);
}

$q7 = $pdo->prepare("SELECT COUNT(*) FROM cooking_history WHERE user_id = ? AND DATE_FORMAT(cooked_at, '%Y-%m') = ?");
$q7->execute([$user_id, $month]);
$cooked_this_month = $q7->fetchColumn();

// Recent pantry items
$q5 = $pdo->prepare("SELECT pi.*, p.name as product_name, p.category
    FROM pantry_items pi
    JOIN products p ON pi.product_id = p.product_id
    WHERE pi.user_id = ?
    ORDER BY pi.added_at DESC LIMIT 5");
$q5->execute([$user_id]);
$recent_items = $q5->fetchAll();

// Expiring within 7 days (not yet expired)
$q6 = $pdo->prepare("SELECT pi.*, p.name as product_name,
    DATEDIFF(pi.expiry_date, CURDATE()) as days_left
    FROM pantry_items pi
    JOIN products p ON pi.product_id = p.product_id
    WHERE pi.user_id = ? AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY pi.expiry_date ASC LIMIT 5");
$q6->execute([$user_id]);
$expiring_items = $q6->fetchAll();

// Expired items list
$q_expired_list = $pdo->prepare("SELECT pi.*, p.name as product_name,
    DATEDIFF(pi.expiry_date, CURDATE()) as days_left
    FROM pantry_items pi
    JOIN products p ON pi.product_id = p.product_id
    WHERE pi.user_id = ? AND expiry_date < CURDATE()
    ORDER BY pi.expiry_date DESC LIMIT 5");
$q_expired_list->execute([$user_id]);
$expired_items = $q_expired_list->fetchAll();

$avatar_letter = strtoupper(substr($user_name, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard — GroceryGenius</title>
  <link rel="stylesheet" href="../assets/css/style.css"/>
  <style>
    .welcome-banner {
      background: linear-gradient(135deg, var(--purple-800), var(--purple-700));
      border: 1px solid var(--purple-600);
      border-radius: var(--radius);
      padding: 22px 24px;
      margin-bottom: 24px;
      display: flex; align-items: center; justify-content: space-between;
      flex-wrap: wrap; gap: 12px;
    }
    .welcome-banner h2 { font-size: 1.25rem; font-weight: 700; color: #fff; }
    .welcome-banner p  { font-size: 0.85rem; color: var(--purple-300); margin-top: 3px; }
    .welcome-badge {
      background: rgba(255,255,255,0.1); padding: 6px 14px;
      border-radius: 20px; font-size: 0.8rem; color: #fff; font-weight: 600;
    }

    .stat-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 16px;
      margin-bottom: 28px;
    }

    .section-title {
      font-size: 0.78rem; font-weight: 700; color: var(--text-muted);
      letter-spacing: 0.08em; text-transform: uppercase; margin-bottom: 12px;
    }
    .two-panel { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

    .empty-state {
      text-align: center; padding: 28px; color: var(--text-soft);
      font-size: 0.85rem;
    }
    .empty-state .empty-icon { font-size: 2rem; margin-bottom: 8px; }

    .budget-label {
      display: flex; justify-content: space-between;
      font-size: 0.82rem; color: var(--text-muted); margin-bottom: 6px;
    }
    .budget-pct-text { font-weight: 700; }

    .quick-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 24px; }
    .quick-btn {
      display: flex; align-items: center; gap: 8px;
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: var(--radius-sm); padding: 10px 16px;
      font-size: 0.85rem; font-weight: 600; color: var(--text-main);
      cursor: pointer; transition: all 0.2s; text-decoration: none;
    }
    .quick-btn:hover { border-color: var(--purple-400); color: var(--purple-300); background: rgba(124,58,237,0.1); }

    .item-row {
      display: flex; align-items: center; justify-content: space-between;
      padding: 10px 0; border-bottom: 1px solid rgba(61,18,120,0.25);
      font-size: 0.87rem;
    }
    .item-row:last-child { border-bottom: none; }
    .item-name { font-weight: 600; color: var(--text-main); }
    .item-meta { font-size: 0.76rem; color: var(--text-muted); margin-top: 2px; }

    @media (max-width: 768px) {
      .stat-grid { grid-template-columns: repeat(2, 1fr); }
      .two-panel { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<button class="hamburger" onclick="document.querySelector('.sidebar').classList.toggle('open')">
  <span></span><span></span><span></span>
</button>

<div class="app-layout">

  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="logo-text">🛒 GroceryGenius</div>
      <div class="logo-sub">Smart grocery management</div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-label">Main</div>
      <a href="dashboard.php" class="nav-item active">
        <span class="nav-icon">🏠</span> Dashboard
      </a>
      <a href="pantry.php" class="nav-item">
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

  <main class="main-content">

    <div class="welcome-banner">
      <div>
        <h2>Good <?= (date('H') < 12) ? 'morning' : ((date('H') < 18) ? 'afternoon' : 'evening') ?>, <?= htmlspecialchars($user_name) ?> 👋</h2>
        <p>Here's what's happening with your groceries today — <?= date('l, F j, Y') ?></p>
      </div>
      <span class="welcome-badge">📅 <?= date('M Y') ?></span>
    </div>

    <!-- STAT CARDS -->
    <div class="stat-grid">

      <div class="stat-card">
        <div class="stat-icon purple">🥦</div>
        <div>
          <div class="stat-val"><?= $total_pantry ?></div>
          <div class="stat-label">Pantry Items</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon red">⚠️</div>
        <div>
          <div class="stat-val"><?= $expiring_soon ?></div>
          <div class="stat-label">Expiring Soon</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon orange">🛍️</div>
        <div>
          <div class="stat-val"><?= $shopping_pending ?></div>
          <div class="stat-label">Shopping Pending</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon green">💰</div>
        <div>
          <div class="stat-val"><?= $budget_pct ?>%</div>
          <div class="stat-label">Budget Used</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon purple">🍳</div>
        <div>
          <div class="stat-val"><?= $cooked_this_month ?></div>
          <div class="stat-label">Recipes Cooked</div>
        </div>
      </div>

    </div>

    <!-- QUICK ACTIONS -->
    <div class="quick-actions">
      <a href="pantry.php" class="quick-btn">➕ Add Pantry Item</a>
      <a href="recipes.php" class="quick-btn">🍳 Get Recipes</a>
      <a href="shopping.php" class="quick-btn">🛍️ Shopping List</a>
      <a href="prices.php" class="quick-btn">📊 Check Prices</a>
      <a href="expense_history.php" class="quick-btn">🧾 Expense History</a>
    </div>

    <!-- TWO PANEL -->
    <div class="two-panel">

      <!-- LEFT: Recent Pantry Items -->
      <div class="card">
        <div class="section-title">Recent Pantry Items</div>
        <?php if (empty($recent_items)): ?>
          <div class="empty-state">
            <div class="empty-icon">🥦</div>
            <p>Your pantry is empty.<br><a href="pantry.php">Add your first item →</a></p>
          </div>
        <?php else: ?>
          <?php foreach ($recent_items as $item): ?>
            <div class="item-row">
              <div>
                <div class="item-name"><?= htmlspecialchars($item['product_name']) ?></div>
                <div class="item-meta"><?= htmlspecialchars($item['category']) ?> · Qty: <?= $item['quantity'] ?></div>
              </div>
              <span class="badge badge-purple"><?= $item['expiry_date'] ?? 'No expiry' ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- RIGHT: Expired + Expiring Soon + Budget -->
      <div style="display:flex;flex-direction:column;gap:20px">

        <!-- EXPIRED BOX -->
        <div class="card">
          <div class="section-title">❌ Expired</div>
          <?php if (empty($expired_items)): ?>
            <div class="empty-state">
              <div class="empty-icon">✅</div>
              <p>No expired items!</p>
            </div>
          <?php else: ?>
            <?php foreach ($expired_items as $item): ?>
              <div class="item-row">
                <div>
                  <div class="item-name"><?= htmlspecialchars($item['product_name']) ?></div>
                  <div class="item-meta">Expired: <?= $item['expiry_date'] ?></div>
                </div>
                <span class="badge badge-danger">Expired</span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- EXPIRING SOON BOX -->
        <div class="card">
          <div class="section-title">⚠️ Expiring Soon</div>
          <?php if (empty($expiring_items)): ?>
            <div class="empty-state">
              <div class="empty-icon">✅</div>
              <p>No items expiring within 7 days!</p>
            </div>
          <?php else: ?>
            <?php foreach ($expiring_items as $item): ?>
              <div class="item-row">
                <div>
                  <div class="item-name"><?= htmlspecialchars($item['product_name']) ?></div>
                  <div class="item-meta">Expires: <?= $item['expiry_date'] ?></div>
                </div>
                <?php
                  $d = (int)$item['days_left'];
                  $cls   = $d <= 1 ? 'badge-danger' : 'badge-warning';
                  $label = $d == 0 ? 'Today!' : ($d == 1 ? '1 day left' : "$d days left");
                ?>
                <span class="badge <?= $cls ?>"><?= $label ?></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- BUDGET -->
        <div class="card">
          <div class="section-title">💰 Budget — <?= date('F Y') ?></div>
          <?php if (!$budget): ?>
            <div class="empty-state">
              <div class="empty-icon">💰</div>
              <p>No budget set.<br><a href="budget.php">Set your budget →</a></p>
            </div>
          <?php else:
            $color = $budget_pct < 60 ? '#10b981' : ($budget_pct < 85 ? '#f59e0b' : '#ef4444');
          ?>
            <div class="budget-label">
              <span>৳<?= number_format($budget['spent_amount'], 2) ?> spent</span>
              <span class="budget-pct-text"><?= $budget_pct ?>%</span>
            </div>
            <div class="progress-wrap">
              <div class="progress-bar" style="width:<?= min($budget_pct,100) ?>%;background:<?= $color ?>"></div>
            </div>
            <div style="margin-top:8px;font-size:0.8rem;color:var(--text-muted)">
              Limit: ৳<?= number_format($budget['limit_amount'], 2) ?> &nbsp;·&nbsp;
              Remaining: ৳<?= number_format($budget['limit_amount'] - $budget['spent_amount'], 2) ?>
            </div>
          <?php endif; ?>
        </div>

      </div>
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