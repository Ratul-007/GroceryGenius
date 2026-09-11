<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id       = (int)$_SESSION['user_id'];
$success       = '';
if (isset($_GET['msg']) && $_GET['msg'] === 'password_changed') {
    $success = 'Password changed successfully!';
}
$error         = '';


// ============================================================
// FETCH CURRENT USER (include password for verification)
// ============================================================

$stmt = $pdo->prepare("SELECT user_id, name, email, phone, profile_photo, password, created_at FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: logout.php');
    exit;
}


// ============================================================
// HANDLE PROFILE PHOTO UPLOAD
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_photo') {

    if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please select a valid image file.';
    } else {
        $file     = $_FILES['profile_photo'];
        $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $max_size = 2 * 1024 * 1024;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed)) {
            $error = 'Only JPG, PNG, WEBP, and GIF images are allowed.';
        } elseif ($file['size'] > $max_size) {
            $error = 'Image must be under 2MB.';
        } else {
            $upload_dir = '../assets/uploads/avatars/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'avatar_' . $user_id . '_' . time() . '.' . strtolower($ext);
            $dest     = $upload_dir . $filename;

            if (move_uploaded_file($file['tmp_name'], $dest)) {
                if (!empty($user['profile_photo'])) {
                    $old = '../assets/uploads/avatars/' . $user['profile_photo'];
                    if (file_exists($old)) unlink($old);
                }
                $pdo->prepare("UPDATE users SET profile_photo = ? WHERE user_id = ?")->execute([$filename, $user_id]);
                $user['profile_photo'] = $filename;
                $success = 'Profile photo updated successfully!';
            } else {
                $error = 'Failed to upload image. Please try again.';
            }
        }
    }
}


// ============================================================
// HANDLE PROFILE INFO UPDATE
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_info') {

    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (empty($name) || empty($email)) {
        $error = 'Name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $check->execute([$email, $user_id]);
        if ($check->fetch()) {
            $error = 'This email is already used by another account.';
        } else {
            $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ? WHERE user_id = ?")
                ->execute([$name, $email, $phone ?: null, $user_id]);
            $_SESSION['user_name'] = $name;
            $user['name']  = $name;
            $user['email'] = $email;
            $user['phone'] = $phone;
            $success = 'Profile updated successfully!';
        }
    }
}


// ============================================================
// HANDLE PASSWORD CHANGE
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {

    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (empty($current) || empty($new) || empty($confirm)) {
        $error = 'All password fields are required.';
    } elseif (!password_verify($current, $user['password'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?")->execute([$hashed, $user_id]);
        header('Location: profile.php?msg=password_changed');
exit;
    }
}

$avatar_letter = strtoupper(substr($user['name'], 0, 1));
$photo_url     = !empty($user['profile_photo'])
    ? '../assets/uploads/avatars/' . htmlspecialchars($user['profile_photo'])
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Profile — GroceryGenius</title>
    <link rel="stylesheet" href="../assets/css/style.css"/>
    <style>

        .profile-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 24px;
            align-items: start;
        }

        /* ── PHOTO CARD ── */
        .photo-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 28px 20px;
            text-align: center;
        }

        .avatar-wrap {
            position: relative;
            width: 110px;
            height: 110px;
            margin: 0 auto 16px;
        }

        .avatar-img {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--purple-500);
        }

        .avatar-placeholder {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--purple-500), var(--accent));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: 800;
            color: #fff;
            border: 3px solid var(--purple-500);
        }

        .avatar-edit-btn {
            position: absolute;
            bottom: 4px;
            right: 4px;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--purple-500);
            border: 2px solid var(--bg-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 0.75rem;
            transition: background 0.2s;
        }

        .avatar-edit-btn:hover { background: var(--purple-400); }

        .profile-name    { font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; }
        .profile-email   { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 4px; }
        .profile-joined  { font-size: 0.75rem; color: var(--text-soft); margin-bottom: 20px; }

        .photo-upload-form { margin-top: 8px; }

        .photo-input-label {
            display: inline-block;
            padding: 8px 16px;
            background: rgba(124,58,237,0.15);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--purple-300);
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            width: 100%;
        }

        .photo-input-label:hover {
            background: rgba(124,58,237,0.25);
            border-color: var(--purple-400);
        }

        #photo_preview_name {
            font-size: 0.72rem;
            color: var(--text-soft);
            margin-top: 6px;
            display: block;
        }

        .upload-btn {
            display: none;
            width: 100%;
            margin-top: 8px;
            padding: 8px;
            background: var(--purple-600);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
        }

        .upload-btn:hover { opacity: 0.9; }

        /* ── RIGHT SIDE ── */
        .right-side { display: flex; flex-direction: column; gap: 20px; }

        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 22px 24px;
        }

        .section-card h3 {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 18px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }

        .form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .form-group { margin-bottom: 14px; }

        .form-group label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 6px;
            letter-spacing: 0.03em;
        }

        .form-group input {
            width: 100%;
            padding: 10px 13px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-main);
            font-size: 0.88rem;
            font-family: 'Inter', sans-serif;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--purple-400);
            box-shadow: 0 0 0 3px rgba(168,85,247,0.12);
        }

        .save-btn {
            padding: 10px 22px;
            background: var(--purple-600);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }

        .save-btn:hover { background: var(--purple-500); transform: translateY(-1px); }

        /* ── PASSWORD TOGGLE ── */
        .pass-wrap { position: relative; }
        .pass-wrap input { padding-right: 42px !important; }
        .pass-toggle {
            position: absolute;
            right: 11px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 1rem;
            padding: 0;
            line-height: 1;
        }
        .pass-toggle:hover { color: var(--purple-300); }

        /* ── PASSWORD STRENGTH ── */
        .password-strength {
            height: 4px;
            border-radius: 2px;
            margin-top: 6px;
            background: var(--border);
            transition: all 0.3s;
        }
        .strength-weak   { background: #ef4444; width: 33%; }
        .strength-medium { background: #f59e0b; width: 66%; }
        .strength-strong { background: #10b981; width: 100%; }
        .strength-label  { font-size: 0.7rem; margin-top: 4px; color: var(--text-soft); }

        @media (max-width: 768px) {
            .profile-layout { grid-template-columns: 1fr; }
            .form-row-2     { grid-template-columns: 1fr; }
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
            <a href="recipes.php" class="nav-item"><span class="nav-icon">🍳</span> Recipes</a>
            <a href="shopping.php" class="nav-item"><span class="nav-icon">🛍️</span> Shopping List</a>
            <a href="cooking_history.php" class="nav-item"><span class="nav-icon">📖</span> Cooking History</a>

            <div class="nav-label">Finance</div>
            <a href="budget.php" class="nav-item"><span class="nav-icon">💰</span> Budget</a>
            <a href="expense_history.php" class="nav-item"><span class="nav-icon">🧾</span> Expense History</a>
            <a href="monthly_report.php" class="nav-item"><span class="nav-icon">📊</span> Monthly Report</a>
            <a href="prices.php" class="nav-item"><span class="nav-icon">📈</span> Price Tracker</a>

            <div class="nav-label">Account</div>
            <a href="profile.php" class="nav-item active"><span class="nav-icon">👤</span> Profile</a>
            <a href="logout.php" class="nav-item"><span class="nav-icon">🚪</span> Logout</a>
        </nav>
        <div class="sidebar-footer">
            <div class="user-info">
                <?php if ($photo_url): ?>
                    <img src="<?= $photo_url ?>" class="user-avatar" style="object-fit:cover;" alt="avatar"/>
                <?php else: ?>
                    <div class="user-avatar"><?= $avatar_letter ?></div>
                <?php endif; ?>
                <div>
                    <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
                    <div class="user-role">Member</div>
                </div>
            </div>
        </div>
    </aside>


    <!-- MAIN -->
    <main class="main-content">

        <div class="page-header">
            <div class="page-title">👤 Profile</div>
            <div class="page-sub">Manage your account information</div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success" style="margin-bottom:20px;">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom:20px;">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="profile-layout">

            <!-- LEFT: PHOTO CARD -->
            <div class="photo-card">

                <div class="avatar-wrap">
                    <?php if ($photo_url): ?>
                        <img src="<?= $photo_url ?>" class="avatar-img" id="avatarPreview" alt="Profile Photo"/>
                    <?php else: ?>
                        <div class="avatar-placeholder" id="avatarPlaceholder"><?= $avatar_letter ?></div>
                    <?php endif; ?>
                    <label for="profile_photo" class="avatar-edit-btn" title="Change photo">✏️</label>
                </div>

                <div class="profile-name"><?= htmlspecialchars($user['name']) ?></div>
                <div class="profile-email"><?= htmlspecialchars($user['email']) ?></div>
                <div class="profile-joined">Joined <?= date('F Y', strtotime($user['created_at'])) ?></div>

                <form method="POST" enctype="multipart/form-data" class="photo-upload-form">
                    <input type="hidden" name="action" value="upload_photo"/>
                    <input
                        type="file"
                        name="profile_photo"
                        id="profile_photo"
                        accept="image/*"
                        style="display:none;"
                        onchange="previewPhoto(this)"
                    />
                    <label for="profile_photo" class="photo-input-label">📷 Choose Photo</label>
                    <span id="photo_preview_name">JPG, PNG, WEBP · Max 2MB</span>
                    <button type="submit" class="upload-btn" id="uploadBtn">⬆️ Upload Photo</button>
                </form>

            </div>


            <!-- RIGHT -->
            <div class="right-side">

                <!-- PERSONAL INFO -->
                <div class="section-card">
                    <h3>📝 Personal Information</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_info"/>
                        <div class="form-row-2">
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required placeholder="Your full name"/>
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required placeholder="your@email.com"/>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Phone / Contact</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="e.g. 01XXXXXXXXX"/>
                        </div>
                        <button type="submit" class="save-btn">💾 Save Changes</button>
                    </form>
                </div>


                <!-- PASSWORD CHANGE -->
                <div class="section-card">
                    <h3>🔒 Change Password</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password"/>

                        <div class="form-group">
                            <label>Current Password</label>
                            <div class="pass-wrap">
                                <input type="password" name="current_password" id="currentPass" placeholder="Enter current password"/>
                                <button type="button" class="pass-toggle" onclick="togglePass('currentPass', this)">👁️</button>
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label>New Password</label>
                                <div class="pass-wrap">
                                    <input type="password" name="new_password" id="newPass" placeholder="Min 6 characters" oninput="checkStrength(this.value)"/>
                                    <button type="button" class="pass-toggle" onclick="togglePass('newPass', this)">👁️</button>
                                </div>
                                <div class="password-strength" id="strengthBar"></div>
                                <div class="strength-label" id="strengthLabel"></div>
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <div class="pass-wrap">
                                    <input type="password" name="confirm_password" id="confirmPass" placeholder="Repeat new password"/>
                                    <button type="button" class="pass-toggle" onclick="togglePass('confirmPass', this)">👁️</button>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="save-btn">🔑 Change Password</button>
                    </form>
                </div>


                <!-- ACCOUNT INFO -->
                <div class="section-card">
                    <h3>ℹ️ Account Details</h3>
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <div style="display:flex;justify-content:space-between;font-size:0.85rem;">
                            <span style="color:var(--text-muted)">User ID</span>
                            <span style="color:var(--text-main);font-weight:600;">#<?= $user_id ?></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:0.85rem;">
                            <span style="color:var(--text-muted)">Member Since</span>
                            <span style="color:var(--text-main);font-weight:600;"><?= date('d M Y', strtotime($user['created_at'])) ?></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:0.85rem;">
                            <span style="color:var(--text-muted)">Account Status</span>
                            <span class="badge badge-success">Active</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </main>
</div>

<script>

// ── Show/Hide Password ───────────────────────────────────────
function togglePass(inputId, btn) {
    const input = document.getElementById(inputId);
    if (input.type === 'password') {
        input.type  = 'text';
        btn.textContent = '🙈';
    } else {
        input.type  = 'password';
        btn.textContent = '👁️';
    }
}

// ── Password Strength ────────────────────────────────────────
function checkStrength(val) {
    const bar   = document.getElementById('strengthBar');
    const label = document.getElementById('strengthLabel');

    if (val.length === 0) {
        bar.className = 'password-strength';
        label.textContent = '';
        return;
    }

    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val) && /[0-9]/.test(val)) score++;

    if (score === 1) {
        bar.className = 'password-strength strength-weak';
        label.textContent = 'Weak';
        label.style.color = '#ef4444';
    } else if (score === 2) {
        bar.className = 'password-strength strength-medium';
        label.textContent = 'Medium';
        label.style.color = '#f59e0b';
    } else {
        bar.className = 'password-strength strength-strong';
        label.textContent = 'Strong';
        label.style.color = '#10b981';
    }
}

// ── Photo Preview ────────────────────────────────────────────
function previewPhoto(input) {
    const file = input.files[0];
    if (!file) return;

    document.getElementById('photo_preview_name').textContent = file.name;
    document.getElementById('uploadBtn').style.display = 'block';

    const reader = new FileReader();
    reader.onload = function(e) {
        const existing    = document.getElementById('avatarPreview');
        const placeholder = document.getElementById('avatarPlaceholder');

        if (existing) {
            existing.src = e.target.result;
        } else if (placeholder) {
            const img = document.createElement('img');
            img.src       = e.target.result;
            img.className = 'avatar-img';
            img.id        = 'avatarPreview';
            placeholder.replaceWith(img);
        }
    };
    reader.readAsDataURL(file);
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

</script>

</body>
</html>