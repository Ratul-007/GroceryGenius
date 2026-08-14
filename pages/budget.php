<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$current_month = date('Y-m');
$message = '';
$error = '';

// Create or update the budget limit for the current month.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_budget'])) {
    $limit_amount = isset($_POST['limit_amount']) ? trim($_POST['limit_amount']) : '';

    if ($limit_amount === '' || !is_numeric($limit_amount) || (float) $limit_amount <= 0) {
        $error = 'Please enter a valid budget amount greater than zero.';
    } else {
        $limit_amount = (float) $limit_amount;

        $check = $pdo->prepare(
            'SELECT budget_id FROM budget WHERE user_id = :user_id AND month = :month LIMIT 1'
        );
        $check->execute([
            ':user_id' => $user_id,
            ':month' => $current_month
        ]);
        $existing_budget = $check->fetch();

        if ($existing_budget) {
            $stmt = $pdo->prepare(
                'UPDATE budget SET limit_amount = :limit_amount
                 WHERE budget_id = :budget_id AND user_id = :user_id'
            );
            $stmt->execute([
                ':limit_amount' => $limit_amount,
                ':budget_id' => (int) $existing_budget['budget_id'],
                ':user_id' => $user_id
            ]);
            $message = 'Monthly budget updated successfully.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO budget (user_id, month, limit_amount, spent_amount)
                 VALUES (:user_id, :month, :limit_amount, 0)'
            );
            $stmt->execute([
                ':user_id' => $user_id,
                ':month' => $current_month,
                ':limit_amount' => $limit_amount
            ]);
            $message = 'Monthly budget saved successfully.';
        }
    }
}

// Add an expense to the current month's spent amount.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    $expense_amount = isset($_POST['expense_amount']) ? trim($_POST['expense_amount']) : '';

    if ($expense_amount === '' || !is_numeric($expense_amount) || (float) $expense_amount <= 0) {
        $error = 'Please enter a valid expense amount greater than zero.';
    } else {
        $expense_amount = (float) $expense_amount;

        $budget_stmt = $pdo->prepare(
            'SELECT budget_id, limit_amount, spent_amount
             FROM budget
             WHERE user_id = :user_id AND month = :month
             LIMIT 1'
        );
        $budget_stmt->execute([
            ':user_id' => $user_id,
            ':month' => $current_month
        ]);
        $current_budget = $budget_stmt->fetch();

        if (!$current_budget) {
            $error = 'Please set your monthly budget before adding an expense.';
        } else {
            $new_spent = (float) $current_budget['spent_amount'] + $expense_amount;

            $stmt = $pdo->prepare(
                'UPDATE budget SET spent_amount = :spent_amount
                 WHERE budget_id = :budget_id AND user_id = :user_id'
            );
            $stmt->execute([
                ':spent_amount' => $new_spent,
                ':budget_id' => (int) $current_budget['budget_id'],
                ':user_id' => $user_id
            ]);
            $message = 'Expense added successfully.';
        }
    }
}

// Load the current month's budget.
$budget_stmt = $pdo->prepare(
    'SELECT budget_id, month, limit_amount, spent_amount
     FROM budget
     WHERE user_id = :user_id AND month = :month
     LIMIT 1'
);
$budget_stmt->execute([
    ':user_id' => $user_id,
    ':month' => $current_month
]);
$budget = $budget_stmt->fetch();

$spent = $budget ? (float) $budget['spent_amount'] : 0;
$limit = $budget ? (float) $budget['limit_amount'] : 0;
$pct = $limit > 0 ? ($spent / $limit) * 100 : 0;
$display_pct = round($pct);
$bar_width = min(max($pct, 0), 100);
$color = $pct < 60 ? '#4ade80' : ($pct < 85 ? '#facc15' : '#f87171');
$remaining = $limit - $spent;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Tracker - GroceryGenius</title>
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
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 240px;
            height: 100vh;
            padding: 24px 16px;
            background: var(--sidebar);
            border-right: 1px solid var(--border);
            z-index: 1000;
        }
        .brand { padding: 4px 12px 24px; font-size: 23px; font-weight: 700; }
        .brand span { color: var(--purple-light); }
        .nav a {
            display: block;
            padding: 12px 14px;
            margin: 5px 0;
            color: var(--muted);
            text-decoration: none;
            border-radius: 9px;
            transition: .2s;
        }
        .nav a:hover, .nav a.active { background: #24113e; color: #fff; }
        .nav a.active { border-left: 3px solid var(--purple); }
        .logout { margin-top: 24px; border-top: 1px solid var(--border); padding-top: 16px; }
        .logout a { color: #f87171; }

        .main {
            margin-left: 240px;
            min-height: 100vh;
            padding: 34px;
        }
        .container { max-width: 1100px; margin: 0 auto; }
        .page-header { margin-bottom: 25px; }
        .page-header h1 { margin: 0 0 7px; font-size: 30px; }
        .page-header p { margin: 0; color: var(--muted); }

        .notice, .error {
            padding: 13px 16px;
            border-radius: 9px;
            margin-bottom: 18px;
        }
        .notice { background: #123421; border: 1px solid #246b3e; color: #86efac; }
        .error { background: #3a151d; border: 1px solid #7f1d2d; color: #fca5a5; }

        .grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 22px;
        }
        .stat-card, .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 22px;
        }
        .stat-label { color: var(--muted); font-size: 14px; margin-bottom: 8px; }
        .stat-value { font-size: 25px; font-weight: 700; }
        .remaining-positive { color: #4ade80; }
        .remaining-negative { color: #f87171; }

        .card { margin-bottom: 22px; }
        .card h2 { margin: 0 0 18px; font-size: 20px; }
        .form-row { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: end; }
        .form-group label { display: block; margin-bottom: 7px; color: var(--muted); font-size: 14px; }
        input[type="number"] {
            width: 100%;
            padding: 12px 13px;
            background: #10091d;
            color: var(--text);
            border: 1px solid #4b2670;
            border-radius: 8px;
            outline: none;
        }
        input[type="number"]:focus { border-color: var(--purple); }
        .btn {
            padding: 12px 17px;
            border: 0;
            border-radius: 8px;
            background: var(--purple);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }
        .btn:hover { opacity: .9; }

        .progress-card { margin-bottom: 22px; }
        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 13px;
        }
        .progress-header h2 { margin: 0; }
        .progress-percent { color: var(--purple-light); font-weight: 700; }
        .budget-bar-wrap {
            width: 100%;
            height: 31px;
            background: #291542;
            border-radius: 999px;
            overflow: hidden;
        }
        .budget-bar {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 10px;
            min-width: 0;
            border-radius: 999px;
            color: #171020;
            font-weight: 700;
            transition: width .4s ease, background .4s ease;
        }
        .spent-text { color: var(--muted); margin: 13px 0 0; }
        .warning { margin-top: 12px; color: #f87171; font-weight: 700; }

        .mobile-menu {
            display: none;
            position: fixed;
            top: 14px;
            left: 14px;
            z-index: 1100;
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--card);
            color: #fff;
            cursor: pointer;
        }

        @media (max-width: 850px) {
            .sidebar { transform: translateX(-100%); transition: transform .25s ease; }
            .sidebar.open { transform: translateX(0); }
            .mobile-menu { display: block; }
            .main { margin-left: 0; padding: 75px 18px 25px; }
            .grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
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
        <a href="budget.php" class="active">Budget</a>
        <a href="prices.php">Price Tracker</a>
    </nav>
    <div class="logout nav">
        <a href="logout.php">Logout</a>
    </div>
</aside>

<main class="main">
    <div class="container">
        <div class="page-header">
            <h1>Budget Tracker</h1>
            <p>Manage your grocery budget and keep track of your monthly spending.</p>
        </div>

        <?php if ($message): ?>
            <div class="notice"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="grid">
            <div class="stat-card">
                <div class="stat-label">Monthly Budget</div>
                <div class="stat-value">৳<?php echo number_format($limit, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Spent</div>
                <div class="stat-value">৳<?php echo number_format($spent, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Remaining</div>
                <div class="stat-value <?php echo $remaining < 0 ? 'remaining-negative' : 'remaining-positive'; ?>">
                    ৳<?php echo number_format($remaining, 2); ?>
                </div>
            </div>
        </div>

        <section class="card">
            <h2><?php echo $budget ? 'Update Monthly Budget' : 'Set Monthly Budget'; ?></h2>
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label for="limit_amount">Budget limit for <?php echo htmlspecialchars(date('F Y')); ?></label>
                        <input type="number" id="limit_amount" name="limit_amount" min="0.01" step="0.01" value="<?php echo $limit > 0 ? htmlspecialchars($limit) : ''; ?>" placeholder="e.g. 10000" required>
                    </div>
                    <button class="btn" type="submit" name="save_budget">Save Budget</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Add Expense</h2>
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label for="expense_amount">Expense amount</label>
                        <input type="number" id="expense_amount" name="expense_amount" min="0.01" step="0.01" placeholder="e.g. 500" required>
                    </div>
                    <button class="btn" type="submit" name="add_expense">Add Expense</button>
                </div>
            </form>
        </section>

        <section class="card progress-card">
            <div class="progress-header">
                <h2>Budget Progress</h2>
                <span class="progress-percent"><?php echo $display_pct; ?>%</span>
            </div>

            <div class="budget-bar-wrap">
                <div class="budget-bar" style="width: <?php echo $bar_width; ?>%; background: <?php echo $color; ?>;">
                    <?php echo $display_pct; ?>%
                </div>
            </div>

            <p class="spent-text">
                Spent: ৳<?php echo number_format($spent, 2); ?> / ৳<?php echo number_format($limit, 2); ?>
            </p>

            <?php if ($limit > 0 && $spent > $limit): ?>
                <div class="warning">⚠ You have exceeded your monthly budget by ৳<?php echo number_format(abs($remaining), 2); ?>.</div>
            <?php elseif ($limit > 0 && $pct >= 85): ?>
                <div class="warning">⚠ You are close to your monthly budget limit.</div>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
