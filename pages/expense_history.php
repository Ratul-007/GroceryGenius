<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id       = (int)$_SESSION['user_id'];
$user_name     = $_SESSION['user_name'] ?? 'User';
$avatar_letter = strtoupper(substr($user_name, 0, 1));

// Month filter — default current month
$selected_month = $_GET['month'] ?? date('Y-m');


// ============================================================
// STATS FOR SELECTED MONTH
// ============================================================

$stats_stmt = $pdo->prepare("
    SELECT
        COUNT(*)                  AS total_purchases,
        COALESCE(SUM(total_amount), 0) AS total_spent,
        COALESCE(MAX(total_amount), 0) AS biggest_purchase
    FROM purchase_history
    WHERE user_id = ?
      AND DATE_FORMAT(purchased_at, '%Y-%m') = ?
");
$stats_stmt->execute([$user_id, $selected_month]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);


// Most bought product this month
$top_stmt = $pdo->prepare("
    SELECT product_name, SUM(total_amount) AS total
    FROM purchase_history
    WHERE user_id = ?
      AND DATE_FORMAT(purchased_at, '%Y-%m') = ?
    GROUP BY product_name
    ORDER BY total DESC
    LIMIT 1
");
$top_stmt->execute([$user_id, $selected_month]);
$top_product = $top_stmt->fetch(PDO::FETCH_ASSOC);


// ============================================================
// ALL PURCHASES FOR SELECTED MONTH (grouped by date in PHP)
// ============================================================

$history_stmt = $pdo->prepare("
    SELECT
        purchase_id,
        product_name,
        quantity,
        unit,
        price_per_unit,
        total_amount,
        purchased_at
    FROM purchase_history
    WHERE user_id = ?
      AND DATE_FORMAT(purchased_at, '%Y-%m') = ?
    ORDER BY purchased_at DESC
");
$history_stmt->execute([$user_id, $selected_month]);
$all_purchases = $history_stmt->fetchAll(PDO::FETCH_ASSOC);


// Group by date
$grouped = [];
foreach ($all_purchases as $purchase) {
    $date = date('Y-m-d', strtotime($purchase['purchased_at']));
    $grouped[$date][] = $purchase;
}


// ============================================================
// AVAILABLE MONTHS FOR FILTER DROPDOWN
// ============================================================

$months_stmt = $pdo->prepare("
    SELECT DISTINCT DATE_FORMAT(purchased_at, '%Y-%m') AS month
    FROM purchase_history
    WHERE user_id = ?
    ORDER BY month DESC
");
$months_stmt->execute([$user_id]);
$available_months = $months_stmt->fetchAll(PDO::FETCH_COLUMN);

if (!in_array($selected_month, $available_months)) {
    $available_months[] = $selected_month;
    rsort($available_months);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Expense History — GroceryGenius</title>
    <link rel="stylesheet" href="../assets/css/style.css"/>
    <style>

        /* ── STATS ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 20px;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--purple-300);
            line-height: 1;
        }

        .stat-sub {
            font-size: 0.72rem;
            color: var(--text-soft);
            margin-top: 5px;
        }

        /* ── FILTER BAR ── */
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 18px;
        }

        .filter-bar label {
            font-size: 0.82rem;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .filter-bar select {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg-card);
            color: var(--text-main);
            font-size: 0.85rem;
            outline: none;
            cursor: pointer;
        }

        .filter-bar select:focus {
            border-color: var(--purple-400);
        }

        .filter-btn {
            padding: 8px 16px;
            background: var(--purple-600);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.83rem;
            font-weight: 700;
            cursor: pointer;
        }

        .filter-btn:hover { opacity: 0.9; }

        /* ── DATE GROUP ── */
        .date-group { margin-bottom: 20px; }

        .date-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            margin-bottom: 8px;
            border-bottom: 1px solid var(--border);
        }

        .date-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .date-total {
            font-size: 0.8rem;
            font-weight: 700;
            color: #6ee7b7;
        }

        /* ── PURCHASE ROW ── */
        .purchase-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 13px 16px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            margin-bottom: 8px;
            transition: transform 0.15s;
        }

        .purchase-row:hover {
            transform: translateX(3px);
            border-color: var(--purple-400);
        }

        .purchase-icon {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: rgba(124,58,237,0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .purchase-info { flex: 1; min-width: 0; }

        .purchase-name {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .purchase-meta {
            font-size: 0.74rem;
            color: var(--text-muted);
            margin-top: 3px;
        }

        .purchase-amount {
            text-align: right;
            flex-shrink: 0;
        }

        .purchase-total {
            font-size: 0.95rem;
            font-weight: 800;
            color: #6ee7b7;
        }

        .purchase-time {
            font-size: 0.7rem;
            color: var(--text-soft);
            margin-top: 3px;
        }

        /* ── EMPTY ── */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty-icon  { font-size: 3rem; margin-bottom: 12px; }
        .empty-title { font-size: 1rem; font-weight: 700; color: var(--text-main); margin-bottom: 8px; }
        .empty-sub   { font-size: 0.83rem; }

        .go-btn {
            display: inline-block;
            margin-top: 16px;
            padding: 9px 18px;
            background: var(--purple-600);
            color: #fff;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-weight: 700;
            font-size: 0.85rem;
        }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-bar { flex-wrap: wrap; }
        }

    </style>
</head>

<body>

<button
    class="hamburger"
    onclick="document.querySelector('.sidebar').classList.toggle('open')">
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
                <div class="user-avatar"><?= htmlspecialchars($avatar_letter) ?></div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($user_name) ?></div>
                    <div class="user-role">Member</div>
                </div>
            </div>
        </div>

    </aside>


    <!-- MAIN -->
    <main class="main-content">

        <div class="page-header">
            <div class="page-title">🧾 Expense History</div>
            <div class="page-sub">
                All your grocery purchases — day by day breakdown
            </div>
        </div>


        <!-- STATS -->
        <div class="stats-grid">

            <div class="stat-card">
                <div class="stat-label">Total Spent</div>
                <div class="stat-value">৳<?= number_format((float)$stats['total_spent'], 0) ?></div>
                <div class="stat-sub"><?= date('F Y', strtotime($selected_month . '-01')) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Purchases</div>
                <div class="stat-value"><?= (int)$stats['total_purchases'] ?></div>
                <div class="stat-sub">items bought</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Biggest Purchase</div>
                <div class="stat-value">৳<?= number_format((float)$stats['biggest_purchase'], 0) ?></div>
                <div class="stat-sub">single item</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Top Product</div>
                <div class="stat-value" style="font-size:1rem; padding-top:4px;">
                    <?= $top_product ? htmlspecialchars($top_product['product_name']) : '—' ?>
                </div>
                <div class="stat-sub">
                    <?= $top_product ? '৳'.number_format((float)$top_product['total'], 2) . ' total' : 'no data' ?>
                </div>
            </div>

        </div>


        <!-- FILTER -->
        <form method="GET" class="filter-bar">
            <label for="month">📅 Month:</label>
            <select name="month" id="month">
                <?php foreach ($available_months as $m): ?>
                    <option value="<?= htmlspecialchars($m) ?>" <?= $m === $selected_month ? 'selected' : '' ?>>
                        <?= date('F Y', strtotime($m . '-01')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="filter-btn">View</button>
        </form>


        <!-- PURCHASE LIST -->

        <?php if (empty($grouped)): ?>

            <div class="empty-state">
                <div class="empty-icon">🧾</div>
                <div class="empty-title">No purchases in <?= date('F Y', strtotime($selected_month . '-01')) ?></div>
                <div class="empty-sub">Mark items as purchased in your Shopping List to record expenses here.</div>
                <a href="shopping.php" class="go-btn">🛍️ Go to Shopping List</a>
            </div>

        <?php else: ?>

            <?php foreach ($grouped as $date => $purchases):

                // Date label
                $today     = date('Y-m-d');
                $yesterday = date('Y-m-d', strtotime('-1 day'));

                if ($date === $today)          $date_label = 'Today';
                elseif ($date === $yesterday)  $date_label = 'Yesterday';
                else                           $date_label = date('l, d M Y', strtotime($date));

                // Daily total
                $day_total = array_sum(array_column($purchases, 'total_amount'));

            ?>

                <div class="date-group">

                    <div class="date-header">
                        <span class="date-label"><?= $date_label ?></span>
                        <span class="date-total">৳<?= number_format($day_total, 2) ?></span>
                    </div>

                    <?php foreach ($purchases as $p): ?>

                        <div class="purchase-row">

                            <div class="purchase-icon">🛒</div>

                            <div class="purchase-info">
                                <div class="purchase-name">
                                    <?= htmlspecialchars($p['product_name']) ?>
                                </div>
                                <div class="purchase-meta">
                                    <?= number_format((float)$p['quantity'], 2) ?>
                                    <?= htmlspecialchars($p['unit']) ?>
                                    &times;
                                    ৳<?= number_format((float)$p['price_per_unit'], 2) ?>
                                    per <?= htmlspecialchars($p['unit']) ?>
                                </div>
                            </div>

                            <div class="purchase-amount">
                                <div class="purchase-total">
                                    ৳<?= number_format((float)$p['total_amount'], 2) ?>
                                </div>
                                <div class="purchase-time">
                                    <?= date('h:i A', strtotime($p['purchased_at'])) ?>
                                </div>
                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </main>

</div>

<script>
document.addEventListener('click', function(e) {
    const sidebar   = document.querySelector('.sidebar');
    const hamburger = document.querySelector('.hamburger');
    if (
        window.innerWidth <= 768 &&
        sidebar && hamburger &&
        !sidebar.contains(e.target) &&
        !hamburger.contains(e.target)
    ) {
        sidebar.classList.remove('open');
    }
});
</script>

</body>
</html>