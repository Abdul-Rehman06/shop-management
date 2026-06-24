<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Add Credit Customer - Shop Management';

$pdo = db();

$savedCustomers = [];
try {
    $stmt = $pdo->query("SELECT id, name, phone FROM customers ORDER BY updated_at DESC, id DESC LIMIT 300");
    $savedCustomers = $stmt->fetchAll();
} catch (Throwable $e) {
    $savedCustomers = [];
}

$name = '';
$phone = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));

    if ($customerId > 0 && ($name === '' || $phone === '')) {
        $stmt = $pdo->prepare("SELECT name, phone FROM customers WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $customerId]);
        $c = $stmt->fetch() ?: null;
        if ($c) {
            if ($name === '') {
                $name = (string) ($c['name'] ?? '');
            }
            if ($phone === '') {
                $phone = (string) ($c['phone'] ?? '');
            }
        }
    }

    if ($name === '') {
        $error = 'Customer name is required.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO credit_customers (name, phone, status)
            VALUES (:name, :phone, 'active')
        ");
        $stmt->execute([
            ':name' => $name,
            ':phone' => $phone !== '' ? $phone : null,
        ]);

        if ($phone !== '') {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO customers (name, phone)
                    VALUES (:name, :phone)
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name)
                ");
                $stmt->execute([':name' => $name, ':phone' => $phone]);
            } catch (Throwable $e) {
            }
        }

        $id = (int) $pdo->lastInsertId();
        flash_set('success', 'Customer added.');
        app_redirect('credit/view.php?id=' . $id);
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-4 animate-slide-up">
    <div>
        <h1 class="h3 mb-1 text-gray-800 font-bold">Add Credit Customer</h1>
        <div class="text-gray-500 text-sm">Create a new customer for advance balance</div>
    </div>
    <a class="btn btn-outline-secondary bg-white/60 border-0 shadow-sm hover-lift rounded-xl d-inline-flex align-items-center gap-2" href="<?= h(app_url('credit/index.php')) ?>">
        <i data-lucide="arrow-left" class="w-4 h-4"></i> Back
    </a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger border-0 shadow-sm animate-slide-up"><?= h($error) ?></div>
<?php endif; ?>

<div class="glass-card animate-slide-up stagger-1 max-w-4xl">
    <div class="card-body p-4 p-md-5">
        <form method="post" class="row g-4">
            <div class="col-12">
                <label class="form-label fw-semibold text-gray-700" for="saved_customer_select">Saved Customer</label>
                <select class="form-select form-select-lg bg-light border-0 shadow-none focus-ring" id="saved_customer_select" name="customer_id">
                    <option value="0">-- Select --</option>
                    <?php foreach ($savedCustomers as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" data-name="<?= h((string) $c['name']) ?>" data-phone="<?= h((string) $c['phone']) ?>">
                            <?= h((string) $c['name']) ?> • <?= h((string) $c['phone']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="mt-2 text-end">
                    <a class="text-primary text-decoration-none small fw-medium hover-lift d-inline-block" href="<?= h(app_url('settings/customers.php')) ?>">
                            <i data-lucide="user-plus" class="w-4 h-4 inline-block mr-1"></i> Add / Manage Customers
                        </a>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold text-gray-700" for="name">Customer Name <span class="text-danger">*</span></label>
                <input class="form-control form-control-lg bg-light border-0 shadow-none focus-ring" type="text" id="name" name="name" value="<?= h($name) ?>" required placeholder="Enter customer name">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold text-gray-700" for="phone">Phone</label>
                <input class="form-control form-control-lg bg-light border-0 shadow-none focus-ring" type="text" id="phone" name="phone" value="<?= h($phone) ?>" placeholder="e.g. 03001234567">
            </div>
            <div class="col-12 mt-5 d-flex gap-3">
                <button class="btn btn-gradient shadow-glow btn-lg px-5 rounded-xl hover-lift d-inline-flex align-items-center gap-2">
                    <i data-lucide="check" class="w-5 h-5"></i> Save Customer
                </button>
                <a class="btn btn-outline-secondary bg-white/60 border-0 shadow-sm btn-lg px-4 rounded-xl hover-lift text-muted" href="<?= h(app_url('credit/index.php')) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        const sel = document.getElementById('saved_customer_select');
        const nameInput = document.getElementById('name');
        const phoneInput = document.getElementById('phone');
        if (!sel || !nameInput || !phoneInput) return;
        sel.addEventListener('change', () => {
            const opt = sel.options[sel.selectedIndex];
            const n = opt ? (opt.getAttribute('data-name') || '') : '';
            const p = opt ? (opt.getAttribute('data-phone') || '') : '';
            if (n) nameInput.value = n;
            if (p) phoneInput.value = p;
        });
    })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
