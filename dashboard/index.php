<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

function money($value): string
{
    return number_format((float) $value, 2);
}

$pdo = db();

$networks = ['Jazz', 'Zong', 'Ufone', 'Telenor'];

$loadBalances = array_fill_keys($networks, 0.0);
$stmt = $pdo->query("
    SELECT t.network, t.closing_balance
    FROM load_transactions t
    WHERE t.id = (
        SELECT t2.id
        FROM load_transactions t2
        WHERE t2.network = t.network
        ORDER BY t2.date DESC, t2.id DESC
        LIMIT 1
    )
");
foreach ($stmt->fetchAll() as $row) {
    $network = (string) ($row['network'] ?? '');
    if ($network !== '' && array_key_exists($network, $loadBalances)) {
        $loadBalances[$network] = (float) $row['closing_balance'];
    }
}
$loadTotalBalance = array_sum($loadBalances);

$easypaisaNet = (float) $pdo->query("
    SELECT COALESCE(SUM(CASE WHEN type='receiving' THEN amount ELSE -amount END), 0)
    FROM easypaisa_transactions
")->fetchColumn();

$jazzcashNet = (float) $pdo->query("
    SELECT COALESCE(SUM(CASE WHEN type='receiving' THEN amount ELSE -amount END), 0)
    FROM jazzcash_transactions
")->fetchColumn();

$bankNet = (float) $pdo->query("
    SELECT COALESCE(SUM(CASE WHEN type='receiving' THEN amount ELSE -amount END), 0)
    FROM bank_transactions
")->fetchColumn();

$todayExpense = (float) $pdo->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM expenses
    WHERE date = CURDATE()
")->fetchColumn();

$monthlyExpense = (float) $pdo->query("
    SELECT COALESCE(SUM(amount), 0)
    FROM expenses
    WHERE date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND date <= LAST_DAY(CURDATE())
")->fetchColumn();

$todayProfit = (float) $pdo->query("
    SELECT COALESCE(SUM(profit), 0)
    FROM sales
    WHERE DATE(created_at) = CURDATE()
")->fetchColumn();

$monthlyProfit = (float) $pdo->query("
    SELECT COALESCE(SUM(profit), 0)
    FROM sales
    WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01 00:00:00')
      AND created_at <= CONCAT(LAST_DAY(CURDATE()), ' 23:59:59')
")->fetchColumn();

$dailySalesData = [];
$stmt = $pdo->query("
    SELECT DATE(created_at) AS d, COALESCE(SUM(quantity * sale_price), 0) AS total
    FROM sales
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY d ASC
");
foreach ($stmt->fetchAll() as $row) {
    $dailySalesData[(string) $row['d']] = (float) $row['total'];
}

$dailySalesLabels = [];
$dailySalesTotals = [];
for ($i = 6; $i >= 0; $i--) {
    $d = (new DateTimeImmutable('today'))->modify("-{$i} days")->format('Y-m-d');
    $dailySalesLabels[] = $d;
    $dailySalesTotals[] = $dailySalesData[$d] ?? 0.0;
}

$monthlyProfitData = [];
$stmt = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COALESCE(SUM(profit), 0) AS total
    FROM sales
    WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY m ASC
");
foreach ($stmt->fetchAll() as $row) {
    $monthlyProfitData[(string) $row['m']] = (float) $row['total'];
}

$monthlyProfitLabels = [];
$monthlyProfitTotals = [];
$monthCursor = new DateTimeImmutable(date('Y-m-01'));
$monthCursor = $monthCursor->modify('-11 months');
for ($i = 0; $i < 12; $i++) {
    $m = $monthCursor->format('Y-m');
    $monthlyProfitLabels[] = $m;
    $monthlyProfitTotals[] = $monthlyProfitData[$m] ?? 0.0;
    $monthCursor = $monthCursor->modify('+1 month');
}

$pageTitle = 'Dashboard - Shop Management';
$extraHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>';

?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-2xl border border-gray-200 shadow-sm relative overflow-hidden">
        <div class="absolute right-0 top-0 w-64 h-64 bg-brand-50 rounded-full blur-3xl -z-10 translate-x-1/2 -translate-y-1/2"></div>
        <div>
            <h1 class="text-3xl font-bold text-gray-900 tracking-tight mb-1">
                Welcome back, <?= h((string) ($admin['name'] ?? 'Admin')) ?> 👋
            </h1>
            <p class="text-gray-500">Here's what's happening in your shop today, <span class="font-medium text-brand-600"><?= date('F j, Y') ?></span>.</p>
        </div>
        <div class="flex flex-wrap gap-3 z-10">
            <a href="<?= h(app_url('load-management/sale.php')) ?>" class="btn btn-outline-primary bg-white hover:bg-brand-50">
                <i data-lucide="smartphone" class="w-4 h-4"></i> New Load
            </a>
            <a href="<?= h(app_url('expenses/add.php')) ?>" class="btn btn-outline-danger bg-white hover:bg-red-50">
                <i data-lucide="receipt" class="w-4 h-4"></i> Add Expense
            </a>
            <a href="<?= h(app_url('sales/add.php')) ?>" class="btn btn-primary shadow-md hover:shadow-lg">
                <i data-lucide="shopping-cart" class="w-4 h-4"></i> New Sale
            </a>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Load Summary -->
    <div class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:-translate-y-1 transition-all duration-300">
        <div class="absolute -right-6 -top-6 w-24 h-24 bg-blue-50 rounded-full group-hover:scale-150 transition-transform duration-500 ease-out z-0"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Load Summary</div>
                <div class="p-2 bg-blue-100 text-blue-600 rounded-lg"><i data-lucide="smartphone" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-bold text-gray-900 tracking-tight">Rs <?= money($loadTotalBalance) ?></div>
        </div>
    </div>

    <!-- Jazz Balance -->
    <div class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:-translate-y-1 transition-all duration-300">
        <div class="absolute -right-6 -top-6 w-24 h-24 bg-red-50 rounded-full group-hover:scale-150 transition-transform duration-500 ease-out z-0"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Jazz Balance</div>
                <div class="p-2 bg-red-100 text-red-600 rounded-lg"><i data-lucide="signal" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-bold text-gray-900 tracking-tight">Rs <?= money($loadBalances['Jazz'] ?? 0) ?></div>
        </div>
    </div>

    <!-- Zong Balance -->
    <div class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:-translate-y-1 transition-all duration-300">
        <div class="absolute -right-6 -top-6 w-24 h-24 bg-green-50 rounded-full group-hover:scale-150 transition-transform duration-500 ease-out z-0"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Zong Balance</div>
                <div class="p-2 bg-green-100 text-green-600 rounded-lg"><i data-lucide="radio-tower" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-bold text-gray-900 tracking-tight">Rs <?= money($loadBalances['Zong'] ?? 0) ?></div>
        </div>
    </div>

    <!-- Ufone Balance -->
    <div class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:-translate-y-1 transition-all duration-300">
        <div class="absolute -right-6 -top-6 w-24 h-24 bg-orange-50 rounded-full group-hover:scale-150 transition-transform duration-500 ease-out z-0"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Ufone Balance</div>
                <div class="p-2 bg-orange-100 text-orange-500 rounded-lg"><i data-lucide="wifi" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-bold text-gray-900 tracking-tight">Rs <?= money($loadBalances['Ufone'] ?? 0) ?></div>
        </div>
    </div>

    <!-- Telenor Balance -->
    <div class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:-translate-y-1 transition-all duration-300">
        <div class="absolute -right-6 -top-6 w-24 h-24 bg-cyan-50 rounded-full group-hover:scale-150 transition-transform duration-500 ease-out z-0"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Telenor Balance</div>
                <div class="p-2 bg-cyan-100 text-cyan-600 rounded-lg"><i data-lucide="rss" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-bold text-gray-900 tracking-tight">Rs <?= money($loadBalances['Telenor'] ?? 0) ?></div>
        </div>
    </div>

    <!-- EasyPaisa Total -->
    <div class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:-translate-y-1 transition-all duration-300">
        <div class="absolute -right-6 -top-6 w-24 h-24 bg-emerald-50 rounded-full group-hover:scale-150 transition-transform duration-500 ease-out z-0"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">EasyPaisa</div>
                <div class="p-2 bg-emerald-100 text-emerald-600 rounded-lg"><i data-lucide="wallet" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-bold text-gray-900 tracking-tight">Rs <?= money($easypaisaNet) ?></div>
        </div>
    </div>

    <!-- JazzCash Total -->
    <div class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:-translate-y-1 transition-all duration-300">
        <div class="absolute -right-6 -top-6 w-24 h-24 bg-rose-50 rounded-full group-hover:scale-150 transition-transform duration-500 ease-out z-0"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">JazzCash</div>
                <div class="p-2 bg-rose-100 text-rose-600 rounded-lg"><i data-lucide="circle-dollar-sign" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-bold text-gray-900 tracking-tight">Rs <?= money($jazzcashNet) ?></div>
        </div>
    </div>

    <!-- Bank Transfer Total -->
    <div class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:-translate-y-1 transition-all duration-300">
        <div class="absolute -right-6 -top-6 w-24 h-24 bg-indigo-50 rounded-full group-hover:scale-150 transition-transform duration-500 ease-out z-0"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Bank Transfer</div>
                <div class="p-2 bg-indigo-100 text-indigo-600 rounded-lg"><i data-lucide="building-2" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-bold text-gray-900 tracking-tight">Rs <?= money($bankNet) ?></div>
        </div>
    </div>

    <!-- Today Expense -->
    <div class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:-translate-y-1 transition-all duration-300">
        <div class="absolute -right-6 -top-6 w-24 h-24 bg-red-50 rounded-full group-hover:scale-150 transition-transform duration-500 ease-out z-0"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Today's Expense</div>
                <div class="p-2 bg-red-100 text-red-600 rounded-lg"><i data-lucide="trending-down" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-bold text-danger tracking-tight">Rs <?= money($todayExpense) ?></div>
        </div>
    </div>

    <!-- Monthly Expense -->
    <div class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:-translate-y-1 transition-all duration-300">
        <div class="absolute -right-6 -top-6 w-24 h-24 bg-red-50 rounded-full group-hover:scale-150 transition-transform duration-500 ease-out z-0"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Monthly Expense</div>
                <div class="p-2 bg-red-100 text-red-600 rounded-lg"><i data-lucide="calendar-x" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-bold text-danger tracking-tight">Rs <?= money($monthlyExpense) ?></div>
        </div>
    </div>

    <!-- Today Profit -->
    <div class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:-translate-y-1 transition-all duration-300">
        <div class="absolute -right-6 -top-6 w-24 h-24 bg-green-50 rounded-full group-hover:scale-150 transition-transform duration-500 ease-out z-0"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Today's Profit</div>
                <div class="p-2 bg-green-100 text-green-600 rounded-lg"><i data-lucide="trending-up" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-bold text-success tracking-tight">Rs <?= money($todayProfit) ?></div>
        </div>
    </div>

    <!-- Monthly Profit -->
    <div class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:-translate-y-1 transition-all duration-300">
        <div class="absolute -right-6 -top-6 w-24 h-24 bg-green-50 rounded-full group-hover:scale-150 transition-transform duration-500 ease-out z-0"></div>
        <div class="relative z-10">
            <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Monthly Profit</div>
                <div class="p-2 bg-green-100 text-green-600 rounded-lg"><i data-lucide="calendar-check" class="w-5 h-5"></i></div>
            </div>
            <div class="text-3xl font-bold text-success tracking-tight">Rs <?= money($monthlyProfit) ?></div>
        </div>
    </div>
</div>

<div class="row g-4 mb-8">
    <div class="col-12 col-lg-6">
        <div class="glass-card rounded-2xl p-6 h-100 relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-center mb-6">
                <div>
                    <h3 class="h5 fw-bold text-gray-900 mb-1">Daily Sales</h3>
                    <div class="text-muted small">Revenue over last 7 days</div>
                </div>
                <div class="p-2 bg-blue-50 text-brand-600 rounded-lg"><i data-lucide="activity" class="w-5 h-5"></i></div>
            </div>
            <div class="relative h-64 w-full">
                <canvas id="dailySalesChart"></canvas>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="glass-card rounded-2xl p-6 h-100 relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-center mb-6">
                <div>
                    <h3 class="h5 fw-bold text-gray-900 mb-1">Monthly Profit</h3>
                    <div class="text-muted small">Earnings over last 12 months</div>
                </div>
                <div class="p-2 bg-green-50 text-success rounded-lg"><i data-lucide="bar-chart-2" class="w-5 h-5"></i></div>
            </div>
            <div class="relative h-64 w-full">
                <canvas id="monthlyProfitChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Quick Navigation Grid -->
<div class="mb-4">
    <h3 class="text-xl font-bold text-gray-900 mb-4">Quick Navigation</h3>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <a href="<?= h(app_url('load-management/index.php')) ?>" class="glass-card rounded-xl p-4 text-center hover:-translate-y-1 transition-all group flex flex-col items-center justify-center gap-2 text-gray-600 hover:text-brand-600">
            <div class="w-12 h-12 rounded-full bg-gray-50 flex items-center justify-center group-hover:bg-brand-50 transition-colors">
                <i data-lucide="smartphone" class="w-6 h-6"></i>
            </div>
            <span class="text-sm font-medium">Load</span>
        </a>
        <a href="<?= h(app_url('easypaisa/index.php')) ?>" class="glass-card rounded-xl p-4 text-center hover:-translate-y-1 transition-all group flex flex-col items-center justify-center gap-2 text-gray-600 hover:text-emerald-600">
            <div class="w-12 h-12 rounded-full bg-gray-50 flex items-center justify-center group-hover:bg-emerald-50 transition-colors">
                <i data-lucide="wallet" class="w-6 h-6"></i>
            </div>
            <span class="text-sm font-medium">EasyPaisa</span>
        </a>
        <a href="<?= h(app_url('jazzcash/index.php')) ?>" class="glass-card rounded-xl p-4 text-center hover:-translate-y-1 transition-all group flex flex-col items-center justify-center gap-2 text-gray-600 hover:text-rose-600">
            <div class="w-12 h-12 rounded-full bg-gray-50 flex items-center justify-center group-hover:bg-rose-50 transition-colors">
                <i data-lucide="circle-dollar-sign" class="w-6 h-6"></i>
            </div>
            <span class="text-sm font-medium">JazzCash</span>
        </a>
        <a href="<?= h(app_url('bank-transfer/index.php')) ?>" class="glass-card rounded-xl p-4 text-center hover:-translate-y-1 transition-all group flex flex-col items-center justify-center gap-2 text-gray-600 hover:text-indigo-600">
            <div class="w-12 h-12 rounded-full bg-gray-50 flex items-center justify-center group-hover:bg-indigo-50 transition-colors">
                <i data-lucide="building-2" class="w-6 h-6"></i>
            </div>
            <span class="text-sm font-medium">Bank</span>
        </a>
        <a href="<?= h(app_url('inventory/index.php')) ?>" class="glass-card rounded-xl p-4 text-center hover:-translate-y-1 transition-all group flex flex-col items-center justify-center gap-2 text-gray-600 hover:text-orange-600">
            <div class="w-12 h-12 rounded-full bg-gray-50 flex items-center justify-center group-hover:bg-orange-50 transition-colors">
                <i data-lucide="package" class="w-6 h-6"></i>
            </div>
            <span class="text-sm font-medium">Inventory</span>
        </a>
        <a href="<?= h(app_url('reports/index.php')) ?>" class="glass-card rounded-xl p-4 text-center hover:-translate-y-1 transition-all group flex flex-col items-center justify-center gap-2 text-gray-600 hover:text-cyan-600">
            <div class="w-12 h-12 rounded-full bg-gray-50 flex items-center justify-center group-hover:bg-cyan-50 transition-colors">
                <i data-lucide="bar-chart-3" class="w-6 h-6"></i>
            </div>
            <span class="text-sm font-medium">Reports</span>
        </a>
    </div>
</div>

<script>
    const dailySalesLabels = <?= json_encode($dailySalesLabels, JSON_UNESCAPED_SLASHES) ?>;
    const dailySalesTotals = <?= json_encode($dailySalesTotals, JSON_UNESCAPED_SLASHES) ?>;
    const monthlyProfitLabels = <?= json_encode($monthlyProfitLabels, JSON_UNESCAPED_SLASHES) ?>;
    const monthlyProfitTotals = <?= json_encode($monthlyProfitTotals, JSON_UNESCAPED_SLASHES) ?>;

    const brandColor = '#3B82F6';
    const successColor = '#10B981';
    const gridColor = '#E5E7EB';
    const textColor = '#6B7280';

    Chart.defaults.color = textColor;
    Chart.defaults.font.family = '"Plus Jakarta Sans", sans-serif';

    new Chart(document.getElementById('dailySalesChart'), {
        type: 'line',
        data: {
            labels: dailySalesLabels,
            datasets: [{
                label: 'Sales Revenue',
                data: dailySalesTotals,
                borderColor: brandColor,
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 3,
                pointBackgroundColor: '#FFFFFF',
                pointBorderColor: brandColor,
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false, backgroundColor: '#111827', titleColor: '#fff', bodyColor: '#fff', borderColor: 'rgba(0,0,0,0.1)', borderWidth: 1 } },
            scales: {
                x: { grid: { display: false, drawBorder: false } },
                y: { grid: { color: gridColor, drawBorder: false }, beginAtZero: true }
            },
            interaction: { mode: 'nearest', axis: 'x', intersect: false }
        }
    });

    new Chart(document.getElementById('monthlyProfitChart'), {
        type: 'bar',
        data: {
            labels: monthlyProfitLabels,
            datasets: [{
                label: 'Profit',
                data: monthlyProfitTotals,
                backgroundColor: 'rgba(16, 185, 129, 0.8)',
                hoverBackgroundColor: successColor,
                borderRadius: 4,
                borderSkipped: false,
                barThickness: 'flex',
                maxBarThickness: 40
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { backgroundColor: '#111827', titleColor: '#fff', bodyColor: '#fff', borderColor: 'rgba(0,0,0,0.1)', borderWidth: 1 } },
            scales: {
                x: { grid: { display: false, drawBorder: false } },
                y: { grid: { color: gridColor, drawBorder: false }, beginAtZero: true }
            }
        }
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
