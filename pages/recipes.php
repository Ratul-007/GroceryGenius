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

// ── ADD TO SHOPPING LIST (servings-aware) ──
if (isset($_GET['add_to_shopping']) && is_numeric($_GET['add_to_shopping'])) {
    $recipe_id = (int)$_GET['add_to_shopping'];

    // Base servings for this recipe (used to scale ingredient quantities)
    $rs_stmt = $pdo->prepare("SELECT servings FROM recipes WHERE recipe_id = ?");
    $rs_stmt->execute([$recipe_id]);
    $base_servings_for_add = (int)$rs_stmt->fetchColumn();
    if ($base_servings_for_add <= 0) $base_servings_for_add = 1;

    // Desired servings chosen by the user on the card (falls back to base)
    $desired_servings_for_add = isset($_GET['servings']) ? max(1, (int)$_GET['servings']) : $base_servings_for_add;
    $add_multiplier = $desired_servings_for_add / $base_servings_for_add;

    // Get every ingredient this recipe needs, plus how much of it is
    // already purchased (this is the same "stock" source cook.php uses)
    $ing_stmt = $pdo->prepare("
        SELECT ri.product_id, ri.quantity, ri.unit,
            COALESCE((
                SELECT SUM(quantity) FROM shopping_list
                WHERE user_id = ? AND product_id = ri.product_id AND is_purchased = 1
            ), 0) AS purchased_qty
        FROM recipe_ingredients ri
        WHERE ri.recipe_id = ?
    ");
    $ing_stmt->execute([$user_id, $recipe_id]);
    $all_ings_for_add = $ing_stmt->fetchAll();

    foreach ($all_ings_for_add as $ing) {
        $required_qty = round((float)$ing['quantity'] * $add_multiplier, 2);
        $owned_qty    = (float)$ing['purchased_qty'];
        $shortfall    = round($required_qty - $owned_qty, 2);

        if ($shortfall <= 0) continue; // already have enough purchased for this serving size

        // Check if already pending (unpurchased) in shopping list
        $exists = $pdo->prepare("SELECT list_item_id, quantity FROM shopping_list WHERE user_id=? AND product_id=? AND is_purchased=0");
        $exists->execute([$user_id, $ing['product_id']]);
        $existing_row = $exists->fetch();

        if ($existing_row) {
            // Top the existing pending quantity up to whatever this recipe now needs
            $new_qty = max((float)$existing_row['quantity'], $shortfall);
            $pdo->prepare("UPDATE shopping_list SET quantity = ? WHERE list_item_id = ?")
                ->execute([$new_qty, $existing_row['list_item_id']]);
        } else {
            $ins = $pdo->prepare("INSERT INTO shopping_list (user_id, product_id, quantity) VALUES (?,?,?)");
            $ins->execute([$user_id, $ing['product_id'], $shortfall]);
        }
    }

    $success = 'Missing ingredients added to your shopping list'
        . ($desired_servings_for_add != $base_servings_for_add ? " (scaled for {$desired_servings_for_add} servings)" : '')
        . '!';
}

// ── FETCH RECIPE SUGGESTIONS ──
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT r.recipe_id, r.name, r.description, r.prep_time, r.cook_time, r.servings,
        COUNT(ri.product_id) AS total_ingredients,
        SUM(CASE WHEN COALESCE(sl.purchased_qty, 0) >= ri.quantity THEN 1 ELSE 0 END) AS have_count
    FROM recipes r
    JOIN recipe_ingredients ri ON r.recipe_id = ri.recipe_id
    LEFT JOIN (
        SELECT product_id, SUM(quantity) AS purchased_qty
        FROM shopping_list
        WHERE user_id = ? AND is_purchased = 1
        GROUP BY product_id
    ) sl ON sl.product_id = ri.product_id
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
// "have_it" now means: purchased quantity in shopping_list >= this
// ingredient's required quantity (base recipe servings) — same stock
// source cook.php checks before letting you cook.
function getIngredients($pdo, $recipe_id, $user_id) {
    $stmt = $pdo->prepare("
        SELECT p.name, ri.quantity, ri.unit,
            COALESCE(sl.purchased_qty, 0) AS purchased_qty,
            CASE WHEN COALESCE(sl.purchased_qty, 0) >= ri.quantity THEN 1 ELSE 0 END AS have_it
        FROM recipe_ingredients ri
        JOIN products p ON ri.product_id = p.product_id
        LEFT JOIN (
            SELECT product_id, SUM(quantity) AS purchased_qty
            FROM shopping_list
            WHERE user_id = ? AND is_purchased = 1
            GROUP BY product_id
        ) sl ON sl.product_id = ri.product_id
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

    /* SERVINGS SELECTOR */
    .serving-select {
      display: flex; align-items: center; gap: 8px;
      margin-top: 10px;
    }
    .serving-select label {
      font-size: 0.72rem; color: var(--text-soft);
      text-transform: uppercase; letter-spacing: 0.05em;
    }
    .serving-select input {
      width: 62px; padding: 4px 8px;
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: 6px; color: var(--text-main); font-size: 0.82rem;
      text-align: center;
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
      <a href="cooking_history.php" class="nav-item"><span class="nav-icon">📖</span> Cooking History</a>

      <div class="nav-label">Finance</div>
      <a href="budget.php" class="nav-item"><span class="nav-icon">💰</span> Budget</a>
      <a href="expense_history.php" class="nav-item"><span class="nav-icon">🧾</span> Expense History</a>
      <a href="monthly_report.php" class="nav-item"><span class="nav-icon">📊</span> Monthly Report</a>
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
        $recipe_servings = (int)$recipe['servings'];
        if ($recipe_servings <= 0) $recipe_servings = 1;
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

          <!-- SERVINGS SELECTOR: choose how many servings you actually want -->
          <div class="serving-select">
            <label for="servings-<?= $recipe['recipe_id'] ?>">Servings</label>
            <input type="number" min="1" value="<?= $recipe_servings ?>"
                   id="servings-<?= $recipe['recipe_id'] ?>"
                   oninput="updateServingLinks(<?= $recipe['recipe_id'] ?>, this.value, <?= $recipe_servings ?>)">
          </div>
        </div>

        <div class="match-section">
          <div class="full-match-banner" id="full-match-banner-<?= $recipe['recipe_id'] ?>"
               style="<?= $pct === 100 ? '' : 'display:none;' ?>">
            ✅ You have all ingredients! Ready to cook!
          </div>
          <div class="match-label">
            <span class="match-text">You have <span id="match-have-<?= $recipe['recipe_id'] ?>"><?= $have ?></span> of <?= $total ?> ingredients</span>
            <span class="match-pct <?= $pct_class ?>" id="match-pct-<?= $recipe['recipe_id'] ?>"><?= $pct ?>% match</span>
          </div>
          <div class="match-bar-wrap">
            <div class="match-bar" id="match-bar-<?= $recipe['recipe_id'] ?>" style="width:<?= $pct ?>%;background:<?= $bar_color ?>"></div>
          </div>
        </div>

        <div class="ingredients-section">
          <div class="ing-title">Ingredients</div>
          <div class="ing-list" id="ing-list-<?= $recipe['recipe_id'] ?>">
            <?php foreach ($ingredients as $ing): ?>
              <span class="ing-chip <?= $ing['have_it'] ? 'ing-have' : 'ing-missing' ?>"
                    data-base-qty="<?= $ing['quantity'] ?>"
                    data-purchased-qty="<?= $ing['purchased_qty'] ?>">
                <span class="ing-icon"><?= $ing['have_it'] ? '✅' : '❌' ?></span>
                <?= htmlspecialchars($ing['name']) ?>
                (<span class="ing-qty"><?= number_format($ing['quantity'], 2) ?></span> <?= htmlspecialchars($ing['unit']) ?>)
              </span>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="recipe-card-footer">
          <?php if ($missing_count === 0): ?>
            <a href="cook.php?recipe_id=<?= $recipe['recipe_id'] ?>&servings=<?= $recipe_servings ?>"
               id="action-link-<?= $recipe['recipe_id'] ?>"
               class="add-shopping-btn can-cook">
              🍳 Cook this Recipe
            </a>
          <?php else: ?>
            <a href="recipes.php?add_to_shopping=<?= $recipe['recipe_id'] ?>&servings=<?= $recipe_servings ?>"
               id="action-link-<?= $recipe['recipe_id'] ?>"
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

// Keep everything on the card — ingredient quantities, have/missing status,
// match %, the full-match banner, and the action button — in sync with
// whatever serving size the user types in. All of this is recomputed
// against the ingredient's real purchased_qty (the same stock cook.php
// checks), not just re-displaying a static server-rendered value.
function matchPctClass(pct) {
  if (pct === 100) return 'full';
  if (pct >= 60)   return 'high';
  if (pct >= 30)   return 'medium';
  return 'low';
}
function matchPctColor(pct) {
  if (pct === 100) return '#10b981';
  if (pct >= 60)   return '#a3e635';
  if (pct >= 30)   return '#f59e0b';
  return '#ef4444';
}

function updateServingLinks(recipeId, servings, baseServings) {
  servings = Math.max(1, parseInt(servings) || 1);
  const multiplier = servings / baseServings;

  const ingList = document.getElementById('ing-list-' + recipeId);
  let haveCount = 0;
  let totalCount = 0;

  if (ingList) {
    const chips = ingList.querySelectorAll('.ing-chip');
    totalCount = chips.length;

    chips.forEach(function (chip) {
      const baseQty      = parseFloat(chip.dataset.baseQty);
      const purchasedQty = parseFloat(chip.dataset.purchasedQty || '0');
      const requiredQty  = baseQty * multiplier;

      // Update the displayed quantity
      const qtyEl = chip.querySelector('.ing-qty');
      if (qtyEl) {
        qtyEl.textContent = (requiredQty % 1 === 0) ? requiredQty.toFixed(0) : requiredQty.toFixed(2);
      }

      // Recheck against what's actually purchased for THIS serving size
      const haveIt = purchasedQty >= requiredQty - 1e-9;
      if (haveIt) haveCount++;

      chip.classList.toggle('ing-have', haveIt);
      chip.classList.toggle('ing-missing', !haveIt);

      const iconEl = chip.querySelector('.ing-icon');
      if (iconEl) iconEl.textContent = haveIt ? '✅' : '❌';
    });
  }

  const pct = totalCount > 0 ? Math.round((haveCount / totalCount) * 100) : 0;

  const haveEl = document.getElementById('match-have-' + recipeId);
  if (haveEl) haveEl.textContent = haveCount;

  const pctEl = document.getElementById('match-pct-' + recipeId);
  if (pctEl) {
    pctEl.textContent = pct + '% match';
    pctEl.className   = 'match-pct ' + matchPctClass(pct);
  }

  const barEl = document.getElementById('match-bar-' + recipeId);
  if (barEl) {
    barEl.style.width      = pct + '%';
    barEl.style.background = matchPctColor(pct);
  }

  const bannerEl = document.getElementById('full-match-banner-' + recipeId);
  if (bannerEl) bannerEl.style.display = (pct === 100) ? 'block' : 'none';

  const missingCount = totalCount - haveCount;
  const actionLink = document.getElementById('action-link-' + recipeId);
  if (actionLink) {
    if (missingCount === 0) {
      actionLink.href = 'cook.php?recipe_id=' + recipeId + '&servings=' + servings;
      actionLink.textContent = '🍳 Cook this Recipe';
      actionLink.classList.remove('need-more');
      actionLink.classList.add('can-cook');
    } else {
      actionLink.href = 'recipes.php?add_to_shopping=' + recipeId + '&servings=' + servings;
      actionLink.textContent = '🛍️ Add ' + missingCount + ' missing item' + (missingCount > 1 ? 's' : '') + ' to Shopping List';
      actionLink.classList.remove('can-cook');
      actionLink.classList.add('need-more');
    }
  }
}
</script>

</body>
</html>