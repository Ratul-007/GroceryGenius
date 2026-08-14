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

// Add or update the current price for a product.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_price'])) {
    $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $price_bdt = isset($_POST['price_bdt']) ? trim($_POST['price_bdt']) : '';

    if ($product_id <= 0 || $price_bdt === '' || !is_numeric($price_bdt) || (float) $price_bdt <= 0) {
        $error = 'Please select a product and enter a valid price greater than zero.';
    } else {
        $price_bdt = (float) $price_bdt;

        $check = $pdo->prepare(
            'SELECT price_id FROM grocery_prices WHERE product_id = :product_id LIMIT 1'
        );
        $check->execute([':product_id' => $product_id]);
        $existing = $check->fetch();

        if ($existing) {
            $stmt = $pdo->prepare(
                'UPDATE grocery_prices
                 SET price_bdt = :price_bdt, updated_at = CURRENT_TIMESTAMP
                 WHERE price_id = :price_id'
            );
            $stmt->execute([
                ':price_bdt' => $price_bdt,
                ':price_id' => (int) $existing['price_id']
            ]);
            $message = 'Product price updated successfully.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO grocery_prices (product_id, price_bdt)
                 VALUES (:product_id, :price_bdt)'
            );
            $stmt->execute([
                ':product_id' => $product_id,
                ':price_bdt' => $price_bdt
            ]);
            $message = 'Product price added successfully.';
        }
    }
}

// Delete a price record.
if (isset($_GET['delete'])) {
    $price_id = (int) $_GET['delete'];
    $stmt = $pdo->prepare('DELETE FROM grocery_prices WHERE price_id = :price_id');
    $stmt->execute([':price_id' => $price_id]);
    header('Location: prices.php?status=deleted');
    exit();
}

if (isset($_GET['status']) && $_GET['status'] === 'deleted') {
    $message = 'Price record removed successfully.';
}

// Products for the add/update form.
$products = $pdo->query(
    'SELECT product_id, name, category, unit FROM products ORDER BY name ASC'
)->fetchAll();

// Available categories.
$category_stmt = $pdo->query(
    'SELECT DISTINCT category FROM products
     WHERE category IS NOT NULL AND category <> ""
     ORDER BY category ASC'
);
$categories = $category_stmt->fetchAll(PDO::FETCH_COLUMN);

// Current prices, with optional search/category filters.
$sql = 'SELECT gp.price_id, gp.product_id, gp.price_bdt, gp.updated_at,
               p.name, p.category, p.unit
        FROM grocery_prices gp
        INNER JOIN products p ON gp.product_id = p.product_id
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Price Tracker - GroceryGenius</title>
    <style>
        :root {
            --bg: #0d0718;
            --sidebar: #120922;
            --card: #170d2b;
            --border: #3b1b68;
            --text: #f5f0ff;
            --muted: #b8a8cf;
            --purple: #a855f7;
            --purple-light: #c084fc;
            --green: #4ade80;
            --red: #f87171;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: Arial, Helvetica, sans-serif; }
        .sidebar { position: fixed; left: 0; top: 0; width: 240px; height: 100vh; padding: 24px 16px; background: var(--sidebar); border-right: 1px solid var(--border); z-index: 1000; }
        .brand { padding: 4px 12px 24px; font-size: 23px; font-weight: 700; }
        .brand span { color: var(--purple-light); }
        .nav a { display: block; padding: 12px 14px; margin: 5px 0; color: var(--muted); text-decoration: none; border-radius: 9px; transition: .2s; }
        .nav a:hover, .nav a.active { background: #24113e; color: #fff; }
        .nav a.active { border-left: 3px solid var(--purple); }
        .logout { margin-top: 24px; border-top: 1px solid var(--border); padding-top: 16px; }
        .logout a { color: var(--red); }
        .main { margin-left: 240px; min-height: 100vh; padding: 34px; }
        .container { max-width: 1150px; margin: 0 auto; }
        .page-header { margin-bottom: 25px; }
        .page-header h1 { margin: 0 0 7px; font-size: 30px; }
        .page-header p { margin: 0; color: var(--muted); }
        .notice, .error { padding: 13px 16px; border-radius: 9px; margin-bottom: 18px; }
        .notice { background: #123421; border: 1px solid #246b3e; color: #86efac; }
        .error { background: #3a151d; border: 1px solid #7f1d2d; color: #fca5a5; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 15px; padding: 22px; margin-bottom: 22px; }
        .card h2 { margin: 0 0 18px; font-size: 20px; }
        .form-grid { display: grid; grid-template-columns: 1.4fr 1fr auto; gap: 12px; align-items: end; }
        .form-group label { display: block; margin-bottom: 7px; color: var(--muted); font-size: 14px; }
        input[type="number"], input[type="text"], select { width: 100%; padding: 12px 13px; background: #10091d; color: var(--text); border: 1px solid #4b2670; border-radius: 8px; outline: none; }
        input:focus, select:focus { border-color: var(--purple); }
        .btn { padding: 12px 17px; border: 0; border-radius: 8px; background: var(--purple); color: #fff; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { opacity: .9; }
        .btn-danger { background: #9f2942; }
        .filters { display: grid; grid-template-columns: 1fr 220px auto; gap: 12px; align-items: end; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 12px; text-align: left; border-bottom: 1px solid #2c1746; }
        th { color: var(--muted); font-size: 13px; font-weight: 600; }
        td { color: #eee7f8; }
        .price { color: var(--green); font-weight: 700; }
        .meta { color: var(--muted); font-size: 13px; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .empty { padding: 28px; text-align: center; color: var(--muted); border: 1px dashed #4b2670; border-radius: 10px; }
        .mobile-menu { display: none; position: fixed; top: 14px; left: 14px; z-index: 1100; padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--card); color: #fff; cursor: pointer; }
        @media (max-width: 850px) {
            .sidebar { transform: translateX(-100%); transition: transform .25s ease; }
            .sidebar.open { transform: translateX(0); }
            .mobile-menu { display: block; }
            .main { margin-left: 0; padding: 75px 18px 25px; }
            .form-grid, .filters { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<button class="mobile-menu" onclick="document.querySelector('.sidebar').classList.toggle('open')">☰</button>

<aside class="sidebar">
    <div class="brand">Grocery<span>Genius</span></div>
    <nav class="nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="pantry.php">Pantry</a>
        <a href="recipes.php">Recipes</a>
        <a href="shopping.php">Shopping List</a>
        <a href="budget.php">Budget</a>
        <a href="prices.php" class="active">Price Tracker</a>
    </nav>
    <div class="logout nav"><a href="logout.php">Logout</a></div>
</aside>

<main class="main">
    <div class="container">
        <div class="page-header">
            <h1>Price Tracker</h1>
            <p>Keep current grocery prices organized and up to date.</p>
        </div>

        <?php if ($message): ?><div class="notice"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <section class="card">
            <h2>Add / Update Price</h2>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="product_id">Product</label>
                        <select id="product_id" name="product_id" required>
                            <option value="">Select a product</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo (int) $product['product_id']; ?>">
                                    <?php echo htmlspecialchars($product['name']); ?><?php echo $product['category'] ? ' — ' . htmlspecialchars($product['category']) : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="price_bdt">Current Price (৳)</label>
                        <input type="number" id="price_bdt" name="price_bdt" min="0.01" step="0.01" placeholder="e.g. 120" required>
                    </div>
                    <button class="btn" type="submit" name="save_price">Save Price</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Find Prices</h2>
            <form method="GET">
                <div class="filters">
                    <div class="form-group">
                        <label for="search">Search product</label>
                        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="e.g. Milk, Rice, Banana">
                    </div>
                    <div class="form-group">
                        <label for="category">Category</label>
                        <select id="category" name="category">
                            <option value="">All categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn" type="submit">Search</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Current Grocery Prices</h2>
            <?php if (empty($prices)): ?>
                <div class="empty">No price records found. Add a product price above to start tracking prices.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Unit</th>
                                <th>Last Updated</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($prices as $item): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($item['name']); ?></strong></td>
                                    <td class="meta"><?php echo htmlspecialchars($item['category'] ?: '—'); ?></td>
                                    <td class="price">৳<?php echo number_format((float) $item['price_bdt'], 2); ?></td>
                                    <td class="meta"><?php echo htmlspecialchars($item['unit'] ?: '—'); ?></td>
                                    <td class="meta"><?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($item['updated_at']))); ?></td>
                                    <td>
                                        <div class="actions">
                                            <a class="btn btn-danger" href="prices.php?delete=<?php echo (int) $item['price_id']; ?>" onclick="return confirm('Delete this price record?');">Delete</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
