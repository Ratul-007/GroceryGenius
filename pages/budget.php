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
        $check->execute([':user_id' => $user_id, ':month' => $current_month]);
        $existing_budget = $check->fetch();

        if ($existing_budget) {
            $stmt = $pdo->prepare(
                'UPDATE budget SET limit_amount = :limit_amount
                 WHERE budget_id = :budget_id AND user_id = :user_id'
            );
            $stmt->execute([
                ':limit_amount' => $limit_amount,
                ':budget_id'    => (int) $existing_budget['budget_id'],
                ':user_id'      => $user_id
            ]);
            $message = 'Monthly budget updated successfully.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO budget (user_id, month, limit_amount, spent_amount)
                 VALUES (:user_id, :month, :limit_amount, 0)'
            );
            $stmt->execute([
                ':user_id'      => $user_id,
                ':month'        => $current_month,
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
             FROM budget WHERE user_id = :user_id AND month = :month LIMIT 1'
        );
        $budget_stmt->execute([':user_id' => $user_id, ':month' => $current_month]);
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
                ':budget_id'    => (int) $current_budget['budget_id'],
                ':user_id'      => $user_id
            ]);
            $message = 'Expense added successfully.';
        }
    }
}

// Load the current month's budget.
$budget_stmt = $pdo->prepare(
    'SELECT budget_id, month, limit_amount, spent_amount
     FROM budget WHERE user_id = :user_id AND month = :month LIMIT 1'
);
$budget_stmt->execute([':user_id' => $user_id, ':month' => $current_month]);
$budget = $budget_stmt->fetch();

$spent       = $budget ? (float) $budget['spent_amount'] : 0;
$limit       = $budget ? (float) $budget['limit_amount'] : 0;
$pct         = $limit > 0 ? ($spent / $limit) * 100 : 0;
$display_pct = round($pct);
$bar_width   = min(max($pct, 0), 100);
$color       = $pct < 60 ? '#10b981' : ($pct < 85 ? '#f59e0b' : '#ef4444');
$remaining   = $limit - $spent;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Tracker — GroceryGenius</title>
    <link rel="stylesheet" href="../assets/css/style.css"/>
    <style>
        .remaining-positive { color: var(--success); }
        .remaining-negative { color: var(--danger); }

        .form-row { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: end; }

        .card + .card { margin-top: 20px; }
        .card h2 { margin: 0 0 18px; font-size: 1.1rem; color: var(--text-main); }

        .progress-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 13px;
        }
        .progress-header h2 { margin: 0; }
        .progress-percent { color: var(--purple-300); font-weight: 700; }

        .budget-bar-wrap {
            width: 100%; height: 31px;
            background: rgba(61,18,120,0.3);
            border-radius: 999px; overflow: hidden;
        }
        .budget-bar {
            height: 100%; display: flex; align-items: center; justify-content: flex-end;
            padding-right: 10px; min-width: 0; border-radius: 999px;
            color: #171020; font-weight: 700;
            transition: width 0.4s ease, background 0.4s ease;
        }
        .spent-text { color: var(--text-muted); margin: 13px 0 0; }
        .warning { margin-top: 12px; color: var(--danger); font-weight: 700; }

        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
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
            <a href="budget.php" class="nav-item active"><span class="nav-icon">💰</span> Budget</a>
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

        <div class="page-header">
            <div class="page-title">💰 Budget Tracker</div>
            <div class="page-sub">Manage your grocery budget and keep track of your monthly spending.</div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">✅ <?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon purple">💰</div>
                <div>
                    <div class="stat-val">৳<?= number_format($limit, 2) ?></div>
                    <div class="stat-label">Monthly Budget</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange">🧾</div>
                <div>
                    <div class="stat-val">৳<?= number_format($spent, 2) ?></div>
                    <div class="stat-label">Spent</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon <?= $remaining < 0 ? 'red' : 'green' ?>">
                    <?= $remaining < 0 ? '⚠️' : '✅' ?>
                </div>
                <div>
                    <div class="stat-val <?= $remaining < 0 ? 'remaining-negative' : 'remaining-positive' ?>">
                        ৳<?= number_format($remaining, 2) ?>
                    </div>
                    <div class="stat-label">Remaining</div>
                </div>
            </div>
        </div>

        <section class="card">
            <h2><?= $budget ? 'Update Monthly Budget' : 'Set Monthly Budget' ?></h2>
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label for="limit_amount">Budget limit for <?= htmlspecialchars(date('F Y')) ?></label>
                        <input type="number" id="limit_amount" name="limit_amount" class="form-control"
                               min="0.01" step="0.01"
                               value="<?= $limit > 0 ? htmlspecialchars($limit) : '' ?>"
                               placeholder="e.g. 10000" required>
                    </div>
                    <button class="btn btn-primary" type="submit" name="save_budget">Save Budget</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Add Expense</h2>
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label for="expense_amount">Expense amount</label>
                        <input type="number" id="expense_amount" name="expense_amount" class="form-control"
                               min="0.01" step="0.01" placeholder="e.g. 500" required>
                    </div>
                    <button class="btn btn-primary" type="submit" name="add_expense">Add Expense</button>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="progress-header">
                <h2>Budget Progress</h2>
                <span class="progress-percent"><?= $display_pct ?>%</span>
            </div>

            <div class="budget-bar-wrap">
                <div class="budget-bar" style="width: <?= $bar_width ?>%; background: <?= $color ?>;">
                    <?= $display_pct ?>%
                </div>
            </div>

            <p class="spent-text">
                Spent: ৳<?= number_format($spent, 2) ?> / ৳<?= number_format($limit, 2) ?>
            </p>

            <?php if ($limit > 0 && $spent > $limit): ?>
                <div class="warning">⚠ You have exceeded your monthly budget by ৳<?= number_format(abs($remaining), 2) ?>.</div>
            <?php elseif ($limit > 0 && $pct >= 85): ?>
                <div class="warning">⚠ You are close to your monthly budget limit.</div>
            <?php endif; ?>
        </section>

    </main>
</div>

<script>
document.addEventListener('click', function(e) {
    const sidebar   = document.querySelector('.sidebar');
    const hamburger = document.querySelector('.hamburger');
    if (window.innerWidth <= 768 && !sidebar.contains(e.target) && !hamburger.contains(e.target)) {
        sidebar.classList.remove('open');
    }
});

// ── Budget Alert Sounds ──────────────────────────────────────
(function () {
    const hasBudget = <?= $limit > 0 ? 'true' : 'false' ?>;
    const budgetPct = <?= $limit > 0 ? json_encode(round($pct, 2)) : 0 ?>;
    const exceeded  = <?= ($limit > 0 && $spent > $limit) ? 'true' : 'false' ?>;

    let audioCtx = null;
    let played   = false;

    function getCtx() {
        if (!audioCtx) {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (AC) audioCtx = new AC();
        }
        return audioCtx;
    }

    // 🟡 Amber warning — soft single beep
    function playWarningBeep() {
        const ctx = getCtx();
        if (!ctx) return;
        const osc  = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = 520;
        gain.gain.setValueAtTime(0.3, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 0.6);
    }

    // 🔴 Red alert — deep air horn
    function playAlertBeep() {
        const ctx = getCtx();
        if (!ctx) return;

        const osc1 = ctx.createOscillator();
        const osc2 = ctx.createOscillator();
        const gain = ctx.createGain();

        osc1.type = 'sawtooth';
        osc1.frequency.setValueAtTime(120, ctx.currentTime);
        osc1.frequency.linearRampToValueAtTime(110, ctx.currentTime + 0.4);

        osc2.type = 'sawtooth';
        osc2.frequency.setValueAtTime(124, ctx.currentTime);

        osc1.connect(gain);
        osc2.connect(gain);
        gain.connect(ctx.destination);

        gain.gain.setValueAtTime(0, ctx.currentTime);
        gain.gain.linearRampToValueAtTime(0.4, ctx.currentTime + 0.02);
        gain.gain.setValueAtTime(0.4, ctx.currentTime + 0.30);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.45);

        osc1.start(ctx.currentTime);
        osc2.start(ctx.currentTime);
        osc1.stop(ctx.currentTime + 0.45);
        osc2.stop(ctx.currentTime + 0.45);
    }

    function doPlay() {
        if (exceeded || budgetPct >= 85) {
            playAlertBeep();
        } else if (budgetPct >= 60) {
            playWarningBeep();
        }
    }

    // IMPORTANT: only mark `played = true` once the AudioContext is actually
    // running. On Safari/Firefox, ctx.resume() on page load (no user gesture)
    // will silently fail to bring the context out of "suspended" state.
    // If we mark `played = true` too early, the click/keydown/touchstart
    // fallback listeners below never get a chance to fire, and the sound
    // never plays on those browsers. Chrome/Edge often resume() successfully
    // even without a gesture, which is why they "just worked" before.
    function maybePlay() {
        if (played || !hasBudget) return;
        const ctx = getCtx();
        if (!ctx) return;

        if (ctx.state === 'suspended') {
            ctx.resume().then(function () {
                played = true;
                setTimeout(doPlay, 400);
            }).catch(function () {
                // resume() failed — leave `played` false so a later user
                // gesture (click/keydown/touchstart) can still trigger it.
            });
        } else {
            played = true;
            setTimeout(doPlay, 400);
        }
    }

    // Try on load
    maybePlay();

    // Fallback — fire on first user interaction (this is what actually
    // unlocks audio on Safari/Firefox/iOS)
    ['click', 'keydown', 'touchstart'].forEach(function (evt) {
        document.addEventListener(evt, maybePlay, { once: true });
    });
})();
</script>

</body>
</html>