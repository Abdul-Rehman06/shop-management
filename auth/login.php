<?php

declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';

app_start_session();

if (app_is_logged_in()) {
    header('Location: ' . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\') . '/../dashboard/index.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email.';
    } elseif ($password === '') {
        $error = 'Please enter your password.';
    } else {
        $pdo = db();
        $admin = null;
        try {
            $stmt = $pdo->prepare('SELECT id, name, email, role, password FROM admins WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $admin = $stmt->fetch();
        } catch (Throwable $e) {
            $stmt = $pdo->prepare('SELECT id, name, email, password FROM admins WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $admin = $stmt->fetch();
        }

        $isValid = false;
        if ($admin) {
            $stored = (string) $admin['password'];
            $info = password_get_info($stored);
            if (($info['algo'] ?? 0) !== 0) {
                $isValid = password_verify($password, $stored);
            } else {
                $isValid = hash_equals($stored, $password);
                if ($isValid) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $upd = $pdo->prepare('UPDATE admins SET password = :password WHERE id = :id');
                    $upd->execute([
                        ':password' => $newHash,
                        ':id' => (int) $admin['id'],
                    ]);
                }
            }
        }

        if (!$admin || !$isValid) {
            $error = 'Invalid email or password.';
        } else {
            app_login_admin($admin);
            if ($remember) {
                app_issue_remember_token((int) $admin['id']);
            }

            header('Location: ' . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\') . '/../dashboard/index.php');
            exit;
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Mashallah Communication</title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\') . '/../assets/images/favicon.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'] },
                    colors: {
                        appbg: '#F3F4F6',
                        card: '#FFFFFF',
                        brand: { 500: '#3B82F6', 600: '#2563EB', start: '#3B82F6', end: '#9333EA' },
                        danger: '#EF4444',
                        bordercolor: '#E5E7EB',
                    }
                }
            }
        }
    </script>
    <style>
        body { background-color: #F3F4F6; color: #111827; }
        .glass-panel { background: #FFFFFF; border: 1px solid #E5E7EB; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); }
        .btn-gradient { background-image: linear-gradient(to right, #3B82F6, #9333EA); }
        .btn-gradient:hover { opacity: 0.9; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4); }
        .input-glow:focus { border-color: #3B82F6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2); outline: none; }
        .text-gradient { background-clip: text; -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-image: linear-gradient(to right, #3B82F6, #9333EA); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center relative overflow-hidden bg-appbg">
    <!-- Background Decor -->
    <div class="absolute top-[-20%] left-[-10%] w-[50%] h-[50%] bg-brand-500/10 rounded-full blur-[120px]"></div>
    <div class="absolute bottom-[-20%] right-[-10%] w-[50%] h-[50%] bg-purple-600/10 rounded-full blur-[120px]"></div>

    <div class="w-full max-w-md p-6 relative z-10">
        <div class="text-center mb-8">
            <img src="<?= htmlspecialchars(rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\') . '/../assets/images/logo.png') ?>" alt="Logo" class="h-16 w-auto mx-auto mb-4 rounded-xl shadow-md">
            <h1 class="text-3xl font-bold tracking-tight text-gradient mb-2">Mashallah Communication</h1>
            <p class="text-gray-500">Sign in to your premium dashboard</p>
        </div>

        <div class="glass-panel rounded-2xl p-8">
            <?php if ($error !== ''): ?>
                <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg mb-6 text-sm" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="off" class="space-y-5">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">Email Address</label>
                    <input type="email" class="w-full bg-white border border-gray-300 text-gray-900 px-4 py-2.5 rounded-lg input-glow transition-all" id="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" required placeholder="admin@shop.com">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">Password</label>
                    <input type="password" class="w-full bg-white border border-gray-300 text-gray-900 px-4 py-2.5 rounded-lg input-glow transition-all" id="password" name="password" required placeholder="••••••••">
                </div>
                <div class="flex items-center">
                    <input type="checkbox" class="w-4 h-4 rounded bg-white border-gray-300 text-brand-600 focus:ring-brand-500" id="remember" name="remember" value="1">
                    <label class="ml-2 text-sm text-gray-600" for="remember">Keep me signed in</label>
                </div>
                <button type="submit" class="w-full btn-gradient text-white font-medium py-2.5 rounded-lg transition-all mt-2">
                    Access Dashboard
                </button>
            </form>
        </div>
        
        <p class="text-center text-gray-400 text-sm mt-8">
            &copy; <?= date('Y') ?> Mashallah Communication. All rights reserved.
        </p>
    </div>
</body>
</html>
