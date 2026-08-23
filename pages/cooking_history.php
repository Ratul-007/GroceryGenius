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


// ============================================================
// FETCH COOKING HISTORY
// ============================================================

$stmt = $pdo->prepare("
    SELECT
        ch.id,
        ch.cooked_at,
        r.name        AS recipe_name,
        r.description AS recipe_desc,
        r.prep_time,
        r.cook_time,
        r.servings,
        r.recipe_id,
        COUNT(ri.id)  AS ingredient_count

    FROM cooking_history ch

    JOIN recipes r
        ON ch.recipe_id = r.recipe_id

    LEFT JOIN recipe_ingredients ri
        ON r.recipe_id = ri.recipe_id

    WHERE ch.user_id = ?

    GROUP BY
        ch.id,
        ch.cooked_at,
        r.name,
        r.description,
        r.prep_time,
        r.cook_time,
        r.servings,
        r.recipe_id

    ORDER BY ch.cooked_at DESC
");
$stmt->execute([$user_id]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ============================================================
// STATS
// ============================================================

$total_cooked = count($history);

$unique_recipes = count(array_unique(array_column($history, 'recipe_id')));

$today = date('Y-m-d');
$cooked_today = count(array_filter($history, function($h) use ($today) {
    return str_starts_with($h['cooked_at'], $today);
}));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Cooking History — GroceryGenius</title>
    <link rel="stylesheet" href="../assets/css/style.css"/>
    <style>

        /* ── STATS ROW ── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 20px;
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--purple-300);
            line-height: 1;
            margin-bottom: 6px;
        }

        .stat-label {
            font-size: 0.78rem;
            color: var(--text-muted);
        }

        /* ── HISTORY LIST ── */
        .history-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .history-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .history-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .history-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(124,58,237,0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .history-info { flex: 1; min-width: 0; }

        .history-name {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .history-desc {
            font-size: 0.78rem;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .history-meta {
            display: flex;
            gap: 10px;
            margin-top: 6px;
            flex-wrap: wrap;
        }

        .history-meta span {
            font-size: 0.72rem;
            color: var(--text-soft);
        }

        .history-right { text-align: right; flex-shrink: 0; }

        .history-date {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .history-time {
            font-size: 0.72rem;
            color: var(--text-soft);
        }

        .cooked-badge {
            display: inline-block;
            margin-top: 6px;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            background: rgba(16,185,129,0.15);
            color: #6ee7b7;
            border: 1px solid rgba(16,185,129,0.2);
        }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty-icon  { font-size: 3.5rem; margin-bottom: 14px; }
        .empty-title { font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin-bottom: 8px; }
        .empty-text  { font-size: 0.85rem; margin-bottom: 20px; }

        .go-recipes-btn {
            display: inline-block;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            background: var(--purple-600);
            color: white;
            font-weight: 600;
            font-size: 0.85rem;
        }

        /* ── DATE DIVIDER ── */
        .date-divider {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-soft);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 6px 0 4px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 4px;
        }

        @media (max-width: 640px) {
            .stats-row { grid-template-columns: repeat(3, 1fr); }
            .history-desc { display: none; }
            .history-meta { display: none; }
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
            <a href="cooking_history.php" class="nav-item active">
                <span class="nav-icon">📖</span> Cooking History
            </a>
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
                <div class="user-avatar"><?= htmlspecialchars($avatar_letter) ?></div>
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
            <div class="page-title">📖 Cooking History</div>
            <div class="page-sub">All the recipes you have cooked so far</div>
        </div>


        <!-- STATS -->
        <div class="stats-row">

            <div class="stat-card">
                <div class="stat-value"><?= $total_cooked ?></div>
                <div class="stat-label">Total Cooked</div>
            </div>

            <div class="stat-card">
                <div class="stat-value"><?= $unique_recipes ?></div>
                <div class="stat-label">Unique Recipes</div>
            </div>

            <div class="stat-card">
                <div class="stat-value"><?= $cooked_today ?></div>
                <div class="stat-label">Cooked Today</div>
            </div>

        </div>


        <!-- HISTORY -->

        <?php if (empty($history)): ?>

            <div class="empty-state">
                <div class="empty-icon">🍳</div>
                <div class="empty-title">No cooking history yet</div>
                <div class="empty-text">
                    Cook a recipe and it will appear here automatically.
                </div>
                <a href="recipes.php" class="go-recipes-btn">
                    🍳 Go to Recipes
                </a>
            </div>

        <?php else: ?>

            <?php

            $current_date = null;

            foreach ($history as $item):

                $item_date  = date('Y-m-d', strtotime($item['cooked_at']));
                $item_time  = date('h:i A', strtotime($item['cooked_at']));

                // Friendly date label
                if ($item_date === date('Y-m-d')) {
                    $date_label = 'Today';
                } elseif ($item_date === date('Y-m-d', strtotime('-1 day'))) {
                    $date_label = 'Yesterday';
                } else {
                    $date_label = date('d M Y', strtotime($item['cooked_at']));
                }

            ?>

                <?php if ($item_date !== $current_date): ?>
                    <?php $current_date = $item_date; ?>
                    <div class="date-divider"><?= $date_label ?></div>
                <?php endif; ?>

                <div class="history-card">

                    <div class="history-icon">🍽️</div>

                    <div class="history-info">
                        <div class="history-name">
                            <?= htmlspecialchars($item['recipe_name']) ?>
                        </div>
                        <div class="history-desc">
                            <?= htmlspecialchars($item['recipe_desc']) ?>
                        </div>
                        <div class="history-meta">
                            <span>⏱️ Prep: <?= (int)$item['prep_time'] ?>m</span>
                            <span>🔥 Cook: <?= (int)$item['cook_time'] ?>m</span>
                            <span>🍽️ Serves: <?= (int)$item['servings'] ?></span>
                            <span>🥘 <?= (int)$item['ingredient_count'] ?> ingredients</span>
                        </div>
                    </div>

                    <div class="history-right">
                        <div class="history-date"><?= $date_label ?></div>
                        <div class="history-time"><?= $item_time ?></div>
                        <div><span class="cooked-badge">✅ Cooked</span></div>
                    </div>

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