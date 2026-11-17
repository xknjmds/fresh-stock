<?php
/**
 * login.php — Admin sign-in page.
 */
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = post('username');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        // SELECT the user by username (prepared statement)
        $stmt = $conn->prepare('SELECT id, full_name, username, password_hash FROM users WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true); // prevent session fixation
            $_SESSION['user_id']   = (int) $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['username']  = $user['username'];
            flash('success', 'Welcome back, ' . $user['full_name'] . '!');
            redirect('index.php');
        }
        $error = 'Invalid username or password.';
    }
}
$flashes = get_flashes();

// Small live numbers for the decorative side panel
$side = $conn->query('SELECT COUNT(*) AS products, COALESCE(SUM(quantity <= reorder_level), 0) AS low FROM products')->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · FreshStock</title>
    <link rel="icon" href="images/logo.svg" type="image/svg+xml">
    <script>
        (function () {
            var t = 'light';
            try { t = localStorage.getItem('theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); } catch (e) {}
            document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=2" rel="stylesheet">
</head>
<body>
<div id="nprogress"></div>

<div class="auth-grid">
    <!-- Left: sign-in form -->
    <div class="auth-form-side">
        <div class="d-flex justify-content-between align-items-center">
            <a href="login.php" class="brand px-0">
                <span class="brand-mark"><i class="bi bi-basket2"></i></span>
                <span class="brand-title">FreshStock</span>
            </a>
            <button type="button" class="btn btn-ghost btn-icon" data-theme-toggle aria-label="Toggle theme"><i class="bi bi-sun theme-icon-light"></i></button>
        </div>

        <div class="auth-form page" style="padding:2rem 0;flex:0">
            <div class="text-center mb-4">
                <h1 class="h3 fw-semibold tracking-tight mb-1">Welcome back</h1>
                <p class="text-muted-fg mb-0">Sign in to manage your store's inventory</p>
            </div>

            <?php foreach ($flashes as $f): ?>
                <div class="d-flex gap-2 align-items-center border rounded-3 px-3 py-2 mb-3 text-sm">
                    <i class="bi bi-info-circle"></i><?= e($f['message']) ?>
                </div>
            <?php endforeach; ?>
            <?php if ($error): ?>
                <div class="d-flex gap-2 align-items-center rounded-3 px-3 py-2 mb-3 text-sm" role="alert"
                     style="border:1px solid hsl(var(--destructive) / .4);color:hsl(var(--destructive))">
                    <i class="bi bi-exclamation-circle"></i><?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="needs-validation d-flex flex-column gap-3" novalidate>
                <?= csrf_field() ?>
                <div>
                    <label class="form-label" for="username">Username</label>
                    <input type="text" class="form-control" id="username" name="username" placeholder="admin"
                           value="<?= e($username) ?>" autocomplete="username" required autofocus>
                    <div class="invalid-feedback">Username is required.</div>
                </div>
                <div>
                    <label class="form-label" for="password">Password</label>
                    <div class="position-relative">
                        <input type="password" class="form-control pe-5" id="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
                        <button type="button" class="btn btn-ghost btn-icon btn-sm position-absolute top-50 end-0 translate-middle-y me-1 text-muted-fg"
                                id="togglePw" aria-label="Show password"><i class="bi bi-eye"></i></button>
                    </div>
                    <div class="invalid-feedback">Password is required.</div>
                </div>
                <button type="submit" class="btn btn-primary w-100" data-loading="Signing in...">Sign in</button>
            </form>

            <div class="d-flex align-items-center gap-3 my-4 text-xs text-muted-fg">
                <hr class="flex-grow-1 m-0"><span>DEMO ACCOUNT</span><hr class="flex-grow-1 m-0">
            </div>
            <button type="button" class="btn btn-outline w-100" id="fillDemo"><i class="bi bi-person-badge"></i> Use admin / admin123</button>
        </div>

        <p class="text-center text-xs text-muted-fg mb-0 mt-auto">Grocery Stock Management System · Web Systems Technologies</p>
    </div>

    <!-- Right: decorative panel -->
    <div class="auth-art">
        <div class="grid-bg"></div>
        <div class="glow" style="background:hsl(142 71% 45%);top:-120px;right:-80px"></div>
        <div class="glow" style="background:hsl(220 70% 50%);bottom:-160px;left:-120px;opacity:.2"></div>

        <div class="position-relative d-flex align-items-center gap-2 fw-medium">
            <span class="brand-mark" style="background:#fafafa;color:#18181b"><i class="bi bi-basket2"></i></span> FreshStock
        </div>

        <div class="position-relative d-flex flex-column gap-3 mx-auto" style="width:340px">
            <div class="float-card float-a">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-xs" style="color:#a1a1aa">Products tracked</span>
                    <i class="bi bi-box-seam" style="color:#a1a1aa"></i>
                </div>
                <div class="fs-3 fw-bold tabular"><?= (int) $side['products'] ?></div>
                <div class="d-flex align-items-end gap-1 mt-2" style="height:40px">
                    <?php foreach ([40, 65, 50, 80, 60, 95, 75] as $i => $h): ?>
                        <span class="flex-grow-1 rounded-1" style="height:<?= $h ?>%;background:hsl(142 71% 45% / <?= $i === 5 ? '1' : '.35' ?>)"></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="float-card float-b ms-5">
                <div class="d-flex align-items-center gap-3">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-2" style="width:36px;height:36px;background:hsl(38 92% 50% / .15);color:hsl(38 92% 60%)"><i class="bi bi-bell"></i></span>
                    <div>
                        <div class="fw-medium text-sm">Low stock alert</div>
                        <div class="text-xs" style="color:#a1a1aa"><?= (int) $side['low'] ?> items need restocking</div>
                    </div>
                </div>
            </div>
        </div>

        <blockquote class="position-relative mb-0">
            <p class="fs-5 fw-medium mb-2" style="max-width:28rem">"Know what's on your shelves, what's running low and what's about to expire — at a glance."</p>
            <footer class="text-sm" style="color:#a1a1aa">Built for small convenience stores</footer>
        </blockquote>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js?v=2"></script>
<script>
    // Show / hide password and quick-fill demo credentials
    document.getElementById('togglePw').addEventListener('click', function () {
        var pw = document.getElementById('password'), show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        this.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
    });
    document.getElementById('fillDemo').addEventListener('click', function () {
        var u = document.getElementById('username'), p = document.getElementById('password');
        u.value = 'admin'; p.value = 'admin123';
        [u, p].forEach(function (el) { el.animate([{ boxShadow: '0 0 0 3px hsl(var(--ring) / .25)' }, { boxShadow: '0 0 0 0 transparent' }], { duration: 600 }); });
    });
</script>
</body>
</html>
