<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        $stmt = $pdo->prepare("SELECT u.*, b.name as branch_name FROM users u LEFT JOIN branches b ON u.branch_id = b.id WHERE u.email = ? AND u.status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']     = $user['id'];
            $_SESSION['name']        = $user['name'];
            $_SESSION['email']       = $user['email'];
            $_SESSION['role']        = $user['role'];
            $_SESSION['branch_id']   = $user['branch_id'];
            $_SESSION['branch_name'] = $user['branch_name'] ?? 'Super Admin Panel';

            session_regenerate_id(true);
            logAudit('login', 'auth', 'User logged in successfully');

            header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}

$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Sign In | BillManage</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">

    <style>
        body { background: #F8FAFC; }

        .login-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Left Brand Panel */
        .login-brand {
            width: 480px;
            flex-shrink: 0;
            background: #1E293B;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 48px;
            position: relative;
            overflow: hidden;
        }

        /* Subtle dot pattern */
        .login-brand::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: radial-gradient(circle, rgba(99,102,241,0.08) 1px, transparent 1px);
            background-size: 28px 28px;
            pointer-events: none;
        }

        /* Orange glow */
        .login-brand::after {
            content: '';
            position: absolute;
            bottom: -80px;
            left: -80px;
            width: 360px;
            height: 360px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(99,102,241,0.18) 0%, transparent 70%);
            pointer-events: none;
        }

        .brand-top { position: relative; z-index: 1; }

        .brand-logo {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 48px;
        }

        .brand-logo .logo-mark {
            width: 44px;
            height: 44px;
            background: #6366F1;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 800;
            color: white;
            letter-spacing: -1px;
        }

        .brand-logo .logo-name {
            font-size: 20px;
            font-weight: 700;
            color: #F8FAFC;
        }

        .brand-headline {
            font-size: 32px;
            font-weight: 800;
            color: #F8FAFC;
            line-height: 1.25;
            margin-bottom: 16px;
            letter-spacing: -0.5px;
        }

        .brand-headline span { color: #6366F1; }

        .brand-sub {
            font-size: 15px;
            color: #94A3B8;
            line-height: 1.6;
            margin-bottom: 40px;
        }

        .feature-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 16px;
            position: relative;
            z-index: 1;
        }

        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .feature-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(99,102,241,0.15);
            border: 1px solid rgba(99,102,241,0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: #6366F1;
            flex-shrink: 0;
        }

        .feature-text strong {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #F1F5F9;
            margin-bottom: 2px;
        }

        .feature-text span {
            font-size: 12px;
            color: #64748B;
        }

        .brand-footer {
            font-size: 12px;
            color: #475569;
            position: relative;
            z-index: 1;
        }

        /* Right Form Panel */
        .login-form-panel {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            background: #F8FAFC;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
        }

        .login-card-header {
            margin-bottom: 32px;
        }

        .login-card-header h2 {
            font-size: 26px;
            font-weight: 700;
            color: #1E293B;
            margin-bottom: 6px;
        }

        .login-card-header p {
            font-size: 14px;
            color: #64748B;
        }

        .login-form-group {
            margin-bottom: 20px;
        }

        .login-input-wrap {
            position: relative;
        }

        .login-input-wrap .input-prefix {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94A3B8;
            font-size: 14px;
            pointer-events: none;
        }

        .login-input-wrap .form-control {
            padding-left: 40px;
            height: 46px;
            border-radius: 10px;
            font-size: 14px;
            background: white;
            border: 1.5px solid #E2E8F0;
        }

        .login-input-wrap .form-control:focus {
            border-color: #6366F1;
            box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
        }

        .password-toggle-btn {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #94A3B8;
            background: none;
            border: none;
            font-size: 14px;
            padding: 4px;
            transition: color 0.15s;
        }
        .password-toggle-btn:hover { color: #475569; }

        .login-submit-btn {
            width: 100%;
            height: 46px;
            background: #6366F1;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
        }

        .login-submit-btn:hover { background: #4F46E5; box-shadow: 0 4px 14px rgba(99,102,241,0.35); transform: translateY(-1px); }
        .login-submit-btn:active { transform: translateY(0); }
        .login-submit-btn:disabled { opacity: 0.65; pointer-events: none; }

        .login-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 24px 0 20px;
            color: #94A3B8;
            font-size: 12px;
        }
        .login-divider::before, .login-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #E2E8F0;
        }

        .login-footer-note {
            margin-top: 24px;
            text-align: center;
            font-size: 12px;
            color: #94A3B8;
        }

        @media (max-width: 900px) { .login-brand { display: none; } }
        @media (max-width: 480px) { .login-form-panel { padding: 24px; } }
    </style>
</head>
<body>

<div class="login-wrapper">

    <!-- Brand Panel -->
    <div class="login-brand">
        <div class="brand-top">
            <div class="brand-logo">
                <div class="logo-mark">BM</div>
                <span class="logo-name">BillManage</span>
            </div>
            <h1 class="brand-headline">Run your business<br>with <span>confidence</span></h1>
            <p class="brand-sub">Multi-branch billing, inventory, and financial analytics — all in one clean, fast platform.</p>
        </div>

        <ul class="feature-list">
            <li class="feature-item">
                <div class="feature-icon"><i class="fas fa-cash-register"></i></div>
                <div class="feature-text">
                    <strong>Fast POS Billing</strong>
                    <span>Barcode scanning, GST, discounts — one click billing</span>
                </div>
            </li>
            <li class="feature-item">
                <div class="feature-icon"><i class="fas fa-warehouse"></i></div>
                <div class="feature-text">
                    <strong>Smart Inventory</strong>
                    <span>Real-time stock levels with low & dead stock alerts</span>
                </div>
            </li>
            <li class="feature-item">
                <div class="feature-icon"><i class="fas fa-chart-bar"></i></div>
                <div class="feature-text">
                    <strong>Financial Reports</strong>
                    <span>Sales, profit, credit — complete business visibility</span>
                </div>
            </li>
            <li class="feature-item">
                <div class="feature-icon"><i class="fas fa-store"></i></div>
                <div class="feature-text">
                    <strong>Multi-Branch</strong>
                    <span>Manage all locations from a single dashboard</span>
                </div>
            </li>
        </ul>

        <div class="brand-footer">© <?php echo date('Y'); ?> BillManage — All rights reserved</div>
    </div>

    <!-- Form Panel -->
    <div class="login-form-panel">
        <div class="login-card">

            <div class="login-card-header">
                <h2>Welcome back 👋</h2>
                <p>Sign in to your BillManage account to continue.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger" style="border-radius:10px;margin-bottom:20px;">
                    <i class="fas fa-circle-xmark"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($msg === 'logged_out'): ?>
                <div class="alert alert-success" style="border-radius:10px;margin-bottom:20px;">
                    <i class="fas fa-circle-check"></i>
                    <div>You have been successfully logged out.</div>
                </div>
            <?php endif; ?>

            <form action="" method="POST" id="loginForm">
                <div class="login-form-group">
                    <label class="form-label" style="font-size:13px;font-weight:600;color:#475569;margin-bottom:6px;display:block;">Email Address</label>
                    <div class="login-input-wrap">
                        <span class="input-prefix"><i class="fas fa-envelope"></i></span>
                        <input type="email" name="email" class="form-control" placeholder="admin@shop.com"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                               required autofocus autocomplete="email">
                    </div>
                </div>

                <div class="login-form-group">
                    <label class="form-label" style="font-size:13px;font-weight:600;color:#475569;margin-bottom:6px;display:block;">Password</label>
                    <div class="login-input-wrap">
                        <span class="input-prefix"><i class="fas fa-lock"></i></span>
                        <input type="password" name="password" id="passwordInput" class="form-control"
                               placeholder="Enter your password" required autocomplete="current-password">
                        <button type="button" class="password-toggle-btn" id="togglePassword" title="Toggle password">
                            <i class="fas fa-eye" id="eye-icon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="login-submit-btn" id="loginBtn">
                    <i class="fas fa-right-to-bracket"></i>
                    <span>Sign In to Dashboard</span>
                </button>
            </form>

            <div class="login-footer-note">
                <i class="fas fa-shield-halved" style="color:var(--success);margin-right:4px;"></i>
                Secured with encrypted sessions &amp; CSRF protection
            </div>

        </div>
    </div>
</div>

<script>
const toggleBtn    = document.getElementById('togglePassword');
const passwordInput = document.getElementById('passwordInput');
const eyeIcon      = document.getElementById('eye-icon');

toggleBtn.addEventListener('click', () => {
    const isPass = passwordInput.type === 'password';
    passwordInput.type = isPass ? 'text' : 'password';
    eyeIcon.className  = isPass ? 'fas fa-eye-slash' : 'fas fa-eye';
});

document.getElementById('loginForm').addEventListener('submit', function() {
    const btn = document.getElementById('loginBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Signing in…</span>';
});
</script>

</body>
</html>
