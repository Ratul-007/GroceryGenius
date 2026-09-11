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

// Month selector
$selected_month = $_GET['month'] ?? date('Y-m');
$month_start    = $selected_month . '-01';
$month_end      = date('Y-m-t', strtotime($month_start));
$month_label    = date('F Y', strtotime($month_start));


// ============================================================
// AVAILABLE MONTHS
// ============================================================

$months_stmt = $pdo->prepare("
    SELECT DISTINCT DATE_FORMAT(purchased_at, '%Y-%m') AS month
    FROM purchase_history WHERE user_id = ?
    UNION
    SELECT DISTINCT DATE_FORMAT(cooked_at, '%Y-%m') AS month
    FROM cooking_history WHERE user_id = ?
    ORDER BY month DESC
");
$months_stmt->execute([$user_id, $user_id]);
$available_months = $months_stmt->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($selected_month, $available_months)) {
    $available_months[] = $selected_month;
    rsort($available_months);
}


// ============================================================
// BUDGET
// ============================================================

$budget_stmt = $pdo->prepare("SELECT limit_amount, spent_amount FROM budget WHERE user_id = ? AND month = ?");
$budget_stmt->execute([$user_id, $selected_month]);
$budget     = $budget_stmt->fetch(PDO::FETCH_ASSOC);
$budget_limit   = $budget ? (float)$budget['limit_amount'] : 0;
$budget_spent   = $budget ? (float)$budget['spent_amount'] : 0;
$budget_pct     = $budget_limit > 0 ? min(round(($budget_spent / $budget_limit) * 100), 100) : 0;
$budget_remain  = $budget_limit - $budget_spent;
$budget_color   = $budget_pct < 60 ? '#10b981' : ($budget_pct < 85 ? '#f59e0b' : '#ef4444');


// ============================================================
// PURCHASE SUMMARY
// ============================================================

$summary_stmt = $pdo->prepare("
    SELECT
        COUNT(*)                       AS total_purchases,
        COALESCE(SUM(total_amount), 0) AS total_spent,
        COALESCE(MAX(total_amount), 0) AS biggest_purchase,
        COALESCE(AVG(total_amount), 0) AS avg_purchase
    FROM purchase_history
    WHERE user_id = ? AND DATE_FORMAT(purchased_at, '%Y-%m') = ?
");
$summary_stmt->execute([$user_id, $selected_month]);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);


// ============================================================
// COOKING SUMMARY
// ============================================================

$cook_stmt = $pdo->prepare("
    SELECT COUNT(*) AS total_cooked
    FROM cooking_history
    WHERE user_id = ? AND DATE_FORMAT(cooked_at, '%Y-%m') = ?
");
$cook_stmt->execute([$user_id, $selected_month]);
$cook_summary = $cook_stmt->fetch(PDO::FETCH_ASSOC);


// ============================================================
// TOP 5 PRODUCTS
// ============================================================

$top_products_stmt = $pdo->prepare("
    SELECT product_name, SUM(total_amount) AS total, SUM(quantity) AS qty, unit
    FROM purchase_history
    WHERE user_id = ? AND DATE_FORMAT(purchased_at, '%Y-%m') = ?
    GROUP BY product_name, unit
    ORDER BY total DESC
    LIMIT 5
");
$top_products_stmt->execute([$user_id, $selected_month]);
$top_products = $top_products_stmt->fetchAll(PDO::FETCH_ASSOC);
$max_product_total = !empty($top_products) ? (float)$top_products[0]['total'] : 1;


// ============================================================
// CATEGORY BREAKDOWN
// ============================================================

$category_stmt = $pdo->prepare("
    SELECT p.category, COALESCE(SUM(ph.total_amount), 0) AS total
    FROM purchase_history ph
    LEFT JOIN products p ON ph.product_id = p.product_id
    WHERE ph.user_id = ? AND DATE_FORMAT(ph.purchased_at, '%Y-%m') = ?
    GROUP BY p.category
    ORDER BY total DESC
");
$category_stmt->execute([$user_id, $selected_month]);
$categories      = $category_stmt->fetchAll(PDO::FETCH_ASSOC);
$total_cat_spent = array_sum(array_column($categories, 'total'));


// ============================================================
// DAY-WISE SPENDING (for bar chart)
// ============================================================

$daily_stmt = $pdo->prepare("
    SELECT DATE(purchased_at) AS day, SUM(total_amount) AS total
    FROM purchase_history
    WHERE user_id = ? AND DATE_FORMAT(purchased_at, '%Y-%m') = ?
    GROUP BY DATE(purchased_at)
    ORDER BY day ASC
");
$daily_stmt->execute([$user_id, $selected_month]);
$daily_rows = $daily_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build full month map
$days_in_month = (int)date('t', strtotime($month_start));
$daily_map = [];
foreach ($daily_rows as $row) {
    $daily_map[$row['day']] = (float)$row['total'];
}
$max_daily = !empty($daily_map) ? max($daily_map) : 1;


// ============================================================
// TOP COOKED RECIPES
// ============================================================

$cooked_recipes_stmt = $pdo->prepare("
    SELECT r.name, COUNT(*) AS times
    FROM cooking_history ch
    JOIN recipes r ON ch.recipe_id = r.recipe_id
    WHERE ch.user_id = ? AND DATE_FORMAT(ch.cooked_at, '%Y-%m') = ?
    GROUP BY r.recipe_id, r.name
    ORDER BY times DESC
    LIMIT 5
");
$cooked_recipes_stmt->execute([$user_id, $selected_month]);
$cooked_recipes = $cooked_recipes_stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Monthly Report — GroceryGenius</title>
    <link rel="stylesheet" href="../assets/css/style.css"/>
    <style>

        /* ── FILTER BAR ── */
        .filter-bar {
            display: flex; align-items: center; gap: 12px;
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 14px 18px; margin-bottom: 24px;
        }
        .filter-bar label { font-size: 0.82rem; color: var(--text-muted); white-space: nowrap; }
        .filter-bar select {
            padding: 8px 12px; border: 1px solid var(--border);
            border-radius: var(--radius-sm); background: var(--bg-card);
            color: var(--text-main); font-size: 0.85rem; outline: none; cursor: pointer;
        }
        .filter-bar select:focus { border-color: var(--purple-400); }
        .filter-btn {
            padding: 8px 16px; background: var(--purple-600); color: #fff;
            border: none; border-radius: var(--radius-sm);
            font-size: 0.83rem; font-weight: 700; cursor: pointer;
        }
        .filter-btn:hover { opacity: 0.9; }

        /* ── STAT GRID ── */
        .report-stats {
            display: grid; grid-template-columns: repeat(4, 1fr);
            gap: 14px; margin-bottom: 24px;
        }
        .report-stat {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 18px 20px;
        }
        .rs-label { font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
        .rs-value { font-size: 1.6rem; font-weight: 800; color: var(--purple-300); line-height: 1; }
        .rs-sub   { font-size: 0.72rem; color: var(--text-soft); margin-top: 5px; }

        /* ── TWO COL ── */
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .three-col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 20px; }

        .report-card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 20px;
        }
        .report-card h3 {
            font-size: 0.85rem; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.06em;
            margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid var(--border);
        }

        /* ── BUDGET BAR ── */
        .budget-row { display: flex; justify-content: space-between; font-size: 0.82rem; color: var(--text-muted); margin-bottom: 8px; }
        .budget-pct { font-weight: 700; }
        .bar-wrap { background: rgba(61,18,120,0.3); border-radius: 8px; height: 10px; margin-bottom: 10px; }
        .bar-fill { height: 10px; border-radius: 8px; transition: width 0.4s; }
        .budget-meta { font-size: 0.78rem; color: var(--text-soft); }

        /* ── DAILY CHART ── */
        .daily-chart {
            display: flex; align-items: flex-end; gap: 3px;
            height: 120px; padding-top: 10px;
        }
        .day-col {
            flex: 1; display: flex; flex-direction: column;
            align-items: center; justify-content: flex-end; height: 100%;
        }
        .day-bar {
            width: 100%; max-width: 28px;
            border-radius: 4px 4px 0 0; min-height: 3px;
            background: linear-gradient(to top, var(--purple-600), var(--purple-400));
            transition: opacity 0.2s; cursor: pointer; position: relative;
        }
        .day-bar:hover { opacity: 0.8; }
        .day-bar .tooltip {
            display: none; position: absolute; bottom: 100%; left: 50%;
            transform: translateX(-50%); background: #1a0d2e;
            border: 1px solid var(--border); border-radius: 6px;
            padding: 4px 8px; font-size: 0.68rem; color: var(--text-main);
            white-space: nowrap; z-index: 10; margin-bottom: 4px;
        }
        .day-bar:hover .tooltip { display: block; }
        .day-num { font-size: 0.6rem; color: var(--text-soft); margin-top: 4px; }

        /* ── TOP PRODUCTS ── */
        .product-row { margin-bottom: 12px; }
        .product-top { display: flex; justify-content: space-between; font-size: 0.82rem; margin-bottom: 4px; }
        .product-name { color: var(--text-main); font-weight: 600; }
        .product-amount { color: #6ee7b7; font-weight: 700; }
        .product-bar-wrap { background: rgba(61,18,120,0.3); border-radius: 4px; height: 5px; }
        .product-bar { height: 5px; border-radius: 4px; background: linear-gradient(90deg, var(--purple-600), var(--purple-400)); }

        /* ── CATEGORY PIE (CSS) ── */
        .cat-row {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 0; border-bottom: 1px solid rgba(61,18,120,0.2);
            font-size: 0.82rem;
        }
        .cat-row:last-child { border-bottom: none; }
        .cat-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .cat-name { flex: 1; color: var(--text-main); }
        .cat-pct  { color: var(--text-muted); font-size: 0.75rem; }
        .cat-amt  { color: #6ee7b7; font-weight: 700; }

        /* ── COOKED RECIPES ── */
        .cooked-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 9px 0; border-bottom: 1px solid rgba(61,18,120,0.2);
            font-size: 0.85rem;
        }
        .cooked-row:last-child { border-bottom: none; }
        .cooked-name { color: var(--text-main); font-weight: 600; }
        .cooked-times {
            padding: 2px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 700;
            background: rgba(124,58,237,0.2); color: var(--purple-300);
        }

        /* ── EMPTY ── */
        .empty-box {
            text-align: center; padding: 30px; color: var(--text-soft); font-size: 0.85rem;
        }

        @media (max-width: 768px) {
            .report-stats { grid-template-columns: repeat(2, 1fr); }
            .two-col, .three-col { grid-template-columns: 1fr; }
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
            <a href="dashboard.php" class="nav-item"><span class="nav-icon">🏠</span> Dashboard</a>
            <a href="pantry.php" class="nav-item"><span class="nav-icon">🥦</span> Pantry</a>
            <a href="recipes.php" class="nav-item"><span class="nav-icon">🍳</span> Recipes</a>
            <a href="shopping.php" class="nav-item"><span class="nav-icon">🛍️</span> Shopping List</a>
            <a href="cooking_history.php" class="nav-item"><span class="nav-icon">📖</span> Cooking History</a>

            <div class="nav-label">Finance</div>
            <a href="budget.php" class="nav-item"><span class="nav-icon">💰</span> Budget</a>
            <a href="expense_history.php" class="nav-item"><span class="nav-icon">🧾</span> Expense History</a>
            <a href="monthly_report.php" class="nav-item active"><span class="nav-icon">📊</span> Monthly Report</a>
            <a href="monthly_report.php" class="nav-item">
<a href="prices.php" class="nav-item"><span class="nav-icon">📈</span> Price Tracker</a>

            <div class="nav-label">Account</div>
            <a href="profile.php" class="nav-item"><span class="nav-icon">👤</span> Profile</a>
            <a href="logout.php" class="nav-item"><span class="nav-icon">🚪</span> Logout</a>
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


    <!-- MAIN -->
    <main class="main-content">

        <div class="page-header">
            <div class="page-title">📊 Monthly Report</div>
            <div class="page-sub">Full breakdown of your grocery spending and cooking for <?= $month_label ?></div>
        </div>

        <!-- MONTH FILTER -->
        <form method="GET" class="filter-bar">
            <label>📅 Month:</label>
            <select name="month">
                <?php foreach ($available_months as $m): ?>
                    <option value="<?= htmlspecialchars($m) ?>" <?= $m === $selected_month ? 'selected' : '' ?>>
                        <?= date('F Y', strtotime($m . '-01')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="filter-btn">View Report</button>
        </form>


        <!-- SUMMARY STATS -->
        <div class="report-stats">

            <div class="report-stat">
                <div class="rs-label">Total Spent</div>
                <div class="rs-value">৳<?= number_format((float)$summary['total_spent'], 0) ?></div>
                <div class="rs-sub"><?= (int)$summary['total_purchases'] ?> purchases</div>
            </div>

            <div class="report-stat">
                <div class="rs-label">Budget Used</div>
                <div class="rs-value"><?= $budget_pct ?>%</div>
                <div class="rs-sub">
                    <?php if ($budget_limit > 0): ?>
                        ৳<?= number_format($budget_remain, 0) ?> remaining
                    <?php else: ?>
                        No budget set
                    <?php endif; ?>
                </div>
            </div>

            <div class="report-stat">
                <div class="rs-label">Recipes Cooked</div>
                <div class="rs-value"><?= (int)$cook_summary['total_cooked'] ?></div>
                <div class="rs-sub">this month</div>
            </div>

            <div class="report-stat">
                <div class="rs-label">Avg per Purchase</div>
                <div class="rs-value">৳<?= number_format((float)$summary['avg_purchase'], 0) ?></div>
                <div class="rs-sub">biggest: ৳<?= number_format((float)$summary['biggest_purchase'], 0) ?></div>
            </div>

        </div>


        <!-- ROW 1: Budget + Daily Chart -->
        <div class="two-col">

            <!-- BUDGET -->
            <div class="report-card">
                <h3>💰 Budget — <?= $month_label ?></h3>
                <?php if (!$budget): ?>
                    <div class="empty-box">No budget set for this month.<br><a href="budget.php">Set budget →</a></div>
                <?php else: ?>
                    <div class="budget-row">
                        <span>৳<?= number_format($budget_spent, 2) ?> spent</span>
                        <span class="budget-pct" style="color:<?= $budget_color ?>"><?= $budget_pct ?>%</span>
                    </div>
                    <div class="bar-wrap">
                        <div class="bar-fill" style="width:<?= $budget_pct ?>%; background:<?= $budget_color ?>;"></div>
                    </div>
                    <div class="budget-meta">
                        Limit: ৳<?= number_format($budget_limit, 2) ?>
                        &nbsp;·&nbsp;
                        Remaining: ৳<?= number_format($budget_remain, 2) ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- DAILY SPENDING CHART -->
            <div class="report-card">
                <h3>📅 Daily Spending</h3>
                <?php if (empty($daily_map)): ?>
                    <div class="empty-box">No spending data for this month.</div>
                <?php else: ?>
                    <div class="daily-chart">
                        <?php for ($d = 1; $d <= $days_in_month; $d++): ?>
                            <?php
                            $date_key = date('Y-m-', strtotime($month_start)) . str_pad($d, 2, '0', STR_PAD_LEFT);
                            $amount   = $daily_map[$date_key] ?? 0;
                            $height   = $amount > 0 ? max(4, round(($amount / $max_daily) * 100)) : 0;
                            ?>
                            <div class="day-col">
                                <?php if ($amount > 0): ?>
                                    <div class="day-bar" style="height:<?= $height ?>%;">
                                        <div class="tooltip"><?= $d ?> — ৳<?= number_format($amount, 0) ?></div>
                                    </div>
                                <?php else: ?>
                                    <div style="height:3px; width:100%; background:rgba(61,18,120,0.2); border-radius:2px;"></div>
                                <?php endif; ?>
                                <?php if ($days_in_month <= 15 || $d % 5 === 0 || $d === 1): ?>
                                    <div class="day-num"><?= $d ?></div>
                                <?php else: ?>
                                    <div class="day-num"></div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>


        <!-- ROW 2: Top Products + Category + Cooked Recipes -->
        <div class="three-col">

            <!-- TOP PRODUCTS -->
            <div class="report-card">
                <h3>🛒 Top Products</h3>
                <?php if (empty($top_products)): ?>
                    <div class="empty-box">No purchases this month.</div>
                <?php else: ?>
                    <?php foreach ($top_products as $p): ?>
                        <div class="product-row">
                            <div class="product-top">
                                <span class="product-name"><?= htmlspecialchars($p['product_name']) ?></span>
                                <span class="product-amount">৳<?= number_format((float)$p['total'], 0) ?></span>
                            </div>
                            <div class="product-bar-wrap">
                                <div class="product-bar" style="width:<?= round(($p['total'] / $max_product_total) * 100) ?>%"></div>
                            </div>
                            <div style="font-size:0.7rem; color:var(--text-soft); margin-top:2px;">
                                <?= number_format((float)$p['qty'], 2) ?> <?= htmlspecialchars($p['unit']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- CATEGORY BREAKDOWN -->
            <div class="report-card">
                <h3>🏷️ By Category</h3>
                <?php
                $cat_colors = ['#a855f7','#10b981','#f59e0b','#ef4444','#3b82f6','#ec4899','#14b8a6'];
                if (empty($categories)): ?>
                    <div class="empty-box">No category data.</div>
                <?php else: ?>
                    <?php foreach ($categories as $i => $cat): ?>
                        <?php
                        $pct = $total_cat_spent > 0 ? round(($cat['total'] / $total_cat_spent) * 100) : 0;
                        $color = $cat_colors[$i % count($cat_colors)];
                        ?>
                        <div class="cat-row">
                            <div class="cat-dot" style="background:<?= $color ?>"></div>
                            <span class="cat-name"><?= htmlspecialchars($cat['category'] ?? 'Other') ?></span>
                            <span class="cat-pct"><?= $pct ?>%</span>
                            <span class="cat-amt">৳<?= number_format((float)$cat['total'], 0) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- COOKED RECIPES -->
            <div class="report-card">
                <h3>🍳 Recipes Cooked</h3>
                <?php if (empty($cooked_recipes)): ?>
                    <div class="empty-box">No recipes cooked this month.</div>
                <?php else: ?>
                    <?php foreach ($cooked_recipes as $cr): ?>
                        <div class="cooked-row">
                            <span class="cooked-name"><?= htmlspecialchars($cr['name']) ?></span>
                            <span class="cooked-times"><?= $cr['times'] ?>x</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>

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