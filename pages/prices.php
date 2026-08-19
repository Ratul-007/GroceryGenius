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
    <title>Price Tracker - GroceryGenius</title>
    <style>
        :root { --bg:#0d0718; --sidebar:#120922; --card:#170d2b; --border:#3b1b68; --text:#f5f0ff; --muted:#b8a8cf; --purple:#a855f7; --purple-light:#c084fc; --green:#4ade80; --red:#f87171; --yellow:#facc15; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font-family:Arial,Helvetica,sans-serif; }
        .sidebar { position:fixed; left:0; top:0; width:240px; height:100vh; padding:24px 16px; background:var(--sidebar); border-right:1px solid var(--border); z-index:1000; }
        .brand { padding:4px 12px 24px; font-size:23px; font-weight:700; } .brand span { color:var(--purple-light); }
        .nav a { display:block; padding:12px 14px; margin:5px 0; color:var(--muted); text-decoration:none; border-radius:9px; transition:.2s; }
        .nav a:hover,.nav a.active { background:#24113e; color:#fff; } .nav a.active { border-left:3px solid var(--purple); }
        .logout { margin-top:24px; border-top:1px solid var(--border); padding-top:16px; } .logout a { color:var(--red); }
        .main { margin-left:240px; min-height:100vh; padding:34px; } .container { max-width:1150px; margin:0 auto; }
        .page-header { margin-bottom:25px; } .page-header h1 { margin:0 0 7px; font-size:30px; } .page-header p { margin:0; color:var(--muted); }
        .notice,.error { padding:13px 16px; border-radius:9px; margin-bottom:18px; } .notice { background:#123421; border:1px solid #246b3e; color:#86efac; } .error { background:#3a151d; border:1px solid #7f1d2d; color:#fca5a5; }
        .card { background:var(--card); border:1px solid var(--border); border-radius:15px; padding:22px; margin-bottom:22px; } .card h2 { margin:0 0 18px; font-size:20px; }
        .form-grid { display:grid; grid-template-columns:1.4fr 1fr auto; gap:12px; align-items:end; } .form-group label { display:block; margin-bottom:7px; color:var(--muted); font-size:14px; }
        input[type=number],input[type=text],select { width:100%; padding:12px 13px; background:#10091d; color:var(--text); border:1px solid #4b2670; border-radius:8px; outline:none; } input:focus,select:focus { border-color:var(--purple); }
        .btn { padding:12px 17px; border:0; border-radius:8px; background:var(--purple); color:#fff; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; } .btn:hover { opacity:.9; } .btn-danger { background:#9f2942; }
        .filters { display:grid; grid-template-columns:1fr 220px auto; gap:12px; align-items:end; }
        .table-wrap { overflow-x:auto; } table { width:100%; border-collapse:collapse; } th,td { padding:14px 12px; text-align:left; border-bottom:1px solid #2c1746; } th { color:var(--muted); font-size:13px; font-weight:600; } td { color:#eee7f8; }
        .price { color:var(--green); font-weight:700; } .meta { color:var(--muted); font-size:13px; } .actions { display:flex; gap:8px; flex-wrap:wrap; }
        .change { display:inline-block; margin-top:4px; font-size:12px; font-weight:700; } .change.down { color:var(--green); } .change.up { color:var(--red); } .change.same { color:var(--muted); }
        .empty { padding:28px; text-align:center; color:var(--muted); border:1px dashed #4b2670; border-radius:10px; }
        .trend-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:18px; }
        .trend-card { background:#120922; border:1px solid #3b1b68; border-radius:12px; padding:18px; }
        .trend-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:16px; }
        .trend-title { margin:0; font-size:18px; } .trend-current { color:var(--green); font-size:18px; font-weight:700; white-space:nowrap; }
        .trend-subtitle { margin:4px 0 0; color:var(--muted); font-size:12px; }
        .trend-chart { height:150px; display:flex; align-items:flex-end; gap:9px; padding:12px 4px 0; border-bottom:1px solid #3b1b68; }
        .trend-point { flex:1; height:100%; display:flex; flex-direction:column; justify-content:flex-end; align-items:center; min-width:0; }
        .trend-value { color:#eee7f8; font-size:11px; margin-bottom:6px; white-space:nowrap; }
        .trend-bar { width:100%; max-width:34px; min-height:8px; border-radius:6px 6px 2px 2px; background:linear-gradient(to top,#7c3aed,#c084fc); transition:height .2s ease; }
        .trend-date { color:var(--muted); font-size:10px; margin-top:7px; white-space:nowrap; }
        .trend-note { margin:14px 0 0; color:var(--muted); font-size:12px; }
        .trend-note strong { color:#eee7f8; }
        .mobile-menu { display:none; position:fixed; top:14px; left:14px; z-index:1100; padding:9px 12px; border:1px solid var(--border); border-radius:8px; background:var(--card); color:#fff; cursor:pointer; }
        @media (max-width:850px) { .sidebar { transform:translateX(-100%); transition:transform .25s ease; } .sidebar.open { transform:translateX(0); } .mobile-menu { display:block; } .main { margin-left:0; padding:75px 18px 25px; } .form-grid,.filters { grid-template-columns:1fr; } .trend-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<button class="mobile-menu" onclick="document.querySelector('.sidebar').classList.toggle('open')">☰</button>
<aside class="sidebar">
    <div class="brand">Grocery<span>Genius</span></div>
    <nav class="nav">
        <a href="dashboard.php">Dashboard</a><a href="pantry.php">Pantry</a><a href="recipes.php">Recipes</a><a href="shopping.php">Shopping List</a><a href="budget.php">Budget</a><a href="prices.php" class="active">Price Tracker</a>
    </nav>
    <div class="logout nav"><a href="logout.php">Logout</a></div>
</aside>
<main class="main"><div class="container">
    <div class="page-header"><h1>Price Tracker</h1><p>Track today's grocery prices and compare them with the most recent previous day.</p></div>
    <?php if ($message): ?><div class="notice"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <section class="card"><h2>Add / Update Today's Price</h2>
        <form method="POST"><div class="form-grid">
            <div class="form-group"><label for="product_id">Product</label><select id="product_id" name="product_id" required><option value="">Select a product</option>
                <?php foreach ($products as $product): ?><option value="<?php echo (int)$product['product_id']; ?>"><?php echo htmlspecialchars($product['name']); ?><?php echo $product['category'] ? ' — '.htmlspecialchars($product['category']) : ''; ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-group"><label for="price_bdt">Today's Price (৳)</label><input type="number" id="price_bdt" name="price_bdt" min="0.01" step="0.01" placeholder="e.g. 110" required></div>
            <button class="btn" type="submit" name="save_price">Save Today's Price</button>
        </div></form>
    </section>

    <section class="card"><h2>Find Prices</h2><form method="GET"><div class="filters">
        <div class="form-group"><label for="search">Search product</label><input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="e.g. Milk, Rice, Chicken"></div>
        <div class="form-group"><label for="category">Category</label><select id="category" name="category"><option value="">All categories</option><?php foreach ($categories as $cat): ?><option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option><?php endforeach; ?></select></div>
        <button class="btn" type="submit">Search</button>
    </div></form></section>

    <section class="card"><h2>Today's Grocery Prices</h2>
    <?php if (empty($prices)): ?><div class="empty">No price records found. Add today's product price above to start tracking daily prices.</div>
    <?php else: ?><div class="table-wrap"><table><thead><tr><th>Product</th><th>Category</th><th>Today's Price</th><th>Previous Price</th><th>Change</th><th>Unit</th><th>Last Updated</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($prices as $item):
            $current = (float)$item['price_bdt'];
            $previous = $item['previous_price'] !== null ? (float)$item['previous_price'] : null;
            $difference = $previous !== null ? $current - $previous : null;
            $percentage = ($previous !== null && $previous > 0) ? ($difference / $previous) * 100 : null;
        ?>
        <tr>
            <td><strong><?php echo htmlspecialchars($item['name']); ?></strong></td>
            <td class="meta"><?php echo htmlspecialchars($item['category'] ?: '—'); ?></td>
            <td class="price">৳<?php echo number_format($current,2); ?></td>
            <td class="meta"><?php echo $previous !== null ? '৳'.number_format($previous,2).' ('.htmlspecialchars(date('d M Y',strtotime($item['previous_date']))).')' : 'No previous price'; ?></td>
            <td><?php if ($difference === null): ?><span class="change same">No comparison yet</span><?php elseif ($difference < 0): ?><span class="change down">▼ ৳<?php echo number_format(abs($difference),2); ?> cheaper<br>(<?php echo number_format(abs($percentage),2); ?>% lower)</span><?php elseif ($difference > 0): ?><span class="change up">▲ ৳<?php echo number_format($difference,2); ?> higher<br>(<?php echo number_format($percentage,2); ?>% higher)</span><?php else: ?><span class="change same">● No change</span><?php endif; ?></td>
            <td class="meta"><?php echo htmlspecialchars($item['unit'] ?: '—'); ?></td>
            <td class="meta"><?php echo htmlspecialchars(date('d M Y, h:i A',strtotime($item['updated_at']))); ?></td>
            <td><div class="actions"><a class="btn btn-danger" href="prices.php?delete=<?php echo (int)$item['price_id']; ?>" onclick="return confirm('Delete this current price? Historical price data will be preserved.');">Delete</a></div></td>
        </tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
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
                            <h3 class="trend-title"><?php echo htmlspecialchars($item['name']); ?></h3>
                            <p class="trend-subtitle">Last <?php echo $history_count; ?> recorded day<?php echo $history_count === 1 ? '' : 's'; ?></p>
                        </div>
                        <div class="trend-current">৳<?php echo number_format((float)$item['price_bdt'], 2); ?></div>
                    </div>

                    <?php if ($history_count > 0): ?>
                        <div class="trend-chart" aria-label="7-day price trend for <?php echo htmlspecialchars($item['name']); ?>">
                            <?php foreach ($history_rows as $history_item):
                                $history_price = (float)$history_item['price_bdt'];
                                $bar_height = $max_history_price > 0 ? max(12, ($history_price / $max_history_price) * 100) : 12;
                            ?>
                                <div class="trend-point">
                                    <div class="trend-value">৳<?php echo number_format($history_price, 0); ?></div>
                                    <div class="trend-bar" style="height:<?php echo number_format($bar_height, 2, '.', ''); ?>%"></div>
                                    <div class="trend-date"><?php echo htmlspecialchars(date('d M', strtotime($history_item['recorded_date']))); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="trend-note">
                            <?php if ($history_count === 1): ?>
                                <strong>First recorded price:</strong> no trend comparison yet.
                            <?php elseif ($trend_difference < 0): ?>
                                <strong>Trend:</strong> price is ৳<?php echo number_format(abs($trend_difference), 2); ?> lower than the first recorded day in this view.
                            <?php elseif ($trend_difference > 0): ?>
                                <strong>Trend:</strong> price is ৳<?php echo number_format($trend_difference, 2); ?> higher than the first recorded day in this view.
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
</div></main>
</body>
</html>
