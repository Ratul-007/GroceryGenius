<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id       = (int) $_SESSION['user_id'];
$user_name     = $_SESSION['user_name'] ?? 'User';
$avatar_letter = strtoupper(substr($user_name, 0, 1));

$message = '';
$error = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';

// Save today's price. Historical records are append-only when the price changes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_price'])) {
    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $price_bdt = isset($_POST['price_bdt']) ? trim($_POST['price_bdt']) : '';
    $today = date('Y-m-d');

    if ($product_id <= 0 || $price_bdt === '' || !is_numeric($price_bdt) || (float) $price_bdt <= 0) {
        $error = 'Please select a product and enter a valid price greater than zero.';
    } else {
        $price_bdt = (float) $price_bdt;

        try {
            $pdo->beginTransaction();

            $current = $pdo->prepare(
                'SELECT price_id, price_bdt, updated_at FROM grocery_prices
                 WHERE product_id = :product_id LIMIT 1'
            );
            $current->execute([':product_id' => $product_id]);
            $existing = $current->fetch();

            // Find the latest historical record for this product. This lets us
            // avoid duplicate history rows when the same price is saved again.
            $latest_history = $pdo->prepare(
                'SELECT history_id, price_bdt, recorded_date
                 FROM price_history
                 WHERE product_id = :product_id
                 ORDER BY recorded_date DESC, history_id DESC
                 LIMIT 1'
            );
            $latest_history->execute([':product_id' => $product_id]);
            $latest = $latest_history->fetch();

            if ($existing) {
                $existing_price = (float) $existing['price_bdt'];
                $existing_date = date('Y-m-d', strtotime($existing['updated_at']));

                // Preserve the old current price if it is not already represented
                // by the latest history record. This also handles legacy data.
                if (
                    !$latest ||
                    (float) $latest['price_bdt'] !== $existing_price ||
                    $latest['recorded_date'] !== $existing_date
                ) {
                    $legacy_insert = $pdo->prepare(
                        'INSERT INTO price_history (product_id, price_bdt, recorded_date)
                         VALUES (:product_id, :price_bdt, :recorded_date)'
                    );
                    $legacy_insert->execute([
                        ':product_id' => $product_id,
                        ':price_bdt' => $existing_price,
                        ':recorded_date' => $existing_date
                    ]);
                }

                $stmt = $pdo->prepare(
                    'UPDATE grocery_prices
                     SET price_bdt = :price_bdt, updated_at = CURRENT_TIMESTAMP
                     WHERE price_id = :price_id'
                );
                $stmt->execute([
                    ':price_bdt' => $price_bdt,
                    ':price_id' => (int) $existing['price_id']
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO grocery_prices (product_id, price_bdt)
                     VALUES (:product_id, :price_bdt)'
                );
                $stmt->execute([
                    ':product_id' => $product_id,
                    ':price_bdt' => $price_bdt
                ]);
            }

            // Record a new history entry only when the price actually changes.
            // Multiple price changes on the same day are intentionally preserved.
            $latest_after_current = $pdo->prepare(
                'SELECT history_id, price_bdt
                 FROM price_history
                 WHERE product_id = :product_id
                 ORDER BY recorded_date DESC, history_id DESC
                 LIMIT 1'
            );
            $latest_after_current->execute([':product_id' => $product_id]);
            $latest_row = $latest_after_current->fetch();

            if (!$latest_row || (float) $latest_row['price_bdt'] !== $price_bdt) {
                $history_insert = $pdo->prepare(
                    'INSERT INTO price_history (product_id, price_bdt, recorded_date)
                     VALUES (:product_id, :price_bdt, :recorded_date)'
                );
                $history_insert->execute([
                    ':product_id' => $product_id,
                    ':price_bdt' => $price_bdt,
                    ':recorded_date' => $today
                ]);
            }

            $pdo->commit();
            $message = "Today's price saved successfully.";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Unable to save the price. Please make sure the price history table has been created.';
        }
    }
}

// Delete only the current price. Historical price records are preserved permanently.
if (isset($_GET['delete'])) {
    $price_id = (int) $_GET['delete'];

    try {
        $stmt = $pdo->prepare('SELECT price_id FROM grocery_prices WHERE price_id = :price_id LIMIT 1');
        $stmt->execute([':price_id' => $price_id]);
        $price_row = $stmt->fetch();

        if ($price_row) {
            $delete_current = $pdo->prepare('DELETE FROM grocery_prices WHERE price_id = :price_id');
            $delete_current->execute([':price_id' => $price_id]);
        }

        header('Location: prices.php?status=deleted');
        exit();
    } catch (PDOException $e) {
        $error = 'Unable to delete the price record.';
    }
}

if (isset($_GET['status']) && $_GET['status'] === 'deleted') {
    $message = 'Current price removed successfully. Price history was preserved.';
}

$products = $pdo->query(
    'SELECT product_id, name, category, unit FROM products ORDER BY name ASC'
)->fetchAll();

$category_stmt = $pdo->query(
    'SELECT DISTINCT category FROM products
     WHERE category IS NOT NULL AND category <> ""
     ORDER BY category ASC'
);
$categories = $category_stmt->fetchAll(PDO::FETCH_COLUMN);

// Current prices plus the most recent previous daily price.
$sql = 'SELECT gp.price_id, gp.product_id, gp.price_bdt, gp.updated_at,
               p.name, p.category, p.unit,
               prev.price_bdt AS previous_price,
               prev.recorded_date AS previous_date
        FROM grocery_prices gp
        INNER JOIN products p ON gp.product_id = p.product_id
        LEFT JOIN price_history prev ON prev.history_id = (
            SELECT ph.history_id
            FROM price_history ph
            WHERE ph.product_id = gp.product_id
              AND ph.recorded_date < CURDATE()
            ORDER BY ph.recorded_date DESC, ph.history_id DESC
            LIMIT 1
        )
        WHERE 1=1';
$params = [];

if ($search !== '') {
    $sql .= ' AND p.name LIKE :search';
    $params[':search'] = '%' . $search . '%';
}

if ($category !== '') {
    $sql .= ' AND p.category = :category';
    $params[':category'] = $category;
}

$sql .= ' ORDER BY p.name ASC';
$price_stmt = $pdo->prepare($sql);
$price_stmt->execute($params);
$prices = $price_stmt->fetchAll();

// Seven-day visual history: use the latest price entry for each day so
// multiple same-day price changes do not create duplicate chart bars.
$history_stmt = $pdo->prepare(
    'SELECT ph.price_bdt, ph.recorded_date
     FROM price_history ph
     WHERE ph.product_id = :product_id
       AND ph.history_id = (
           SELECT ph2.history_id
           FROM price_history ph2
           WHERE ph2.product_id = ph.product_id
             AND ph2.recorded_date = ph.recorded_date
           ORDER BY ph2.history_id DESC
           LIMIT 1
       )
     ORDER BY ph.recorded_date DESC
     LIMIT 7'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Price Tracker — GroceryGenius</title>
    <link rel="stylesheet" href="../assets/css/style.css"/>
    <style>
        .form-grid { display: grid; grid-template-columns: 1.4fr 1fr auto; gap: 12px; align-items: end; }
        .filters   { display: grid; grid-template-columns: 1fr 220px auto; gap: 12px; align-items: end; }

        .table-wrap { overflow-x: auto; }
        .price { color: var(--success); font-weight: 700; }
        .meta  { color: var(--text-muted); font-size: 0.82rem; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }

        .change { display: inline-block; margin-top: 4px; font-size: 0.75rem; font-weight: 700; }
        .change.down { color: var(--success); }
        .change.up   { color: var(--danger); }
        .change.same { color: var(--text-muted); }

        .empty {
            padding: 28px; text-align: center; color: var(--text-muted);
            border: 1px dashed var(--border); border-radius: var(--radius-sm);
        }

        .trend-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 18px; }
        .trend-card { background: var(--bg-input); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px; }
        .trend-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 16px; }
        .trend-title { margin: 0; font-size: 1rem; color: var(--text-main); }
        .trend-current { color: var(--success); font-size: 1rem; font-weight: 700; white-space: nowrap; }
        .trend-subtitle { margin: 4px 0 0; color: var(--text-muted); font-size: 0.75rem; }
        .trend-chart { height: 150px; display: flex; align-items: flex-end; gap: 9px; padding: 12px 4px 0; border-bottom: 1px solid var(--border); }
        .trend-point { flex: 1; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; min-width: 0; }
        .trend-value { color: var(--text-main); font-size: 0.68rem; margin-bottom: 6px; white-space: nowrap; }
        .trend-bar { width: 100%; max-width: 34px; min-height: 8px; border-radius: 6px 6px 2px 2px; background: linear-gradient(to top, var(--purple-500), var(--purple-300)); transition: height 0.2s ease; }
        .trend-date { color: var(--text-muted); font-size: 0.62rem; margin-top: 7px; white-space: nowrap; }
        .trend-note { margin: 14px 0 0; color: var(--text-muted); font-size: 0.75rem; }
        .trend-note strong { color: var(--text-main); }

        .card + .card { margin-top: 20px; }
        .card h2 { margin: 0 0 18px; font-size: 1.1rem; color: var(--text-main); }

        @media (max-width: 768px) {
            .form-grid, .filters { grid-template-columns: 1fr; }
            .trend-grid { grid-template-columns: 1fr; }
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
            <a href="dashboard.php" class="nav-item"><span class="nav-icon">🏠</span> Dashboard</a>
            <a href="pantry.php" class="nav-item"><span class="nav-icon">🥦</span> Pantry</a>
            <a href="recipes.php" class="nav-item"><span class="nav-icon">🍳</span> Recipes</a>
            <a href="shopping.php" class="nav-item"><span class="nav-icon">🛍️</span> Shopping List</a>
            <a href="cooking_history.php" class="nav-item"><span class="nav-icon">📖</span> Cooking History</a>

            <div class="nav-label">Finance</div>
            <a href="budget.php" class="nav-item"><span class="nav-icon">💰</span> Budget</a>
            <a href="expense_history.php" class="nav-item"><span class="nav-icon">🧾</span> Expense History</a>
            <a href="monthly_report.php" class="nav-item"><span class="nav-icon">📊</span> Monthly Report</a>
            <a href="prices.php" class="nav-item active"><span class="nav-icon">📈</span> Price Tracker</a>

            <div class="nav-label">Account</div>
            <a href="profile.php" class="nav-item"><span class="nav-icon">👤</span> Profile</a>
            <a href="logout.php" class="nav-item"><span class="nav-icon">🚪</span> Logout</a>
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

    <main class="main-content">

        <div class="page-header">
            <div class="page-title">📈 Price Tracker</div>
            <div class="page-sub">Track today's grocery prices and compare them with the most recent previous day.</div>
        </div>

        <?php if ($message): ?><div class="alert alert-success">✅ <?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

        <section class="card">
            <h2>Add / Update Today's Price</h2>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="product_id">Product</label>
                        <select id="product_id" name="product_id" class="form-control" required>
                            <option value="">Select a product</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?= (int)$product['product_id'] ?>">
                                    <?= htmlspecialchars($product['name']) ?><?= $product['category'] ? ' — ' . htmlspecialchars($product['category']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="price_bdt">Today's Price (৳)</label>
                        <input type="number" id="price_bdt" name="price_bdt" class="form-control"
                               min="0.01" step="0.01" placeholder="e.g. 110" required>
                    </div>
                    <button class="btn btn-primary" type="submit" name="save_price">Save Today's Price</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Find Prices</h2>
            <form method="GET">
                <div class="filters">
                    <div class="form-group">
                        <label for="search">Search product</label>
                        <input type="text" id="search" name="search" class="form-control"
                               value="<?= htmlspecialchars($search) ?>" placeholder="e.g. Milk, Rice, Chicken">
                    </div>
                    <div class="form-group">
                        <label for="category">Category</label>
                        <select id="category" name="category" class="form-control">
                            <option value="">All categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-outline" type="submit">Search</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Today's Grocery Prices</h2>
            <?php if (empty($prices)): ?>
                <div class="empty">No price records found. Add today's product price above to start tracking daily prices.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Product</th><th>Category</th><th>Today's Price</th>
                            <th>Previous Price</th><th>Change</th><th>Unit</th>
                            <th>Last Updated</th><th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($prices as $item):
                            $current = (float)$item['price_bdt'];
                            $previous = $item['previous_price'] !== null ? (float)$item['previous_price'] : null;
                            $difference = $previous !== null ? $current - $previous : null;
                            $percentage = ($previous !== null && $previous > 0) ? ($difference / $previous) * 100 : null;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                            <td class="meta"><?= htmlspecialchars($item['category'] ?: '—') ?></td>
                            <td class="price">৳<?= number_format($current, 2) ?></td>
                            <td class="meta"><?= $previous !== null ? '৳' . number_format($previous, 2) . ' (' . htmlspecialchars(date('d M Y', strtotime($item['previous_date']))) . ')' : 'No previous price' ?></td>
                            <td>
                                <?php if ($difference === null): ?>
                                    <span class="change same">No comparison yet</span>
                                <?php elseif ($difference < 0): ?>
                                    <span class="change down">▼ ৳<?= number_format(abs($difference), 2) ?> cheaper<br>(<?= number_format(abs($percentage), 2) ?>% lower)</span>
                                <?php elseif ($difference > 0): ?>
                                    <span class="change up">▲ ৳<?= number_format($difference, 2) ?> higher<br>(<?= number_format($percentage, 2) ?>% higher)</span>
                                <?php else: ?>
                                    <span class="change same">● No change</span>
                                <?php endif; ?>
                            </td>
                            <td class="meta"><?= htmlspecialchars($item['unit'] ?: '—') ?></td>
                            <td class="meta"><?= htmlspecialchars(date('d M Y, h:i A', strtotime($item['updated_at']))) ?></td>
                            <td>
                                <div class="actions">
                                    <a class="btn btn-danger btn-sm" href="prices.php?delete=<?= (int)$item['price_id'] ?>"
                                       onclick="return confirm('Delete this current price? Historical price data will be preserved.');">Delete</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>7-Day Price Trend</h2>
            <?php if (empty($prices)): ?>
                <div class="empty">Add a current price to see its historical trend here.</div>
            <?php else: ?>
                <div class="trend-grid">
                <?php foreach ($prices as $item):
                    $history_stmt->execute([':product_id' => (int)$item['product_id']]);
                    $history_rows = $history_stmt->fetchAll();
                    $history_rows = array_reverse($history_rows);
                    $max_history_price = 0;
                    foreach ($history_rows as $history_item) {
                        $max_history_price = max($max_history_price, (float)$history_item['price_bdt']);
                    }
                    $history_count = count($history_rows);
                    $first_history_price = $history_count > 0 ? (float)$history_rows[0]['price_bdt'] : null;
                    $last_history_price = $history_count > 0 ? (float)$history_rows[$history_count - 1]['price_bdt'] : null;
                    $trend_difference = ($first_history_price !== null && $last_history_price !== null) ? $last_history_price - $first_history_price : 0;
                ?>
                    <div class="trend-card">
                        <div class="trend-header">
                            <div>
                                <h3 class="trend-title"><?= htmlspecialchars($item['name']) ?></h3>
                                <p class="trend-subtitle">Last <?= $history_count ?> recorded day<?= $history_count === 1 ? '' : 's' ?></p>
                            </div>
                            <div class="trend-current">৳<?= number_format((float)$item['price_bdt'], 2) ?></div>
                        </div>

                        <?php if ($history_count > 0): ?>
                            <div class="trend-chart" aria-label="7-day price trend for <?= htmlspecialchars($item['name']) ?>">
                                <?php foreach ($history_rows as $history_item):
                                    $history_price = (float)$history_item['price_bdt'];
                                    $bar_height = $max_history_price > 0 ? max(12, ($history_price / $max_history_price) * 100) : 12;
                                ?>
                                    <div class="trend-point">
                                        <div class="trend-value">৳<?= number_format($history_price, 0) ?></div>
                                        <div class="trend-bar" style="height:<?= number_format($bar_height, 2, '.', '') ?>%"></div>
                                        <div class="trend-date"><?= htmlspecialchars(date('d M', strtotime($history_item['recorded_date']))) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="trend-note">
                                <?php if ($history_count === 1): ?>
                                    <strong>First recorded price:</strong> no trend comparison yet.
                                <?php elseif ($trend_difference < 0): ?>
                                    <strong>Trend:</strong> price is ৳<?= number_format(abs($trend_difference), 2) ?> lower than the first recorded day in this view.
                                <?php elseif ($trend_difference > 0): ?>
                                    <strong>Trend:</strong> price is ৳<?= number_format($trend_difference, 2) ?> higher than the first recorded day in this view.
                                <?php else: ?>
                                    <strong>Trend:</strong> price is unchanged from the first recorded day in this view.
                                <?php endif; ?>
                            </p>
                        <?php else: ?>
                            <div class="empty">No historical prices available yet.</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
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