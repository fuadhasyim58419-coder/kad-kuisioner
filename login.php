<?php
ob_start();

$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// Pastikan direktori session dapat ditulis di lingkungan serverless (misal Vercel /tmp)
if (session_status() === PHP_SESSION_NONE) {
    $savePath = session_save_path();
    if (empty($savePath) || !@is_writable($savePath)) {
        if (is_dir('/tmp') && is_writable('/tmp')) {
            @session_save_path('/tmp');
        }
    }
    @session_start();
}

$secret_key = getenv('ADMIN_SECRET') ?: 'pt_kad_stevedoring_secret_2026';
$default_username = getenv('ADMIN_USER') ?: "admin";
$default_password = getenv('ADMIN_PASS') ?: "kad12345";

$error = "";

// Cek apakah admin sudah login via Session atau Signed Auth Cookie
$is_logged = false;
if (isset($_SESSION['kad_admin_logged']) && $_SESSION['kad_admin_logged'] === true) {
    $is_logged = true;
} elseif (isset($_COOKIE['kad_admin_auth'])) {
    $expected_cookie = hash_hmac('sha256', 'kad_admin_authorized_' . $default_username, $secret_key);
    if (hash_equals($expected_cookie, $_COOKIE['kad_admin_auth'])) {
        $_SESSION['kad_admin_logged'] = true;
        $_SESSION['admin_user'] = $default_username;
        $is_logged = true;
    }
}

if ($is_logged) {
    header("Location: dashboard.php", true, 302);
    if (ob_get_level() > 0) {
        @ob_end_clean();
    }
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === $default_username && $password === $default_password) {
        $_SESSION['kad_admin_logged'] = true;
        $_SESSION['admin_user'] = $username;

        // Simpan Signed Auth Cookie agar tetap login stabil di serverless lambda instance Vercel
        $cookie_val = hash_hmac('sha256', 'kad_admin_authorized_' . $username, $secret_key);
        @setcookie('kad_admin_auth', $cookie_val, [
            'expires'  => time() + (86400 * 7), // 7 hari
            'path'     => '/',
            'secure'   => $is_https,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        @session_write_close();

        header("Location: dashboard.php", true, 302);
        if (ob_get_level() > 0) {
            @ob_end_clean();
        }
        exit;
    } else {
        $error = "Username atau password yang Anda masukkan salah!";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Admin - PT Kaltim Adhiguna Dermaga</title>
    <style>
        :root {
            --primary-navy: #0a2540;
            --primary-blue: #005b96;
            --secondary-blue: #0088cc;
            --light-blue: #eef6fb;
            --border-color: #d1e3f0;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --white: #ffffff;
            --gold: #f59e0b;
            --red: #ef4444;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #0a2540 0%, #005b96 50%, #0088cc 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            background-color: var(--white);
            max-width: 420px;
            width: 100%;
            border-radius: 14px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }

        .login-header {
            background: linear-gradient(135deg, var(--primary-navy), var(--primary-blue));
            color: var(--white);
            padding: 28px 24px;
            text-align: center;
            border-bottom: 4px solid var(--gold);
        }

        .company-badge {
            display: inline-block;
            background-color: rgba(255, 255, 255, 0.15);
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .login-header h2 {
            font-size: 1.35rem;
            font-weight: 700;
        }

        .login-header p {
            font-size: 0.84rem;
            opacity: 0.88;
            margin-top: 4px;
        }

        form {
            padding: 28px 24px;
        }

        .alert-error {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            color: var(--red);
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.86rem;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-dark);
            margin-bottom: 6px;
        }

        .form-control {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(0, 91, 150, 0.15);
        }

        .btn-submit {
            display: block;
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--primary-navy), var(--primary-blue));
            color: var(--white);
            font-size: 1rem;
            font-weight: 700;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 24px;
        }

        .btn-submit:hover {
            opacity: 0.95;
            transform: translateY(-1px);
        }

        .login-footer {
            text-align: center;
            padding: 14px 20px;
            background-color: #f8fafc;
            border-top: 1px solid var(--border-color);
            font-size: 0.82rem;
            color: var(--text-muted);
        }

        .login-footer a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 600;
        }

        @media (max-width: 480px) {
            body {
                padding: 14px;
            }
            .login-header {
                padding: 22px 18px;
            }
            .login-header h2 {
                font-size: 1.2rem;
            }
            form {
                padding: 22px 18px;
            }
            .form-control {
                font-size: 16px;
            }
            .btn-submit {
                min-height: 48px;
            }
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="login-header">
            <img src="assets/img/logo_kad.jpg" alt="Logo PT KAD" style="height: 54px; background: #ffffff; padding: 4px 8px; border-radius: 8px; margin-bottom: 12px; object-fit: contain; box-shadow: 0 4px 10px rgba(0,0,0,0.15);">
            <div class="company-badge">PT Kaltim Adhiguna Dermaga</div>
            <h2>Portal Admin Dashboard</h2>
            <p>Khusus Tim Manajemen & Evaluasi Kepuasan Pelanggan</p>
        </div>

        <form action="login.php" method="POST">
            <?php if (!empty($error)): ?>
                <div class="alert-error">
                    <span>&#9888;</span> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="Masukkan username" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="Masukkan password" required>
            </div>

            <button type="submit" class="btn-submit">Masuk ke Dashboard &rarr;</button>
        </form>

        <div class="login-footer">
            <a href="index.html">&larr; Kembali ke Formulir Kuesioner</a>
        </div>
    </div>

</body>
</html>
