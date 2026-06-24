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

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Add Credit Customer</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('credit/index.php')) ?>">Back</a>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-12">
                <label class="form-label" for="saved_customer_select">Saved Customer</label>
                <select class="form-select" id="saved_customer_select" name="customer_id">
                    <option value="0">-- Select --</option>
                    <?php foreach ($savedCustomers as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" data-name="<?= h((string) $c['name']) ?>" data-phone="<?= h((string) $c['phone']) ?>">
                            <?= h((string) $c['name']) ?> • <?= h((string) $c['phone']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="mt-2">
                    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/customers.php')) ?>">Add / Manage Customers</a>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label" for="name">Customer Name</label>
                <input class="form-control" type="text" id="name" name="name" value="<?= h($name) ?>" required>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label" for="phone">Phone</label>
                <input class="form-control" type="text" id="phone" name="phone" value="<?= h($phone) ?>">
            </div>
            <div class="col-12">
                <button class="btn btn-primary">Save</button>
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
