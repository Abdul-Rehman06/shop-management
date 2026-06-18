<?php

declare(strict_types=1);

$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$canManageStock = app_can_manage_stock();
$canViewProfit = app_can_view_profit();

function isActive(string $path, string $currentPath): string {
    return strpos($currentPath, $path) !== false ? 'bg-brand-50 text-brand-600 border-r-2 border-brand-600' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900';
}
?>
<aside class="w-64 bg-white border-r border-gray-200 flex-shrink-0 hidden md:flex flex-col overflow-y-auto">
    <div class="py-6 px-4">
        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-4">Main Menu</div>
        <nav class="space-y-1">
            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= isActive('dashboard/index.php', $currentPath) ?>" href="<?= h(app_url('dashboard/index.php')) ?>">
                <i data-lucide="layout-dashboard" class="w-5 h-5"></i> Dashboard
            </a>
            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= isActive('mobile-accounts', $currentPath) ?>" href="<?= h(app_url('mobile-accounts/index.php')) ?>">
                <i data-lucide="wallet-cards" class="w-5 h-5"></i> Mobile Accounts
            </a>
            <?php if ($canViewProfit): ?>
                <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= isActive('load-management', $currentPath) ?>" href="<?= h(app_url('load-management/index.php')) ?>">
                    <i data-lucide="smartphone" class="w-5 h-5"></i> Load Management
                </a>
            <?php endif; ?>
            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= isActive('expenses', $currentPath) ?>" href="<?= h(app_url('expenses/index.php')) ?>">
                <i data-lucide="receipt" class="w-5 h-5"></i> Expenses
            </a>
            <?php if ($canManageStock): ?>
                <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= isActive('inventory', $currentPath) ?>" href="<?= h(app_url('inventory/index.php')) ?>">
                    <i data-lucide="package" class="w-5 h-5"></i> Inventory
                </a>
            <?php endif; ?>
            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= isActive('sales', $currentPath) ?>" href="<?= h(app_url('sales/index.php')) ?>">
                <i data-lucide="shopping-cart" class="w-5 h-5"></i> Sales
            </a>
        </nav>

        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mt-8 mb-4">System</div>
        <nav class="space-y-1">
            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= isActive('reports', $currentPath) ?>" href="<?= h(app_url('reports/index.php')) ?>">
                <i data-lucide="bar-chart-3" class="w-5 h-5"></i> Reports
            </a>
            <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors <?= isActive('settings', $currentPath) ?>" href="<?= h(app_url('settings/index.php')) ?>">
                <i data-lucide="settings" class="w-5 h-5"></i> Settings
            </a>
        </nav>
    </div>
</aside>
<main class="flex-1 overflow-y-auto bg-appbg p-4 sm:p-6 lg:p-8">
