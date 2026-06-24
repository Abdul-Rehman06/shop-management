<?php

declare(strict_types=1);

$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$canManageStock = app_can_manage_stock();

function isActive(string $path, string $currentPath): string {
    return strpos($currentPath, $path) !== false 
        ? 'bg-gradient-premium text-white shadow-md shadow-brand-500/20 translate-x-1' 
        : 'text-gray-600 hover:bg-brand-50 hover:text-brand-600 hover:translate-x-1';
}
?>
<aside class="w-72 bg-white/80 backdrop-blur-xl border-r border-gray-200/80 flex-shrink-0 hidden md:flex flex-col overflow-y-auto shadow-[4px_0_24px_rgba(0,0,0,0.02)] z-10">
    <div class="py-8 px-5">
        <div class="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-4 pl-3">Main Menu</div>
        <nav class="space-y-1.5">
            <a class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-[15px] font-semibold transition-all duration-300 <?= isActive('dashboard/index.php', $currentPath) ?>" href="<?= h(app_url('dashboard/index.php')) ?>">
                <i data-lucide="layout-dashboard" class="w-5 h-5 <?= strpos($currentPath, 'dashboard/index.php') !== false ? 'text-white' : 'text-gray-400 group-hover:text-brand-500' ?>"></i> Dashboard
            </a>
            <a class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-[15px] font-semibold transition-all duration-300 <?= isActive('mobile-accounts', $currentPath) ?>" href="<?= h(app_url('mobile-accounts/index.php')) ?>">
                <i data-lucide="wallet-cards" class="w-5 h-5 <?= strpos($currentPath, 'mobile-accounts') !== false ? 'text-white' : 'text-gray-400 group-hover:text-brand-500' ?>"></i> Mobile Accounts
            </a>
            <a class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-[15px] font-semibold transition-all duration-300 <?= isActive('load-management', $currentPath) ?>" href="<?= h(app_url('load-management/index.php')) ?>">
                <i data-lucide="smartphone" class="w-5 h-5 <?= strpos($currentPath, 'load-management') !== false ? 'text-white' : 'text-gray-400 group-hover:text-brand-500' ?>"></i> Load Management
            </a>
            <a class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-[15px] font-semibold transition-all duration-300 <?= isActive('udhar', $currentPath) ?>" href="<?= h(app_url('udhar/index.php')) ?>">
                <i data-lucide="hand-coins" class="w-5 h-5 <?= strpos($currentPath, 'udhar') !== false ? 'text-white' : 'text-gray-400 group-hover:text-brand-500' ?>"></i> Udhar
            </a>
            <a class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-[15px] font-semibold transition-all duration-300 <?= isActive('credit', $currentPath) ?>" href="<?= h(app_url('credit/index.php')) ?>">
                <i data-lucide="circle-dollar-sign" class="w-5 h-5 <?= strpos($currentPath, 'credit') !== false ? 'text-white' : 'text-gray-400 group-hover:text-brand-500' ?>"></i> Credit
            </a>
            <?php if (app_is_owner()): ?>
                <a class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-[15px] font-semibold transition-all duration-300 <?= isActive('settings/customers.php', $currentPath) ?>" href="<?= h(app_url('settings/customers.php')) ?>">
                    <i data-lucide="contact" class="w-5 h-5 <?= strpos($currentPath, 'settings/customers.php') !== false ? 'text-white' : 'text-gray-400 group-hover:text-brand-500' ?>"></i> Customers
                </a>
            <?php endif; ?>
            <a class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-[15px] font-semibold transition-all duration-300 <?= isActive('bank-deposits', $currentPath) ?>" href="<?= h(app_url('bank-deposits/index.php')) ?>">
                <i data-lucide="landmark" class="w-5 h-5 <?= strpos($currentPath, 'bank-deposits') !== false ? 'text-white' : 'text-gray-400 group-hover:text-brand-500' ?>"></i> Bank Deposits
            </a>
            <a class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-[15px] font-semibold transition-all duration-300 <?= isActive('dealer-payments', $currentPath) ?>" href="<?= h(app_url('dealer-payments/index.php')) ?>">
                <i data-lucide="users" class="w-5 h-5 <?= strpos($currentPath, 'dealer-payments') !== false ? 'text-white' : 'text-gray-400 group-hover:text-brand-500' ?>"></i> Dealer Payments
            </a>
            <a class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-[15px] font-semibold transition-all duration-300 <?= isActive('cash-management', $currentPath) ?>" href="<?= h(app_url('cash-management/index.php')) ?>">
                <i data-lucide="banknote" class="w-5 h-5 <?= strpos($currentPath, 'cash-management') !== false ? 'text-white' : 'text-gray-400 group-hover:text-brand-500' ?>"></i> Cash Management
            </a>
            <a class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-[15px] font-semibold transition-all duration-300 <?= isActive('expenses', $currentPath) ?>" href="<?= h(app_url('expenses/index.php')) ?>">
                <i data-lucide="receipt" class="w-5 h-5 <?= strpos($currentPath, 'expenses') !== false ? 'text-white' : 'text-gray-400 group-hover:text-brand-500' ?>"></i> Expenses
            </a>
            <?php if ($canManageStock): ?>
                <a class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-[15px] font-semibold transition-all duration-300 <?= isActive('inventory', $currentPath) ?>" href="<?= h(app_url('inventory/index.php')) ?>">
                    <i data-lucide="package" class="w-5 h-5 <?= strpos($currentPath, 'inventory') !== false ? 'text-white' : 'text-gray-400 group-hover:text-brand-500' ?>"></i> Inventory
                </a>
            <?php endif; ?>
            <a class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-[15px] font-semibold transition-all duration-300 <?= isActive('sales', $currentPath) ?>" href="<?= h(app_url('sales/index.php')) ?>">
                <i data-lucide="shopping-cart" class="w-5 h-5 <?= strpos($currentPath, 'sales') !== false ? 'text-white' : 'text-gray-400 group-hover:text-brand-500' ?>"></i> Sales
            </a>
        </nav>

        <div class="text-[11px] font-bold text-gray-400 uppercase tracking-widest mt-10 mb-4 pl-3">System</div>
        <nav class="space-y-1.5">
            <a class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-[15px] font-semibold transition-all duration-300 <?= isActive('reports', $currentPath) ?>" href="<?= h(app_url('reports/index.php')) ?>">
                <i data-lucide="bar-chart-3" class="w-5 h-5 <?= strpos($currentPath, 'reports') !== false ? 'text-white' : 'text-gray-400 group-hover:text-brand-500' ?>"></i> Reports
            </a>
            <a class="flex items-center gap-3.5 px-4 py-3 rounded-xl text-[15px] font-semibold transition-all duration-300 <?= isActive('settings', $currentPath) ?>" href="<?= h(app_url('settings/index.php')) ?>">
                <i data-lucide="settings" class="w-5 h-5 <?= strpos($currentPath, 'settings') !== false ? 'text-white' : 'text-gray-400 group-hover:text-brand-500' ?>"></i> Settings
            </a>
        </nav>
        
        <!-- Sidebar Footer / Premium Banner -->
        <div class="mt-8 mb-4">
            <a href="<?= h(app_url('auth/logout.php')) ?>" class="flex items-center justify-center gap-2 w-full py-3 px-4 bg-red-50 hover:bg-red-100 text-red-600 font-bold rounded-xl transition-all duration-300 border border-red-100 hover:border-red-200 shadow-sm hover:shadow group no-underline">
                <i data-lucide="log-out" class="w-5 h-5 group-hover:-translate-x-1 transition-transform"></i> Logout
            </a>
        </div>

        <div class="p-4 bg-gradient-to-br from-brand-50 to-pink-50 rounded-2xl border border-brand-100/50 shadow-sm relative overflow-hidden group">
            <div class="absolute -right-4 -top-4 w-16 h-16 bg-brand-500/10 rounded-full group-hover:scale-150 transition-transform duration-500"></div>
            <div class="relative z-10">
                <div class="flex items-center gap-2 mb-2">
                    <i data-lucide="shield-check" class="w-5 h-5 text-brand-600"></i>
                    <span class="font-bold text-gray-900 text-sm">Secure System</span>
                </div>
                <p class="text-xs text-gray-500 leading-relaxed">End-to-end encrypted shop management system.</p>
            </div>
        </div>
    </div>
</aside>
<main class="flex-1 overflow-y-auto bg-transparent p-4 sm:p-6 lg:p-8 animate-fade-in relative z-0">
