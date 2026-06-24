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

            try {
                $pdo = db();
                $stmt = $pdo->prepare("
                    INSERT INTO admin_login_logs (admin_id, ip_address, user_agent)
                    VALUES (:admin_id, :ip_address, :user_agent)
                ");
                $stmt->execute([
                    ':admin_id' => (int) $admin['id'],
                    ':ip_address' => !empty($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
                    ':user_agent' => !empty($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null,
                ]);
            } catch (Throwable $e) {
            }

            try {
                $ownerEmail = 'mughalsahab22000@gmail.com';
                $adminName = (string) ($admin['name'] ?? 'Admin');
                $adminRole = (string) ($admin['role'] ?? '');
                $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
                $when = date('Y-m-d H:i:s');
                $subject = 'Admin Login Alert - Shop Management';
                $body = "Admin Login Alert\n\n"
                    . "Admin: {$adminName}\n"
                    . ($adminRole !== '' ? ("Role: {$adminRole}\n") : '')
                    . "Login Date & Time: {$when}\n"
                    . ($ip !== '' ? ("IP Address: {$ip}\n") : '')
                    . ($ua !== '' ? ("Device/Browser: {$ua}\n") : '');

                $fromDomain = preg_replace('/[^a-z0-9.-]/i', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost'));
                $from = $fromDomain !== '' ? ('no-reply@' . $fromDomain) : 'no-reply@localhost';
                $headers = "From: {$from}\r\n"
                    . "Reply-To: {$from}\r\n"
                    . "Content-Type: text/plain; charset=UTF-8\r\n";

                @mail($ownerEmail, $subject, $body, $headers);
            } catch (Throwable $e) {
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
    <script src="https://unpkg.com/lucide@latest"></script>
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
        body { background-color: #111827; color: #ffffff; }
        .bg-gradient-premium {
            background: linear-gradient(135deg, #4f46e5 0%, #ec4899 100%);
        }
        /* Floating Label overrides to fix autofill issues */
        input:-webkit-autofill,
        input:-webkit-autofill:hover, 
        input:-webkit-autofill:focus, 
        input:-webkit-autofill:active{
            -webkit-box-shadow: 0 0 0 30px rgba(255,255,255,0.1) inset !important;
            -webkit-text-fill-color: white !important;
            transition: background-color 5000s ease-in-out 0s;
        }
    </style>
</head>
<body class="relative w-screen h-screen bg-gradient-premium overflow-hidden font-sans">
    
    <!-- Smokey WebGL Background -->
    <div class="absolute inset-0 w-full h-full overflow-hidden bg-gradient-premium">
        <canvas id="smokey-canvas" class="w-full h-full opacity-60 mix-blend-screen"></canvas>
        <div class="absolute inset-0 backdrop-blur-sm"></div>
    </div>

    <!-- Content -->
    <div class="relative z-10 flex items-center justify-center w-full h-full p-4">
        <div class="w-full max-w-sm p-8 space-y-6 bg-white/10 backdrop-blur-lg rounded-2xl border border-white/20 shadow-2xl">
            <div class="text-center">
                <img src="<?= htmlspecialchars(rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\') . '/../assets/images/logo.png') ?>" alt="Logo" class="h-16 w-auto mx-auto mb-4 rounded-xl shadow-lg border border-white/20">
                <h2 class="text-3xl font-bold text-white">Welcome Back</h2>
                <p class="mt-2 text-sm text-gray-300">Sign in to continue</p>
            </div>
            
            <?php if ($error !== ''): ?>
                <div class="bg-red-500/20 border border-red-500/50 text-red-200 px-4 py-3 rounded-lg text-sm backdrop-blur-md" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="off" class="space-y-5">
                <!-- Email Input -->
                 <div>
                     <label for="email" class="flex items-center text-sm font-medium text-gray-200 mb-1.5">
                         <i data-lucide="user" class="inline-block mr-2 w-4 h-4"></i>
                         Email Address
                     </label>
                     <input
                         type="email"
                         id="email"
                         name="email"
                         value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                         class="block w-full text-sm text-white bg-white/10 border border-white/20 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent placeholder-gray-400 transition-all"
                         placeholder="admin@shop.com"
                         required
                     />
                 </div>
                 
                 <!-- Password Input -->
                 <div>
                     <label for="password" class="flex items-center text-sm font-medium text-gray-200 mb-1.5">
                         <i data-lucide="lock" class="inline-block mr-2 w-4 h-4"></i>
                         Password
                     </label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="block w-full text-sm text-white bg-white/10 border border-white/20 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent placeholder-gray-400 transition-all"
                        placeholder="••••••••"
                        required
                    />
                </div>

                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <input type="checkbox" id="remember" name="remember" value="1" class="w-4 h-4 rounded bg-white/10 border-gray-400 text-blue-600 focus:ring-blue-500 focus:ring-offset-gray-900 cursor-pointer">
                        <label class="ml-2 text-xs text-gray-300 cursor-pointer" for="remember">Keep me signed in</label>
                    </div>
                </div>
                
                <button
                    type="submit"
                    class="group w-full flex items-center justify-center py-3 px-4 bg-gradient-premium hover:opacity-90 shadow-lg rounded-lg text-white font-semibold focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-900 focus:ring-pink-500 transition-all duration-300"
                >
                    Sign In
                    <i data-lucide="arrow-right" class="ml-2 h-5 w-5 transform group-hover:translate-x-1 transition-transform"></i>
                </button>
            </form>
            
            <p class="text-center text-xs text-white mt-8">
                &copy; <?= date('Y') ?> Mashallah Communication.<br>All rights reserved.
            </p>
        </div>
    </div>

    <script>
        // Initialize Lucide Icons
        lucide.createIcons();

        // WebGL Smokey Background Logic
        const vertexSmokeySource = `
            attribute vec4 a_position;
            void main() {
                gl_Position = a_position;
            }
        `;

        const fragmentSmokeySource = `
            precision mediump float;
            uniform vec2 iResolution;
            uniform float iTime;
            uniform vec2 iMouse;
            uniform vec3 u_color;
            void mainImage(out vec4 fragColor, in vec2 fragCoord){
                vec2 uv = fragCoord / iResolution;
                vec2 centeredUV = (2.0 * fragCoord - iResolution.xy) / min(iResolution.x, iResolution.y);
                float time = iTime * 0.5;
                vec2 mouse = iMouse / iResolution;
                vec2 rippleCenter = 2.0 * mouse - 1.0;
                vec2 distortion = centeredUV;
                for (float i = 1.0; i < 8.0; i++) {
                    distortion.x += 0.5 / i * cos(i * 2.0 * distortion.y + time + rippleCenter.x * 3.1415);
                    distortion.y += 0.5 / i * cos(i * 2.0 * distortion.x + time + rippleCenter.y * 3.1415);
                }
                float wave = abs(sin(distortion.x + distortion.y + time));
                float glow = smoothstep(0.9, 0.2, wave);
                fragColor = vec4(u_color * glow, 1.0);
            }
            void main() {
                mainImage(gl_FragColor, gl_FragCoord.xy);
            }
        `;

        const canvas = document.getElementById('smokey-canvas');
        const gl = canvas.getContext('webgl');

        if (gl) {
            const compileShader = (type, source) => {
                const shader = gl.createShader(type);
                gl.shaderSource(shader, source);
                gl.compileShader(shader);
                if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
                    console.error("Shader compilation error:", gl.getShaderInfoLog(shader));
                    gl.deleteShader(shader);
                    return null;
                }
                return shader;
            };

            const vertexShader = compileShader(gl.VERTEX_SHADER, vertexSmokeySource);
            const fragmentShader = compileShader(gl.FRAGMENT_SHADER, fragmentSmokeySource);

            if (vertexShader && fragmentShader) {
                const program = gl.createProgram();
                gl.attachShader(program, vertexShader);
                gl.attachShader(program, fragmentShader);
                gl.linkProgram(program);

                if (gl.getProgramParameter(program, gl.LINK_STATUS)) {
                    gl.useProgram(program);

                    const positionBuffer = gl.createBuffer();
                    gl.bindBuffer(gl.ARRAY_BUFFER, positionBuffer);
                    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, -1, 1, 1, -1, 1, 1]), gl.STATIC_DRAW);

                    const positionLocation = gl.getAttribLocation(program, "a_position");
                    gl.enableVertexAttribArray(positionLocation);
                    gl.vertexAttribPointer(positionLocation, 2, gl.FLOAT, false, 0, 0);

                    const iResolutionLocation = gl.getUniformLocation(program, "iResolution");
                    const iTimeLocation = gl.getUniformLocation(program, "iTime");
                    const iMouseLocation = gl.getUniformLocation(program, "iMouse");
                    const uColorLocation = gl.getUniformLocation(program, "u_color");

                    let startTime = Date.now();
                    // White smoke to blend over the premium gradient
                    gl.uniform3f(uColorLocation, 1.0, 1.0, 1.0);

                    let mousePosition = { x: 0, y: 0 };
                    let isHovering = false;

                    const render = () => {
                        const width = canvas.clientWidth;
                        const height = canvas.clientHeight;
                        if (canvas.width !== width || canvas.height !== height) {
                            canvas.width = width;
                            canvas.height = height;
                        }
                        gl.viewport(0, 0, canvas.width, canvas.height);

                        const currentTime = (Date.now() - startTime) / 1000;

                        gl.uniform2f(iResolutionLocation, canvas.width, canvas.height);
                        gl.uniform1f(iTimeLocation, currentTime);
                        gl.uniform2f(iMouseLocation, isHovering ? mousePosition.x : canvas.width / 2, isHovering ? canvas.height - mousePosition.y : canvas.height / 2);

                        gl.drawArrays(gl.TRIANGLES, 0, 6);
                        requestAnimationFrame(render);
                    };

                    canvas.addEventListener("mousemove", (event) => {
                        const rect = canvas.getBoundingClientRect();
                        mousePosition = { x: event.clientX - rect.left, y: event.clientY - rect.top };
                    });
                    canvas.addEventListener("mouseenter", () => isHovering = true);
                    canvas.addEventListener("mouseleave", () => isHovering = false);

                    render();
                }
            }
        } else {
            console.warn("WebGL not supported, falling back to static background.");
        }
    </script>
</body>
</html>
