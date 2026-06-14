<?php

declare(strict_types=1);

$admin = app_current_admin();
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
                        appbg: '#F3F4F6',
                        sidebar: '#FFFFFF',
                        card: '#FFFFFF',
                        cardhover: '#F9FAFB',
                        brand: {
                            500: '#3B82F6',
                            600: '#2563EB',
                            gradientStart: '#3B82F6',
                            gradientEnd: '#9333EA',
                        },
                        success: '#10B981',
                        danger: '#EF4444',
                        info: '#3B82F6',
                        bordercolor: '#E5E7EB',
                    }
                }
            }
        }
    </script>
    <style>
        body {
            background-color: #F3F4F6 !important;
            color: #111827 !important;
        }
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #F3F4F6; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94A3B8; }
        
        /* Glassmorphism Utilities */
        .glass-card {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        }
        .text-gradient {
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-image: linear-gradient(to right, #3B82F6, #9333EA);
        }
        .btn-gradient {
            background-image: linear-gradient(to right, #3B82F6, #9333EA);
            color: white;
            transition: all 0.3s ease;
        }
        .btn-gradient:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }
        .input-glow:focus {
            border-color: #3B82F6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
            outline: none;
        }

        /* --- GLOBAL BOOTSTRAP OVERRIDES FOR PREMIUM $100M UI --- */
        /* Cards */
        .card { background: #FFFFFF !important; border: 1px solid #E5E7EB !important; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05) !important; color: #111827 !important; border-radius: 1rem !important; }
        .card-header, .card-footer { background: transparent !important; border-color: #E5E7EB !important; }
        
        /* Tables */
        .table { color: #374151 !important; border-color: #E5E7EB !important; margin-bottom: 0 !important; }
        .table-light { background: #F9FAFB !important; color: #4B5563 !important; }
        .table-light th, .table thead th { background: #F9FAFB !important; color: #4B5563 !important; border-bottom: 1px solid #E5E7EB !important; font-weight: 600 !important; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; padding: 1rem 1.5rem !important; }
        .table-striped>tbody>tr:nth-of-type(odd)>* { background-color: #F9FAFB !important; color: #374151 !important; }
        .table-hover>tbody>tr:hover>* { background-color: #F3F4F6 !important; color: #111827 !important; }
        .table td, .table th { border-color: #E5E7EB !important; padding: 1rem 1.5rem !important; vertical-align: middle !important; }
        
        /* Buttons */
        .btn { border-radius: 0.5rem !important; padding: 0.5rem 1rem !important; font-weight: 500 !important; transition: all 0.2s !important; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .btn-sm { padding: 0.375rem 0.75rem !important; font-size: 0.875rem !important; }
        .btn-primary { background-image: linear-gradient(to right, #3B82F6, #9333EA) !important; border: none !important; color: white !important; box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2) !important; }
        .btn-primary:hover { box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4) !important; transform: translateY(-1px); }
        .btn-outline-primary { border-color: #3B82F6 !important; color: #3B82F6 !important; background: transparent !important; }
        .btn-outline-primary:hover { background: #3B82F6 !important; color: white !important; }
        .btn-secondary { background: #F1F5F9 !important; border-color: #E2E8F0 !important; color: #475569 !important; }
        .btn-secondary:hover { background: #E2E8F0 !important; border-color: #CBD5E1 !important; color: #0F172A !important; }
        .btn-outline-secondary { border-color: #CBD5E1 !important; color: #475569 !important; background: transparent !important; }
        .btn-outline-secondary:hover { background: #F1F5F9 !important; color: #0F172A !important; }
        .btn-danger { background: #EF4444 !important; border-color: #EF4444 !important; color: white !important; }
        .btn-outline-danger { border-color: #EF4444 !important; color: #EF4444 !important; background: transparent !important; }
        .btn-outline-danger:hover { background: #EF4444 !important; color: white !important; }
        
        /* Forms */
        .form-control, .form-select { background-color: #FFFFFF !important; border: 1px solid #D1D5DB !important; color: #111827 !important; border-radius: 0.5rem !important; padding: 0.6rem 1rem !important; transition: all 0.2s; }
        .form-control:focus, .form-select:focus { border-color: #3B82F6 !important; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2) !important; background-color: #FFFFFF !important; color: #111827 !important; }
        .form-control::placeholder { color: #9CA3AF !important; }
        .form-label { color: #374151 !important; font-weight: 500 !important; margin-bottom: 0.5rem !important; font-size: 0.875rem !important; }
        
        /* Text & Headings */
        h1, h2, h3, h4, h5, h6, .h1, .h2, .h3, .h4, .h5, .h6 { color: #111827 !important; font-weight: 700 !important; }
        .text-muted { color: #6B7280 !important; }
        
        /* Badges */
        .badge.bg-danger { background-color: #FEE2E2 !important; color: #EF4444 !important; border: 1px solid #FCA5A5 !important; border-radius: 9999px !important; padding: 0.35em 0.65em !important; font-weight: 600 !important; }
        .badge.bg-success { background-color: #D1FAE5 !important; color: #10B981 !important; border: 1px solid #6EE7B7 !important; border-radius: 9999px !important; padding: 0.35em 0.65em !important; font-weight: 600 !important; }
        .badge.bg-warning { background-color: #FEF9C3 !important; color: #EAB308 !important; border: 1px solid #FDE047 !important; border-radius: 9999px !important; padding: 0.35em 0.65em !important; font-weight: 600 !important; }
        
        /* Alerts */
        .alert-success { background-color: #D1FAE5 !important; border-color: #A7F3D0 !important; color: #065F46 !important; border-radius: 0.75rem !important; }
        .alert-danger { background-color: #FEE2E2 !important; border-color: #FECACA !important; color: #991B1B !important; border-radius: 0.75rem !important; }
        
        /* Utility overrides */
        .bg-white, .bg-light { background-color: transparent !important; }
        .text-dark { color: #111827 !important; }
        .border-end { border-right-color: #E5E7EB !important; }
        .border-bottom { border-bottom-color: #E5E7EB !important; }
        .shadow-sm { box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05) !important; }
    </style>
    <!-- Include Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <?= $extraHead ?>
</head>
<body class="bg-appbg text-gray-900 antialiased min-h-screen flex flex-col">
    <!-- Top Navbar -->
    <nav class="bg-sidebar border-b border-bordercolor sticky top-0 z-50 shadow-sm">
        <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center gap-3">
                    <img src="<?= h(app_url('assets/images/logo.png')) ?>" alt="Logo" class="h-10 w-auto rounded">
                    <span class="font-bold text-xl tracking-tight text-gradient hidden sm:block">Mashallah Communication</span>
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-gray-50 border border-gray-200">
                        <i data-lucide="user" class="w-4 h-4 text-brand-600"></i>
                        <span class="text-sm font-medium text-gray-700">
                            <?= h((string) ($admin['name'] ?? 'Admin')) ?>
                        </span>
                    </div>
                    <a href="<?= h(app_url('auth/logout.php')) ?>" class="text-gray-500 hover:text-danger transition-colors p-2 rounded-full hover:bg-gray-100">
                        <i data-lucide="log-out" class="w-5 h-5"></i>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Layout -->
    <div class="flex-1 flex overflow-hidden">
