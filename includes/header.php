<?php

declare(strict_types=1);

$admin = app_current_admin();
$adminId = (int) ($admin['id'] ?? 0);
$pdo = db();
$stmt = $pdo->prepare("SELECT name, profile_image FROM admins WHERE id = ?");
$stmt->execute([$adminId]);
$adminData = $stmt->fetch();
$adminName = $adminData ? (string)$adminData['name'] : (string)($admin['name'] ?? 'Admin');
$adminImage = $adminData ? (string)$adminData['profile_image'] : '';

$pageTitle = (string) ($pageTitle ?? 'Mashallah Communication');
$extraHead = (string) ($extraHead ?? '');

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> | Mashallah Communication</title>
    <link rel="icon" type="image/png" href="<?= h(app_url('assets/images/favicon.png')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                    },
                    colors: {
                        appbg: '#f8fafc',
                        sidebar: '#ffffff',
                        card: '#ffffff',
                        cardhover: '#f1f5f9',
                        brand: {
                            500: '#4f46e5',
                            600: '#4338ca',
                            gradientStart: '#4f46e5',
                            gradientEnd: '#ec4899',
                        },
                        success: '#10b981',
                        danger: '#ef4444',
                        info: '#3b82f6',
                        warning: '#f59e0b',
                        bordercolor: '#e2e8f0',
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-out',
                        'slide-up': 'slideUp 0.5s ease-out forwards',
                        'slide-in-right': 'slideInRight 0.5s ease-out',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { opacity: '0', transform: 'translateY(20px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                        slideInRight: {
                            '0%': { opacity: '0', transform: 'translateX(20px)' },
                            '100%': { opacity: '1', transform: 'translateX(0)' },
                        }
                    },
                    boxShadow: {
                        'glass': '0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03), inset 0 1px 0 rgba(255, 255, 255, 0.5)',
                        'glow': '0 0 20px rgba(79, 70, 229, 0.15)',
                    }
                }
            }
        }
    </script>
    <style>
        body {
            background-color: #f8fafc !important;
            color: #0f172a !important;
        }
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        /* Glassmorphism Utilities */
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01), inset 0 1px 0 rgba(255, 255, 255, 0.6);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .glass-card:hover {
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.05), inset 0 1px 0 rgba(255, 255, 255, 0.8);
            background: rgba(255, 255, 255, 0.95);
        }
        .text-gradient {
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-image: linear-gradient(135deg, #4f46e5 0%, #ec4899 100%);
        }
        .bg-gradient-premium {
            background: linear-gradient(135deg, #4f46e5 0%, #ec4899 100%);
        }
        .btn-gradient {
            background-image: linear-gradient(135deg, #4f46e5 0%, #ec4899 100%);
            color: white !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        .btn-gradient::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(135deg, #4338ca 0%, #db2777 100%);
            opacity: 0;
            z-index: -1;
            transition: opacity 0.3s ease;
        }
        .btn-gradient:hover::before {
            opacity: 1;
        }
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.3), 0 4px 6px -4px rgba(79, 70, 229, 0.3);
        }
        .input-glow:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
            outline: none;
        }

        /* --- GLOBAL BOOTSTRAP OVERRIDES FOR PREMIUM UI --- */
        /* Cards */
        .card { background: rgba(255,255,255,0.9) !important; backdrop-filter: blur(10px); border: 1px solid rgba(226,232,240,0.8) !important; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05) !important; color: #0f172a !important; border-radius: 1.25rem !important; transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .card:hover { box-shadow: 0 10px 15px -3px rgba(0,0,0,0.08) !important; }
        .card-header, .card-footer { background: transparent !important; border-color: rgba(226,232,240,0.8) !important; }
        
        /* Tables */
        .table { color: #334155 !important; border-color: #e2e8f0 !important; margin-bottom: 0 !important; }
        .table-light { background: #f8fafc !important; color: #475569 !important; }
        .table-light th, .table thead th { background: #f8fafc !important; color: #475569 !important; border-bottom: 2px solid #e2e8f0 !important; font-weight: 600 !important; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; padding: 1.25rem 1.5rem !important; }
        .table-striped>tbody>tr:nth-of-type(odd)>* { background-color: #f8fafc !important; color: #334155 !important; }
        .table-hover>tbody>tr { transition: background-color 0.2s ease; }
        .table-hover>tbody>tr:hover>* { background-color: #f1f5f9 !important; color: #0f172a !important; }
        .table td, .table th { border-color: #e2e8f0 !important; padding: 1.25rem 1.5rem !important; vertical-align: middle !important; }
        
        /* Buttons */
        .btn { border-radius: 0.75rem !important; padding: 0.625rem 1.25rem !important; font-weight: 500 !important; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; letter-spacing: 0.025em; }
        .btn-sm { padding: 0.4rem 0.875rem !important; font-size: 0.875rem !important; border-radius: 0.5rem !important; }
        .btn-primary { background: linear-gradient(135deg, #4f46e5, #4338ca) !important; border: none !important; color: white !important; box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2) !important; }
        .btn-primary:hover { box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.3) !important; transform: translateY(-2px); }
        .btn-outline-primary { border: 2px solid #4f46e5 !important; color: #4f46e5 !important; background: transparent !important; }
        .btn-outline-primary:hover { background: #4f46e5 !important; color: white !important; transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2) !important; }
        .btn-secondary { background: #f1f5f9 !important; border-color: #e2e8f0 !important; color: #475569 !important; }
        .btn-secondary:hover { background: #e2e8f0 !important; border-color: #cbd5e1 !important; color: #0f172a !important; transform: translateY(-1px); }
        .btn-outline-secondary { border: 2px solid #cbd5e1 !important; color: #475569 !important; background: transparent !important; }
        .btn-outline-secondary:hover { background: #f1f5f9 !important; color: #0f172a !important; border-color: #94a3b8 !important; transform: translateY(-1px); }
        .btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626) !important; border: none !important; color: white !important; box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.2) !important; }
        .btn-danger:hover { box-shadow: 0 10px 15px -3px rgba(239, 68, 68, 0.3) !important; transform: translateY(-2px); }
        
        /* Forms */
        .form-control, .form-select { background-color: rgba(255,255,255,0.9) !important; border: 1px solid #cbd5e1 !important; color: #0f172a !important; border-radius: 0.75rem !important; padding: 0.75rem 1.25rem !important; transition: all 0.3s ease; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); }
        .form-control:focus, .form-select:focus { border-color: #4f46e5 !important; box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1) !important; background-color: #ffffff !important; transform: translateY(-1px); }
        .form-control::placeholder { color: #94a3b8 !important; }
        .form-label { color: #334155 !important; font-weight: 600 !important; margin-bottom: 0.5rem !important; font-size: 0.875rem !important; letter-spacing: 0.025em; }
        
        /* Text & Headings */
        h1, h2, h3, h4, h5, h6, .h1, .h2, .h3, .h4, .h5, .h6 { color: #0f172a !important; font-weight: 700 !important; letter-spacing: -0.025em; }
        .text-muted { color: #64748b !important; }
        
        /* Badges */
        .badge { padding: 0.4em 0.8em !important; font-weight: 600 !important; letter-spacing: 0.025em; }
        .badge.bg-danger { background-color: #fee2e2 !important; color: #ef4444 !important; border: 1px solid #fecaca !important; border-radius: 9999px !important; }
        .badge.bg-success { background-color: #d1fae5 !important; color: #10b981 !important; border: 1px solid #a7f3d0 !important; border-radius: 9999px !important; }
        .badge.bg-warning { background-color: #fef3c7 !important; color: #f59e0b !important; border: 1px solid #fde68a !important; border-radius: 9999px !important; }
        .badge.bg-info { background-color: #dbeafe !important; color: #3b82f6 !important; border: 1px solid #bfdbfe !important; border-radius: 9999px !important; }
        
        /* Alerts */
        .alert { border: none !important; border-radius: 1rem !important; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .alert-success { background-color: #ecfdf5 !important; border: 1px solid #a7f3d0 !important; color: #065f46 !important; }
        .alert-danger { background-color: #fef2f2 !important; border: 1px solid #fecaca !important; color: #991b1b !important; }
        
        /* Utility overrides */
        .bg-white, .bg-light { background-color: transparent !important; }
        .text-dark { color: #0f172a !important; }
        .border-end { border-right-color: #e2e8f0 !important; }
        .border-bottom { border-bottom-color: #e2e8f0 !important; }
        .shadow-sm { box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03) !important; }
        
        /* Layout elements */
        .page-header-title { font-size: 1.875rem; font-weight: 800; background: linear-gradient(135deg, #0f172a 0%, #334155 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        
        /* Animations */
        .stagger-1 { animation-delay: 100ms; }
        .stagger-2 { animation-delay: 200ms; }
        .stagger-3 { animation-delay: 300ms; }
        .stagger-4 { animation-delay: 400ms; }
        .stagger-5 { animation-delay: 500ms; }
    </style>
    <!-- Include Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <?= $extraHead ?>
</head>
<body class="bg-appbg text-gray-900 antialiased min-h-screen flex flex-col font-sans selection:bg-brand-500 selection:text-white">
    <!-- Top Navbar -->
    <nav class="bg-white/80 backdrop-blur-md border-b border-gray-200/80 sticky top-0 z-50 shadow-sm transition-all duration-300">
        <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center gap-3">
                    <div class="relative group cursor-pointer">
                        <div class="absolute -inset-0.5 bg-gradient-premium rounded-lg blur opacity-30 group-hover:opacity-100 transition duration-1000 group-hover:duration-200 animate-pulse-slow"></div>
                        <img src="<?= h(app_url('assets/images/logo.png')) ?>" alt="Logo" class="relative h-10 w-auto rounded-lg shadow-sm">
                    </div>
                    <span class="font-bold text-xl tracking-tight text-gradient hidden sm:block ml-2">Mashallah Communication</span>
                </div>
                <div class="flex items-center gap-4">
                    <a href="<?= h(app_url('settings/profile.php')) ?>" class="flex items-center gap-2 px-4 py-2 rounded-xl bg-gray-50 border border-gray-200/80 shadow-sm hover:shadow-md hover:border-brand-300 transition-all duration-300 group no-underline">
                        <?php if ($adminImage !== '' && file_exists(__DIR__ . '/../uploads/' . $adminImage)): ?>
                            <img src="<?= h(app_url('uploads/' . $adminImage)) ?>" alt="Profile" class="w-8 h-8 rounded-full object-cover shadow-sm border border-white">
                        <?php else: ?>
                            <div class="w-8 h-8 rounded-full bg-gradient-premium flex items-center justify-center text-white shadow-sm border border-white">
                                <span class="text-sm font-bold"><?= strtoupper(substr($adminName, 0, 1)) ?></span>
                            </div>
                        <?php endif; ?>
                        <span class="text-sm font-semibold text-gray-700 hidden md:block group-hover:text-brand-600 transition-colors">
                            <?= h($adminName) ?>
                        </span>
                    </a>
                    <a href="<?= h(app_url('auth/logout.php')) ?>" class="text-gray-500 hover:text-danger transition-all duration-300 p-2.5 rounded-xl hover:bg-red-50 hover:shadow-sm flex items-center justify-center group" title="Logout">
                        <i data-lucide="log-out" class="w-5 h-5 group-hover:scale-110 transition-transform"></i>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Layout -->
    <div class="flex-1 flex overflow-hidden relative">
        <!-- Decorative background blobs -->
        <div class="absolute top-0 left-0 w-full h-full overflow-hidden pointer-events-none -z-10">
            <div class="absolute top-[-10%] left-[-5%] w-96 h-96 bg-brand-500/10 rounded-full blur-[100px]"></div>
            <div class="absolute bottom-[-10%] right-[-5%] w-96 h-96 bg-pink-500/10 rounded-full blur-[100px]"></div>
            <div class="absolute top-[40%] left-[60%] w-64 h-64 bg-emerald-500/5 rounded-full blur-[80px]"></div>
        </div>
