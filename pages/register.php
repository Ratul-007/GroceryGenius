<?php
session_start();
require_once '../config/db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($name) || empty($email) || empty($password) || empty($confirm)) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'This email is already registered. Please log in instead.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$name, $email, $hash]);
            $success = 'Account created! Redirecting to login...';
            header('Refresh: 2; URL=login.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Register — GroceryGenius</title>
  <link rel="stylesheet" href="../assets/css/style.css"/>
  <style>
    body {
      display: flex; align-items: center; justify-content: center;
      min-height: 100vh; padding: 20px;
      background: var(--bg-dark);
      background-image:
        radial-gradient(ellipse at 80% 10%, rgba(92,33,182,0.25) 0%, transparent 60%),
        radial-gradient(ellipse at 20% 90%, rgba(232,121,249,0.1) 0%, transparent 60%);
    }
    .auth-wrapper { width: 100%; max-width: 440px; padding: 20px; }
    .auth-logo { text-align: center; margin-bottom: 24px; }
    .auth-logo .emoji { font-size: 2.4rem; display: block; margin-bottom: 6px; }
    .auth-logo h1 { font-size: 1.6rem; font-weight: 800; color: var(--text-main); }
    .auth-logo p  { font-size: 0.83rem; color: var(--text-muted); margin-top: 3px; }

    .auth-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 32px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.4), 0 0 0 1px rgba(168,85,247,0.1);
    }
    .auth-card h2 { font-size: 1.2rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; }
    .auth-card .subtitle { font-size: 0.83rem; color: var(--text-muted); margin-bottom: 22px; }

    /* ── Input with icon ── */
    .input-icon-wrap { position: relative; }
    .input-icon-wrap .form-control { padding-left: 40px; padding-right: 42px; }
    .input-icon {
      position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
      font-size: 1rem; pointer-events: none;
    }

    /* ── Password toggle ── */
    .pass-toggle {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer;
      color: var(--text-muted); font-size: 1rem; padding: 0; line-height: 1;
    }
    .pass-toggle:hover { color: var(--purple-300); }

    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

    .divider {
      text-align: center; margin: 18px 0;
      position: relative; color: var(--text-soft); font-size: 0.8rem;
    }
    .divider::before, .divider::after {
      content: ''; position: absolute; top: 50%; width: 42%;
      height: 1px; background: var(--border);
    }
    .divider::before { left: 0; } .divider::after { right: 0; }

    .auth-footer { text-align: center; margin-top: 18px; font-size: 0.83rem; color: var(--text-muted); }
    .password-hint { font-size: 0.75rem; color: var(--text-soft); margin-top: 4px; }
  </style>
</head>
<body>
  <div class="auth-wrapper">

    <div class="auth-logo">
      <span class="emoji">🛒</span>
      <h1>GroceryGenius</h1>
      <p>Create your free account</p>
    </div>

    <div class="auth-card">
      <h2>Get started</h2>
      <p class="subtitle">Join GroceryGenius and take control of your groceries</p>

      <?php if ($error): ?>
        <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <form method="POST" action="">

        <div class="form-group">
          <label for="name">Full Name</label>
          <div class="input-icon-wrap">
            <span class="input-icon">👤</span>
            <input
              type="text" id="name" name="name"
              class="form-control" placeholder="Your full name"
              value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
              required autofocus
            />
          </div>
        </div>

        <div class="form-group">
          <label for="email">Email Address</label>
          <div class="input-icon-wrap">
            <span class="input-icon">📧</span>
            <input
              type="email" id="email" name="email"
              class="form-control" placeholder="you@example.com"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
              required
            />
          </div>
        </div>

        <div class="two-col">
          <div class="form-group">
            <label for="password">Password</label>
            <div class="input-icon-wrap">
              <span class="input-icon">🔒</span>
              <input
                type="password" id="regPass" name="password"
                class="form-control" placeholder="Min 6 chars"
                required
              />
              <button type="button" class="pass-toggle" onclick="togglePass('regPass', this)">👁️</button>
            </div>
            <p class="password-hint">At least 6 characters</p>
          </div>
          <div class="form-group">
            <label for="confirm_password">Confirm</label>
            <div class="input-icon-wrap">
              <span class="input-icon">🔐</span>
              <input
                type="password" id="regConfirm" name="confirm_password"
                class="form-control" placeholder="Repeat password"
                required
              />
              <button type="button" class="pass-toggle" onclick="togglePass('regConfirm', this)">👁️</button>
            </div>
          </div>
        </div>

        <div style="margin-bottom:16px">
          <button type="submit" class="btn btn-primary">Create Account →</button>
        </div>

      </form>

      <div class="divider">already have an account?</div>

      <a href="login.php" class="btn btn-outline">Sign In Instead</a>

    </div>

    <div class="auth-footer">
      Team: 404 Team Not Found &nbsp;·&nbsp; GroceryGenius
    </div>

  </div>

  <script>
  function togglePass(id, btn) {
      const input = document.getElementById(id);
      if (input.type === 'password') {
          input.type = 'text';
          btn.textContent = '🙈';
      } else {
          input.type = 'password';
          btn.textContent = '👁️';
      }
  }
  </script>
</body>
</html>
