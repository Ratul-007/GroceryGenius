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

$recipe_id = isset($_GET['recipe_id']) ? (int)$_GET['recipe_id'] : 0;

if ($recipe_id <= 0) {
    header('Location: recipes.php');
    exit;
}


// ============================================================
// FETCH RECIPE
// ============================================================

$stmt = $pdo->prepare("
    SELECT recipe_id, name, description, prep_time, cook_time, servings, instructions
    FROM recipes WHERE recipe_id = ?
");
$stmt->execute([$recipe_id]);
$recipe = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recipe) {
    header('Location: recipes.php');
    exit;
}

$base_servings = (int)$recipe['servings'];
if ($base_servings <= 0) $base_servings = 1;

// Servings the user actually wants to cook — carried over from the recipes
// page (?servings=X) if present, otherwise falls back to the recipe default.
// This is what makes the serving adjuster meaningful from the very first
// load, instead of only being validated at the very end.
$desired_servings   = isset($_GET['servings']) ? max(1, (int)$_GET['servings']) : $base_servings;
$serving_multiplier = $desired_servings / $base_servings;


// ============================================================
// FETCH RECIPE INGREDIENTS
// ============================================================

$ing_stmt = $pdo->prepare("
    SELECT ri.product_id, p.name, ri.quantity, ri.unit,
        COALESCE((
            SELECT SUM(sl.quantity) FROM shopping_list sl
            WHERE sl.user_id = ? AND sl.product_id = ri.product_id AND sl.is_purchased = 1
        ), 0) AS available_qty
    FROM recipe_ingredients ri
    INNER JOIN products p ON ri.product_id = p.product_id
    WHERE ri.recipe_id = ?
    ORDER BY ri.id ASC
");
$ing_stmt->execute([$user_id, $recipe_id]);
$ingredients_raw = $ing_stmt->fetchAll(PDO::FETCH_ASSOC);


// ============================================================
// CHECK AVAILABILITY (scaled to the desired servings)
// ============================================================

$total_ingredients = count($ingredients_raw);
$have_count        = 0;
$ingredients       = [];

foreach ($ingredients_raw as $ing) {
    $required_qty  = (float)$ing['quantity'] * $serving_multiplier;
    $available_qty = (float)$ing['available_qty'];
    $have_it       = ($available_qty >= $required_qty);
    if ($have_it) $have_count++;
    $ing['required_qty']  = $required_qty;
    $ing['available_qty'] = $available_qty;
    $ing['have_it']       = $have_it ? 1 : 0;
    $ingredients[]        = $ing;
}

$can_cook = ($total_ingredients > 0 && $have_count === $total_ingredients);


// ============================================================
// DECODE INSTRUCTIONS
// ============================================================

$instructions = [];
if (!empty($recipe['instructions'])) {
    $decoded = json_decode($recipe['instructions'], true);
    if (is_array($decoded)) $instructions = $decoded;
}

if (empty($instructions)) {
    $instructions = [
        'Prepare all the ingredients.',
        'Follow the recipe ingredients carefully.',
        'Cook until the food is ready.',
        'Check the taste and adjust seasoning if necessary.',
        'Serve the food properly.',
        'Let the food rest for a moment.',
        'Enjoy your meal!'
    ];
}

$total_steps = count($instructions);


// ============================================================
// AJAX: FINISH COOKING
// ============================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'finish_cooking'
) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $posted_recipe_id = isset($_POST['recipe_id']) ? (int)$_POST['recipe_id'] : 0;
        $serving_multiplier_post = isset($_POST['serving_multiplier']) ? (float)$_POST['serving_multiplier'] : 1.0;
        if ($serving_multiplier_post <= 0) $serving_multiplier_post = 1.0;

        if ($posted_recipe_id !== $recipe_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid recipe.']);
            exit;
        }

        $pdo->beginTransaction();

        $recipe_ing_stmt = $pdo->prepare("
            SELECT ri.product_id, ri.quantity, ri.unit, p.name
            FROM recipe_ingredients ri
            INNER JOIN products p ON ri.product_id = p.product_id
            WHERE ri.recipe_id = ?
            ORDER BY ri.id ASC
        ");
        $recipe_ing_stmt->execute([$recipe_id]);
        $recipe_ingredients = $recipe_ing_stmt->fetchAll(PDO::FETCH_ASSOC);

        // STEP 1: Validate
        foreach ($recipe_ingredients as $ingredient) {
            $product_id   = (int)$ingredient['product_id'];
            $required_qty = (float)$ingredient['quantity'] * $serving_multiplier_post;

            $stock_stmt = $pdo->prepare("
                SELECT COALESCE(SUM(quantity), 0)
                FROM shopping_list
                WHERE user_id = ? AND product_id = ? AND is_purchased = 1
            ");
            $stock_stmt->execute([$user_id, $product_id]);
            $available_qty = (float)$stock_stmt->fetchColumn();

            if ($available_qty < $required_qty) {
                $pdo->rollBack();
                echo json_encode([
                    'success' => false,
                    'message' => 'Not enough ' . $ingredient['name'] .
                                 '. Required: ' . round($required_qty, 2) . ' ' . $ingredient['unit'] .
                                 ', Available: ' . $available_qty . ' ' . $ingredient['unit']
                ]);
                exit;
            }
        }

        // STEP 2: Deduct
        foreach ($recipe_ingredients as $ingredient) {
            $product_id = (int)$ingredient['product_id'];
            $remaining  = (float)$ingredient['quantity'] * $serving_multiplier_post;

            $stock_rows_stmt = $pdo->prepare("
                SELECT list_item_id, quantity FROM shopping_list
                WHERE user_id = ? AND product_id = ? AND is_purchased = 1 AND quantity > 0
                ORDER BY list_item_id ASC FOR UPDATE
            ");
            $stock_rows_stmt->execute([$user_id, $product_id]);
            $stock_rows = $stock_rows_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($stock_rows as $stock) {
                if ($remaining <= 0) break;
                $list_item_id = (int)$stock['list_item_id'];
                $current_qty  = (float)$stock['quantity'];

                if ($current_qty <= $remaining) {
                    $pdo->prepare("DELETE FROM shopping_list WHERE list_item_id = ? AND user_id = ?")
                        ->execute([$list_item_id, $user_id]);
                    $remaining -= $current_qty;
                } else {
                    $pdo->prepare("UPDATE shopping_list SET quantity = ? WHERE list_item_id = ? AND user_id = ?")
                        ->execute([$current_qty - $remaining, $list_item_id, $user_id]);
                    $remaining = 0;
                }
            }
        }

        $pdo->commit();

        $pdo->prepare("INSERT INTO cooking_history (user_id, recipe_id) VALUES (?, ?)")
            ->execute([$user_id, $recipe_id]);

        echo json_encode([
            'success'   => true,
            'message'   => 'Recipe cooked! Ingredients consumed from your shopping stock.',
            'recipe_id' => $recipe_id
        ]);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Could not complete cooking. Please try again.']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Cooking <?= htmlspecialchars($recipe['name']) ?> — GroceryGenius</title>
    <link rel="stylesheet" href="../assets/css/style.css"/>
    <style>

        .cook-page { max-width: 900px; margin: 0 auto; }
        .cook-header { text-align: center; margin-bottom: 25px; }
        .cook-icon { font-size: 3.5rem; margin-bottom: 10px; }
        .cook-title { font-size: 1.8rem; font-weight: 800; color: var(--text-main); }
        .cook-description { color: var(--text-muted); margin-top: 8px; }

        .cook-meta {
            display: flex; justify-content: center; gap: 18px;
            margin-top: 15px; flex-wrap: wrap;
        }
        .cook-meta span {
            background: var(--bg-card); border: 1px solid var(--border);
            padding: 7px 12px; border-radius: 8px;
            font-size: 0.8rem; color: var(--text-muted);
        }

        /* ── SERVING ADJUSTER ── */
        .serving-adjuster {
            display: flex; align-items: center; justify-content: center;
            gap: 12px; margin-top: 16px;
        }
        .serving-label { font-size: 0.82rem; color: var(--text-muted); }
        .serving-controls { display: flex; align-items: center; gap: 8px; }
        .serving-btn {
            width: 32px; height: 32px; border-radius: 50%;
            border: 1px solid var(--border); background: var(--bg-card);
            color: var(--purple-300); font-size: 1.1rem; font-weight: 700;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            transition: all 0.2s; line-height: 1;
        }
        .serving-btn:hover { background: rgba(124,58,237,0.2); border-color: var(--purple-400); }
        .serving-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .serving-display {
            min-width: 80px; text-align: center;
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 8px; padding: 6px 14px;
            font-size: 0.88rem; font-weight: 700; color: var(--text-main);
        }
        .serving-multiplier-badge {
            font-size: 0.72rem; color: var(--purple-300);
            background: rgba(124,58,237,0.15); border: 1px solid var(--border);
            border-radius: 20px; padding: 3px 10px;
        }

        .ingredients-box {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 20px; margin-bottom: 20px;
        }
        .section-title { font-size: 0.9rem; font-weight: 700; color: var(--text-main); margin-bottom: 14px; }
        .ingredient-list { display: flex; flex-wrap: wrap; gap: 8px; }
        .ingredient { padding: 7px 11px; border-radius: 8px; font-size: 0.8rem; }
        .ingredient.have {
            background: rgba(16,185,129,0.15); color: #6ee7b7;
            border: 1px solid rgba(16,185,129,0.25);
        }
        .stock-note { margin-top: 12px; color: var(--text-soft); font-size: 0.75rem; text-align: center; }

        .cooking-card {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: var(--radius); overflow: hidden;
        }

        .progress-container { padding: 18px 22px; border-bottom: 1px solid var(--border); }
        .progress-info { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.8rem; color: var(--text-muted); }
        .progress-bar { height: 8px; background: rgba(61,18,120,0.3); border-radius: 10px; overflow: hidden; }
        .progress-fill {
            height: 100%; width: 0%;
            background: linear-gradient(90deg, var(--purple-500), var(--purple-400));
            border-radius: 10px; transition: width 0.3s ease;
        }

        .step-content {
            padding: 50px 35px; text-align: center; min-height: 230px;
            display: flex; flex-direction: column; justify-content: center;
        }
        .step-number {
            width: 55px; height: 55px; margin: 0 auto 18px; border-radius: 50%;
            background: rgba(124,58,237,0.18); color: var(--purple-300);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; font-weight: 800;
        }
        .step-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-soft); margin-bottom: 10px; }
        .step-text { font-size: 1.25rem; line-height: 1.6; color: var(--text-main); font-weight: 600; max-width: 650px; margin: 0 auto; }

        .cook-controls {
            padding: 16px 22px; border-top: 1px solid var(--border);
            display: flex; justify-content: space-between; gap: 10px;
        }
        .cook-btn {
            padding: 10px 18px; border-radius: var(--radius-sm);
            border: 1px solid var(--border); cursor: pointer;
            font-weight: 600; font-size: 0.85rem;
            background: var(--bg-card); color: var(--text-muted);
        }
        .cook-btn:hover { border-color: var(--purple-400); color: var(--purple-300); }
        .cook-btn.primary { background: var(--purple-600); color: white; border-color: var(--purple-500); }
        .cook-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .finish-screen { display: none; text-align: center; padding: 55px 25px; }
        .finish-icon  { font-size: 4rem; margin-bottom: 15px; }
        .finish-title { font-size: 1.6rem; font-weight: 800; color: var(--text-main); margin-bottom: 8px; }
        .finish-text  { color: var(--text-muted); margin-bottom: 25px; }
        .back-btn {
            display: inline-block; text-decoration: none; padding: 10px 18px;
            border-radius: var(--radius-sm); background: var(--purple-600);
            color: white; font-weight: 600;
        }

        .not-ready {
            background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.25);
            border-radius: var(--radius); padding: 25px; text-align: center; margin-bottom: 20px;
        }
        .not-ready-title { color: #fca5a5; font-weight: 700; margin-bottom: 8px; }
        .not-ready-text  { color: var(--text-muted); font-size: 0.85rem; }

        .cook-error {
            display: none; margin: 0 22px 16px; padding: 12px 15px; border-radius: 8px;
            background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3);
            color: #fca5a5; font-size: 0.85rem; text-align: center;
        }

        @media (max-width: 600px) {
            .step-content { padding: 35px 20px; }
            .step-text { font-size: 1.05rem; }
            .cook-controls { flex-direction: column; }
            .cook-btn { width: 100%; }
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
                <div class="user-avatar"><?= htmlspecialchars($avatar_letter) ?></div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($user_name) ?></div>
                    <div class="user-role">Member</div>
                </div>
            </div>
        </div>
    </aside>


    <main class="main-content">
        <div class="cook-page">

            <!-- HEADER -->
            <div class="cook-header">
                <div class="cook-icon">🍳</div>
                <div class="cook-title"><?= htmlspecialchars($recipe['name']) ?></div>
                <div class="cook-description"><?= htmlspecialchars($recipe['description']) ?></div>
                <div class="cook-meta">
                    <span>⏱️ Prep: <?= (int)$recipe['prep_time'] ?> min</span>
                    <span>🔥 Cook: <?= (int)$recipe['cook_time'] ?> min</span>
                    <span>🍽️ Serves: <span id="servesDisplay"><?= $desired_servings ?></span></span>
                </div>

                <!-- SERVING SIZE ADJUSTER -->
                <?php if ($can_cook): ?>
                <div class="serving-adjuster">
                    <span class="serving-label">Serving size:</span>
                    <div class="serving-controls">
                        <button class="serving-btn" id="servingDown" onclick="adjustServing(-1)">−</button>
                        <div class="serving-display" id="servingDisplay"><?= $desired_servings ?> servings</div>
                        <button class="serving-btn" id="servingUp" onclick="adjustServing(1)">+</button>
                    </div>
                    <span class="serving-multiplier-badge" id="multiplierBadge"
                          style="<?= abs($serving_multiplier - 1.0) > 0.0001 ? '' : 'display:none;' ?>">
                        <?= (fmod($serving_multiplier, 1) == 0)
                            ? (int)$serving_multiplier . '×'
                            : number_format($serving_multiplier, 2) . '×' ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>


            <?php if (!$can_cook): ?>

            <div class="not-ready">
                <div class="not-ready-title">⚠️ Ingredients are not ready</div>
                <div class="not-ready-text">
                    For <strong><?= $desired_servings ?></strong> serving<?= $desired_servings > 1 ? 's' : '' ?>,
                    you have <strong><?= $have_count ?></strong> of
                    <strong><?= $total_ingredients ?></strong> required ingredients
                    in your purchased shopping stock.
                    <br><br>
                    Please purchase the missing ingredients from your Shopping List before cooking.
                    <?php if ($desired_servings != $base_servings): ?>
                        <br><br>
                        <a href="recipes.php?add_to_shopping=<?= $recipe_id ?>&servings=<?= $desired_servings ?>">
                            🛍️ Add the missing ingredients for <?= $desired_servings ?> servings to your Shopping List
                        </a>
                    <?php endif; ?>
                </div>
                <br>
                <a href="shopping.php" class="back-btn">🛍️ Go to Shopping List</a>
            </div>


            <?php else: ?>

            <!-- INGREDIENTS -->
            <div class="ingredients-box">
                <div class="section-title">🥘 Ingredients <span style="font-size:0.75rem;color:var(--text-soft);font-weight:400;" id="ingredientNote"></span></div>
                <div class="ingredient-list" id="ingredientList">
                    <?php foreach ($ingredients as $ing): ?>
                        <div class="ingredient have"
                             data-base-qty="<?= $ing['required_qty'] / $serving_multiplier ?>"
                             data-unit="<?= htmlspecialchars($ing['unit']) ?>">
                            ✅ <?= htmlspecialchars($ing['name']) ?>
                            — <span class="ing-qty"><?= number_format($ing['required_qty'], 2) ?></span>
                            <?= htmlspecialchars($ing['unit']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="stock-note">
                    ℹ️ Ingredients will be deducted from your purchased Shopping List when cooking is completed.
                </div>
            </div>


            <!-- COOKING CARD -->
            <div class="cooking-card">

                <div class="progress-container">
                    <div class="progress-info">
                        <span id="stepCounter">Step 1 of <?= $total_steps ?></span>
                        <span id="progressPercent">14%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                </div>

                <div class="step-content" id="stepContent">
                    <div class="step-number" id="stepNumber">1</div>
                    <div class="step-label">Cooking Step</div>
                    <div class="step-text" id="stepText">
                        <?= htmlspecialchars($instructions[0]) ?>
                    </div>
                </div>

                <div class="finish-screen" id="finishScreen">
                    <div class="finish-icon">🎉</div>
                    <div class="finish-title">Recipe Completed!</div>
                    <div class="finish-text">
                        Your <strong><?= htmlspecialchars($recipe['name']) ?></strong> is ready.
                        Enjoy your meal! 😋
                    </div>
                    <a href="recipes.php" class="back-btn">← Back to Recipes</a>
                </div>

                <div class="cook-error" id="cookError"></div>

                <div class="cook-controls" id="cookControls">
                    <button type="button" class="cook-btn" id="prevBtn" onclick="previousStep()" disabled>
                        ← Previous
                    </button>
                    <button type="button" class="cook-btn primary" id="nextBtn" onclick="nextStep()">
                        Next →
                    </button>
                </div>

            </div>

            <?php endif; ?>

        </div>
    </main>
</div>


<script>

const steps       = <?= json_encode(array_values($instructions), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const recipeId    = <?= (int)$recipe_id ?>;
const baseServings= <?= $base_servings ?>;

let currentStep      = 0;
let finishing        = false;
let currentServings  = <?= $desired_servings ?>;   // starts at whatever was chosen on recipes.php
let servingMultiplier= <?= $serving_multiplier ?>; // matches the server-side check above

const stepNumber      = document.getElementById('stepNumber');
const stepText        = document.getElementById('stepText');
const stepCounter     = document.getElementById('stepCounter');
const progressFill    = document.getElementById('progressFill');
const progressPercent = document.getElementById('progressPercent');
const prevBtn         = document.getElementById('prevBtn');
const nextBtn         = document.getElementById('nextBtn');
const cookControls    = document.getElementById('cookControls');
const stepContent     = document.getElementById('stepContent');
const finishScreen    = document.getElementById('finishScreen');
const cookError       = document.getElementById('cookError');
const servingDisplay  = document.getElementById('servingDisplay');
const servesDisplay   = document.getElementById('servesDisplay');
const multiplierBadge = document.getElementById('multiplierBadge');
const ingredientNote  = document.getElementById('ingredientNote');
const servingDown     = document.getElementById('servingDown');


// ── Serving Adjuster ────────────────────────────────────────

function adjustServing(delta) {
    const newServings = currentServings + delta;
    if (newServings < 1) return;

    currentServings   = newServings;
    servingMultiplier = currentServings / baseServings;

    // Update displays
    servingDisplay.textContent = currentServings + ' servings';
    if (servesDisplay) servesDisplay.textContent = currentServings;

    // Show/hide multiplier badge
    if (servingMultiplier !== 1) {
        multiplierBadge.style.display = 'inline-block';
        multiplierBadge.textContent   = (servingMultiplier % 1 === 0
            ? servingMultiplier
            : servingMultiplier.toFixed(2)) + '×';
    } else {
        multiplierBadge.style.display = 'none';
    }

    // Update ingredient quantities
    document.querySelectorAll('#ingredientList .ingredient').forEach(function(el) {
        const baseQty = parseFloat(el.dataset.baseQty);
        const newQty  = baseQty * servingMultiplier;
        el.querySelector('.ing-qty').textContent = newQty % 1 === 0
            ? newQty.toFixed(0)
            : newQty.toFixed(2);
    });

    // Note
    if (ingredientNote) {
        ingredientNote.textContent = servingMultiplier !== 1
            ? '(adjusted for ' + currentServings + ' servings)'
            : '';
    }

    // Disable minus at 1
    if (servingDown) servingDown.disabled = (currentServings <= 1);
}


// ── Step Navigation ─────────────────────────────────────────

function updateStep() {
    stepNumber.textContent  = currentStep + 1;
    stepText.textContent    = steps[currentStep];
    stepCounter.textContent = `Step ${currentStep + 1} of ${steps.length}`;

    const percent = Math.round(((currentStep + 1) / steps.length) * 100);
    progressFill.style.width    = percent + '%';
    progressPercent.textContent = percent + '%';

    prevBtn.disabled    = (currentStep === 0);
    nextBtn.textContent = (currentStep === steps.length - 1) ? '✅ Finish Cooking' : 'Next →';
}

function nextStep() {
    if (finishing) return;
    if (currentStep < steps.length - 1) {
        currentStep++;
        updateStep();
    } else {
        finishCooking();
    }
}

function previousStep() {
    if (finishing || currentStep <= 0) return;
    currentStep--;
    updateStep();
}


// ── Finish Cooking (AJAX) ────────────────────────────────────

async function finishCooking() {
    if (finishing) return;
    finishing = true;

    nextBtn.disabled    = true;
    prevBtn.disabled    = true;
    nextBtn.textContent = '⏳ Processing...';
    cookError.style.display = 'none';

    try {
        const formData = new FormData();
        formData.append('action',             'finish_cooking');
        formData.append('recipe_id',          recipeId);
        formData.append('serving_multiplier', servingMultiplier);

        const response = await fetch('cook.php?recipe_id=' + recipeId, {
            method: 'POST', body: formData, credentials: 'same-origin'
        });

        const data = await response.json();

        if (!data.success) throw new Error(data.message || 'Unable to finish cooking.');

        stepContent.style.display  = 'none';
        cookControls.style.display = 'none';
        finishScreen.style.display = 'block';
        progressFill.style.width    = '100%';
        progressPercent.textContent = '100%';
        stepCounter.textContent     = 'Completed';

    } catch (error) {
        cookError.textContent   = '❌ ' + error.message;
        cookError.style.display = 'block';
        nextBtn.disabled    = false;
        prevBtn.disabled    = (currentStep === 0);
        nextBtn.textContent = '✅ Finish Cooking';
        finishing           = false;
    }
}


// ── Mobile sidebar ───────────────────────────────────────────

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


// ── Init ─────────────────────────────────────────────────────

updateStep();
if (servingDown) servingDown.disabled = (currentServings <= 1); // reflects the servings we actually started at

</script>

</body>
</html>