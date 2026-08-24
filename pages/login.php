<?php
session_start();
require_once '../config/db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']    = $user['user_id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['is_admin']   = !empty($user['is_admin']) ? 1 : 0;
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid email or password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login — GroceryGenius</title>
  <link rel="stylesheet" href="../assets/css/style.css"/>
  <style>
    body {
      display: flex; align-items: center; justify-content: center;
      min-height: 100vh;
      background: var(--bg-dark);
      background-image:
        radial-gradient(ellipse at 20% 20%, rgba(92,33,182,0.25) 0%, transparent 60%),
        radial-gradient(ellipse at 80% 80%, rgba(232,121,249,0.1) 0%, transparent 60%);
    }
    .auth-wrapper { width: 100%; max-width: 420px; padding: 20px; }
    .auth-logo { text-align: center; margin-bottom: 28px; }
    .auth-logo .emoji { font-size: 2.8rem; display: block; margin-bottom: 8px; }
    .auth-logo h1 { font-size: 1.8rem; font-weight: 800; color: var(--text-main); }
    .auth-logo p  { font-size: 0.85rem; color: var(--text-muted); margin-top: 4px; }

    .auth-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 32px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.4), 0 0 0 1px rgba(168,85,247,0.1);
    }
    .auth-card h2 { font-size: 1.2rem; font-weight: 700; color: var(--text-main); margin-bottom: 6px; }
    .auth-card .subtitle { font-size: 0.83rem; color: var(--text-muted); margin-bottom: 24px; }

    .divider {
      text-align: center; margin: 20px 0;
      position: relative; color: var(--text-soft); font-size: 0.8rem;
    }
    .divider::before, .divider::after {
      content: ''; position: absolute; top: 50%; width: 42%;
      height: 1px; background: var(--border);
    }
    .divider::before { left: 0; } .divider::after { right: 0; }

    .auth-footer { text-align: center; margin-top: 20px; font-size: 0.84rem; color: var(--text-muted); }

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
  </style>
</head>
<body>
  <div class="auth-wrapper">

    <div class="auth-logo">
      <span class="emoji">🛒</span>
      <h1>GroceryGenius</h1>
      <p>Smart grocery management & meal planning</p>
    </div>

    <div class="auth-card">
      <h2>Welcome back</h2>
      <p class="subtitle">Sign in to your account to continue</p>

      <?php if ($error): ?>
        <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="">

        <div class="form-group">
          <label for="email">Email Address</label>
          <div class="input-icon-wrap">
            <span class="input-icon">📧</span>
            <input
              type="email" id="email" name="email"
              class="form-control"
              placeholder="you@example.com"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
              required autofocus
            />
          </div>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <div class="input-icon-wrap">
            <span class="input-icon">🔒</span>
            <input
              type="password" id="loginPass" name="password"
              class="form-control"
              placeholder="Enter your password"
              required
            />
            <button type="button" class="pass-toggle" onclick="togglePass('loginPass', this)">👁️</button>
          </div>
        </div>

        <div style="margin-bottom:20px">
          <button type="submit" class="btn btn-primary">Sign In →</button>
        </div>

      </form>

      <div class="divider">or</div>

      <a href="register.php" class="btn btn-outline">Create a new account</a>

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