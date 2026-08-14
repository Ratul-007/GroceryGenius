<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$avatar_letter = strtoupper(substr($user_name, 0, 1));

$success = '';
$error   = '';

// ── SEED RECIPES IF EMPTY ──
$check = $pdo->query("SELECT COUNT(*) FROM recipes")->fetchColumn();
if ($check == 0) {
    $recipes_data = [
        ['Egg Fried Rice',       'A quick and delicious fried rice with eggs.',          15, 10, 2],
        ['Vegetable Curry',      'Spicy mixed vegetable curry with rice.',                10, 25, 3],
        ['Milk Oatmeal',         'Creamy oatmeal cooked with milk and banana.',           5,  10, 1],
        ['Potato Stir Fry',      'Simple stir fried potatoes with spices.',              10, 15, 2],
        ['Banana Smoothie',      'Refreshing banana smoothie with milk.',                 5,   5, 1],
        ['Tomato Rice',          'Flavourful tomato rice cooked with spices.',           10,  20, 2],
        ['Egg Omelette',         'Simple egg omelette with onion and chilli.',            5,  10, 1],
        ['Vegetable Soup',       'Healthy mixed vegetable soup.',                        10,  20, 2],
        ['Chicken Curry',        'Classic Bangladeshi chicken curry with rice.',         15,  35, 4],
        ['Dal with Rice',        'Yellow lentil dal served with steamed rice.',          10,  25, 2],
        ['Pasta with Sauce',     'Spaghetti with homemade tomato sauce.',                10,  20, 3],
        ['Fruit Salad',          'Fresh mixed fruit salad with honey.',                   5,   5, 1],
    ];

    $recipe_stmt = $pdo->prepare("INSERT INTO recipes (name, description, prep_time, cook_time, servings) VALUES (?,?,?,?,?)");
    foreach ($recipes_data as $r) {
        $recipe_stmt->execute($r);
    }

    // Seed products needed for recipes
    $products_data = [
        ['Egg',       'Dairy',      'pcs'],
        ['Rice',      'Grains',     'kg'],
        ['Milk',      'Dairy',      'L'],
        ['Potato',    'Vegetables', 'kg'],
        ['Banana',    'Fruits',     'pcs'],
        ['Tomato',    'Vegetables', 'kg'],
        ['Onion',     'Vegetables', 'kg'],
        ['Oatmeal',   'Grains',     'kg'],
        ['Chicken',   'Meat',       'kg'],
        ['Lentil',    'Grains',     'kg'],
        ['Pasta',     'Grains',     'kg'],
        ['Mixed Fruit', 'Fruits',   'kg'],
        ['Chilli',    'Spices',     'pcs'],
        ['Spices',    'Spices',     'pack'],
        ['Honey',     'Other',      'bottle'],
    ];

    $prod_stmt = $pdo->prepare("INSERT IGNORE INTO products (name, category, unit) VALUES (?,?,?)");
    foreach ($products_data as $p) {
        $prod_stmt->execute($p);
    }

    // Get product IDs
    $all_products = $pdo->query("SELECT product_id, name FROM products")->fetchAll(PDO::FETCH_KEY_PAIR);
    $prod_map = array_flip($all_products);

    // Recipe ingredients [recipe_name => [ingredient_name, qty, unit]]
    $ingredients = [
        'Egg Fried Rice'    => [['Egg',2,'pcs'],['Rice',0.5,'kg'],['Onion',1,'pcs'],['Spices',1,'pack']],
        'Vegetable Curry'   => [['Potato',0.5,'kg'],['Tomato',2,'pcs'],['Onion',1,'pcs'],['Spices',1,'pack'],['Rice',0.5,'kg']],
        'Milk Oatmeal'      => [['Milk',0.3,'L'],['Oatmeal',0.1,'kg'],['Banana',1,'pcs']],
        'Potato Stir Fry'   => [['Potato',0.5,'kg'],['Onion',1,'pcs'],['Spices',1,'pack'],['Chilli',2,'pcs']],
        'Banana Smoothie'   => [['Banana',2,'pcs'],['Milk',0.25,'L']],
        'Tomato Rice'       => [['Tomato',3,'pcs'],['Rice',0.5,'kg'],['Onion',1,'pcs'],['Spices',1,'pack']],
        'Egg Omelette'      => [['Egg',3,'pcs'],['Onion',1,'pcs'],['Chilli',1,'pcs']],
        'Vegetable Soup'    => [['Potato',0.3,'kg'],['Tomato',2,'pcs'],['Onion',1,'pcs'],['Spices',1,'pack']],
        'Chicken Curry'     => [['Chicken',0.5,'kg'],['Onion',2,'pcs'],['Tomato',2,'pcs'],['Spices',1,'pack'],['Rice',0.5,'kg']],
        'Dal with Rice'     => [['Lentil',0.2,'kg'],['Rice',0.5,'kg'],['Onion',1,'pcs'],['Spices',1,'pack']],
        'Pasta with Sauce'  => [['Pasta',0.2,'kg'],['Tomato',3,'pcs'],['Onion',1,'pcs'],['Spices',1,'pack']],
        'Fruit Salad'       => [['Mixed Fruit',0.5,'kg'],['Banana',1,'pcs'],['Honey',1,'bottle']],
    ];

    // Get recipe IDs
    $all_recipes = $pdo->query("SELECT recipe_id, name FROM recipes")->fetchAll(PDO::FETCH_KEY_PAIR);
    $rec_map = array_flip($all_recipes);

    $ing_stmt = $pdo->prepare("INSERT IGNORE INTO recipe_ingredients (recipe_id, product_id, quantity, unit) VALUES (?,?,?,?)");
    foreach ($ingredients as $recipe_name => $ings) {
        if (!isset($rec_map[$recipe_name])) continue;
        $recipe_id = $rec_map[$recipe_name];
        foreach ($ings as $ing) {
            $pname = $ing[0]; $qty = $ing[1]; $unit = $ing[2];
            // find or create product
            $pid_stmt = $pdo->prepare("SELECT product_id FROM products WHERE name = ?");
            $pid_stmt->execute([$pname]);
            $pid = $pid_stmt->fetchColumn();
            if (!$pid) {
                $ins = $pdo->prepare("INSERT INTO products (name, category, unit) VALUES (?,?,?)");
                $ins->execute([$pname, 'Other', $unit]);
                $pid = $pdo->lastInsertId();
            }
            $ing_stmt->execute([$recipe_id, $pid, $qty, $unit]);
        }
    }
}

// ── ADD TO SHOPPING LIST ──
if (isset($_GET['add_to_shopping']) && is_numeric($_GET['add_to_shopping'])) {
    $recipe_id = (int)$_GET['add_to_shopping'];

    // Get missing ingredients for this recipe
    $missing = $pdo->prepare("
        SELECT ri.product_id, ri.quantity, ri.unit
        FROM recipe_ingredients ri
        LEFT JOIN pantry_items pi ON ri.product_id = pi.product_id AND pi.user_id = ?
        WHERE ri.recipe_id = ? AND pi.item_id IS NULL
    ");
    $missing->execute([$user_id, $recipe_id]);
    $missing_items = $missing->fetchAll();

    foreach ($missing_items as $m) {
        // Check if already in shopping list
        $exists = $pdo->prepare("SELECT list_item_id FROM shopping_list WHERE user_id=? AND product_id=? AND is_purchased=0");
        $exists->execute([$user_id, $m['product_id']]);
        if (!$exists->fetch()) {
            $ins = $pdo->prepare("INSERT INTO shopping_list (user_id, product_id, quantity) VALUES (?,?,?)");
            $ins->execute([$user_id, $m['product_id'], $m['quantity']]);
        }
    }
    $success = 'Missing ingredients added to your shopping list!';
}

// ── FETCH RECIPE SUGGESTIONS ──
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT r.recipe_id, r.name, r.description, r.prep_time, r.cook_time, r.servings,
        COUNT(ri.product_id) AS total_ingredients,
        SUM(CASE WHEN pi.product_id IS NOT NULL THEN 1 ELSE 0 END) AS have_count
    FROM recipes r
    JOIN recipe_ingredients ri ON r.recipe_id = ri.recipe_id
    LEFT JOIN pantry_items pi ON ri.product_id = pi.product_id AND pi.user_id = ?
";
$params = [$user_id];

if (!empty($search)) {
    $sql .= " WHERE r.name LIKE ?";
    $params[] = "%$search%";
}

$sql .= " GROUP BY r.recipe_id ORDER BY have_count DESC, total_ingredients ASC";

$recipes_stmt = $pdo->prepare($sql);
$recipes_stmt->execute($params);
$recipes = $recipes_stmt->fetchAll();

// ── FETCH INGREDIENTS FOR EACH RECIPE ──
function getIngredients($pdo, $recipe_id, $user_id) {
    $stmt = $pdo->prepare("
        SELECT p.name, ri.quantity, ri.unit,
            CASE WHEN pi.item_id IS NOT NULL THEN 1 ELSE 0 END as have_it
        FROM recipe_ingredients ri
        JOIN products p ON ri.product_id = p.product_id
        LEFT JOIN pantry_items pi ON ri.product_id = pi.product_id AND pi.user_id = ?
        WHERE ri.recipe_id = ?
    ");
    $stmt->execute([$user_id, $recipe_id]);
    return $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Recipes — GroceryGenius</title>
  <link rel="stylesheet" href="../assets/css/style.css"/>
  <style>
    .recipe-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 18px;
    }

    .recipe-card {
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: var(--radius); overflow: hidden;
      transition: transform 0.2s, box-shadow 0.2s;
      display: flex; flex-direction: column;
    }
    .recipe-card:hover { transform: translateY(-3px); box-shadow: var(--shadow); }

    .recipe-card-header {
      padding: 18px 18px 12px;
      border-bottom: 1px solid var(--border);
    }
    .recipe-name {
      font-size: 1rem; font-weight: 700; color: var(--text-main); margin-bottom: 6px;
    }
    .recipe-desc {
      font-size: 0.8rem; color: var(--text-muted); line-height: 1.5;
    }
    .recipe-meta {
      display: flex; gap: 12px; margin-top: 10px; flex-wrap: wrap;
    }
    .recipe-meta span {
      font-size: 0.72rem; color: var(--text-soft);
      display: flex; align-items: center; gap: 4px;
    }

    /* MATCH BAR */
    .match-section { padding: 14px 18px; border-bottom: 1px solid var(--border); }
    .match-label {
      display: flex; justify-content: space-between;
      font-size: 0.78rem; margin-bottom: 6px;
    }
    .match-text { color: var(--text-muted); }
    .match-pct  { font-weight: 700; }
    .match-pct.full    { color: #6ee7b7; }
    .match-pct.high    { color: #a3e635; }
    .match-pct.medium  { color: #fcd34d; }
    .match-pct.low     { color: #fca5a5; }

    .match-bar-wrap { background: rgba(61,18,120,0.3); border-radius: 8px; height: 8px; }
    .match-bar { height: 8px; border-radius: 8px; transition: width 0.4s; }

    /* INGREDIENTS */
    .ingredients-section { padding: 14px 18px; flex: 1; }
    .ing-title { font-size: 0.72rem; font-weight: 700; color: var(--text-muted);
                 text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 10px; }
    .ing-list { display: flex; flex-wrap: wrap; gap: 6px; }
    .ing-chip {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 500;
    }
    .ing-have    { background: rgba(16,185,129,0.15); color: #6ee7b7; border: 1px solid rgba(16,185,129,0.2); }
    .ing-missing { background: rgba(239,68,68,0.1);   color: #fca5a5; border: 1px solid rgba(239,68,68,0.2); }

    /* CARD FOOTER */
    .recipe-card-footer { padding: 12px 18px; border-top: 1px solid var(--border); }
    .add-shopping-btn {
      display: block; width: 100%; text-align: center;
      padding: 9px; border-radius: var(--radius-sm);
      font-size: 0.85rem; font-weight: 600; text-decoration: none;
      transition: all 0.2s;
    }
    .can-cook {
      background: linear-gradient(135deg, var(--purple-500), var(--purple-400));
      color: #fff; box-shadow: 0 4px 12px rgba(124,58,237,0.3);
    }
    .can-cook:hover { opacity: 0.9; color: #fff; }
    .need-more {
      background: rgba(124,58,237,0.1); color: var(--purple-300);
      border: 1px solid var(--border);
    }
    .need-more:hover { background: rgba(124,58,237,0.2); color: var(--purple-300); }

    .full-match-banner {
      background: linear-gradient(135deg, rgba(16,185,129,0.2), rgba(16,185,129,0.05));
      border: 1px solid rgba(16,185,129,0.3);
      border-radius: 6px; padding: 6px 10px; margin-bottom: 10px;
      font-size: 0.78rem; color: #6ee7b7; text-align: center; font-weight: 600;
    }

    .toolbar { display: flex; gap: 10px; margin-bottom: 20px; }
    .search-wrap { position: relative; flex: 1; }
    .search-wrap input { padding-left: 36px; }
    .search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); pointer-events: none; }

    .recipes-count { font-size: 0.82rem; color: var(--text-muted); margin-bottom: 16px; }

    @media (max-width: 768px) {
      .recipe-grid { grid-template-columns: 1fr; }
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
      <a href="recipes.php" class="nav-item active"><span class="nav-icon">🍳</span> Recipes</a>
      <a href="shopping.php" class="nav-item"><span class="nav-icon">🛍️</span> Shopping List</a>
      <div class="nav-label">Finance</div>
      <a href="budget.php" class="nav-item"><span class="nav-icon">💰</span> Budget</a>
      <a href="prices.php" class="nav-item"><span class="nav-icon">📊</span> Price Tracker</a>
      <div class="nav-label">Account</div>
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

  <!-- MAIN CONTENT -->
  <main class="main-content">

    <div class="page-header">
      <div class="page-title">🍳 Recipe Suggestions</div>
      <div class="page-sub">Recipes ranked by how many ingredients you already have</div>
    </div>

    <?php if ($success): ?>
      <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- SEARCH -->
    <div class="toolbar">
      <form method="GET" action="" class="search-wrap">
        <span class="search-icon">🔍</span>
        <input type="text" name="search" class="form-control"
          placeholder="Search recipes..."
          value="<?= htmlspecialchars($search) ?>"/>
      </form>
    </div>

    <div class="recipes-count">
      Showing <strong><?= count($recipes) ?></strong> recipes —
      sorted by your pantry match
    </div>

    <!-- RECIPE CARDS -->
    <div class="recipe-grid">
      <?php foreach ($recipes as $recipe):
        $have  = (int)$recipe['have_count'];
        $total = (int)$recipe['total_ingredients'];
        $pct   = $total > 0 ? round(($have / $total) * 100) : 0;

        if ($pct === 100)     { $bar_color = '#10b981'; $pct_class = 'full'; }
        elseif ($pct >= 60)   { $bar_color = '#a3e635'; $pct_class = 'high'; }
        elseif ($pct >= 30)   { $bar_color = '#f59e0b'; $pct_class = 'medium'; }
        else                   { $bar_color = '#ef4444'; $pct_class = 'low'; }

        $ingredients = getIngredients($pdo, $recipe['recipe_id'], $user_id);
        $missing_count = $total - $have;
      ?>
      <div class="recipe-card">

        <div class="recipe-card-header">
          <div class="recipe-name">🍽️ <?= htmlspecialchars($recipe['name']) ?></div>
          <div class="recipe-desc"><?= htmlspecialchars($recipe['description']) ?></div>
          <div class="recipe-meta">
            <span>⏱️ Prep: <?= $recipe['prep_time'] ?>m</span>
            <span>🔥 Cook: <?= $recipe['cook_time'] ?>m</span>
            <span>🍽️ Serves: <?= $recipe['servings'] ?></span>
          </div>
        </div>

        <div class="match-section">
          <?php if ($pct === 100): ?>
            <div class="full-match-banner">✅ You have all ingredients! Ready to cook!</div>
          <?php endif; ?>
          <div class="match-label">
            <span class="match-text">You have <?= $have ?> of <?= $total ?> ingredients</span>
            <span class="match-pct <?= $pct_class ?>"><?= $pct ?>% match</span>
          </div>
          <div class="match-bar-wrap">
            <div class="match-bar" style="width:<?= $pct ?>%;background:<?= $bar_color ?>"></div>
          </div>
        </div>

        <div class="ingredients-section">
          <div class="ing-title">Ingredients</div>
          <div class="ing-list">
            <?php foreach ($ingredients as $ing): ?>
              <span class="ing-chip <?= $ing['have_it'] ? 'ing-have' : 'ing-missing' ?>">
                <?= $ing['have_it'] ? '✅' : '❌' ?>
                <?= htmlspecialchars($ing['name']) ?>
                (<?= $ing['quantity'] ?> <?= $ing['unit'] ?>)
              </span>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="recipe-card-footer">
          <?php if ($missing_count === 0): ?>
            <a href="recipes.php?add_to_shopping=<?= $recipe['recipe_id'] ?>"
               class="add-shopping-btn can-cook">
              🍳 Cook this Recipe
            </a>
          <?php else: ?>
            <a href="recipes.php?add_to_shopping=<?= $recipe['recipe_id'] ?>"
               class="add-shopping-btn need-more">
              🛍️ Add <?= $missing_count ?> missing item<?= $missing_count > 1 ? 's' : '' ?> to Shopping List
            </a>
          <?php endif; ?>
        </div>

      </div>
      <?php endforeach; ?>
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
