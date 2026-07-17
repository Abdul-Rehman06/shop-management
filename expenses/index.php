<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/exp_lib.php';

$pageTitle = 'Expenses - Shop Management';

$pdo = db();
$success = flash_get('success');
$error = flash_get('error');
$canEditDelete = app_can_edit_delete_records();
$admin = app_current_admin();
$adminName = trim((string) ($admin['name'] ?? ''));

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

$filters = [
    'status' => trim((string) ($_GET['status'] ?? '')),
    'category' => trim((string) ($_GET['category'] ?? '')),
];
$categories = exp_categories($pdo);
$categoryRows = exp_category_rows($pdo, false);
$editCategoryId = (int) ($_GET['edit_category'] ?? ($_POST['category_id'] ?? 0));
$editCategory = $editCategoryId > 0 ? exp_category_find($pdo, $editCategoryId) : null;
$categoryNameInput = trim((string) ($_POST['category_name'] ?? ($editCategory['category_name'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'add_category' || $action === 'update_category') {
        if (!$canEditDelete) {
            flash_set('error', 'Access denied.');
            app_redirect('expenses/index.php');
        }

        if ($categoryNameInput === '') {
            $error = 'Category name is required.';
        } else {
            try {
                if ($action === 'add_category') {
                    $stmt = $pdo->prepare("
                        INSERT INTO expense_categories (category_name, is_active, sort_order)
                        VALUES (:category_name, 1, 999)
                    ");
                    $stmt->execute([':category_name' => $categoryNameInput]);
                    flash_set('success', 'Expense category added.');
                } else {
                    $existingCategory = exp_category_find($pdo, $editCategoryId);
                    if (!$existingCategory) {
                        flash_set('error', 'Category not found.');
                        app_redirect('expenses/index.php');
                    }
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("
                        UPDATE expense_categories
                        SET category_name = :category_name
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':category_name' => $categoryNameInput,
                        ':id' => $editCategoryId,
                    ]);
                    if ((string) ($existingCategory['category_name'] ?? '') !== $categoryNameInput) {
                        $stmt = $pdo->prepare("
                            UPDATE expenses
                            SET category = :new_name
                            WHERE category = :old_name
                        ");
                        $stmt->execute([
                            ':new_name' => $categoryNameInput,
                            ':old_name' => (string) ($existingCategory['category_name'] ?? ''),
                        ]);
                    }
                    $pdo->commit();
                    flash_set('success', 'Expense category updated.');
                }
                app_redirect('expenses/index.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Could not save category.';
            }
        }
    } elseif ($action === 'delete_category') {
        if (!$canEditDelete) {
            flash_set('error', 'Access denied.');
            app_redirect('expenses/index.php');
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM expense_categories WHERE id = :id");
            $stmt->execute([':id' => $editCategoryId]);
            flash_set('success', 'Expense category deleted.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not delete category.');
        }
        app_redirect('expenses/index.php');
    } elseif ($action === 'toggle_status') {
        if (!$canEditDelete) {
            flash_set('error', 'Access denied.');
            app_redirect('expenses/index.php');
        }

        $expenseId = (int) ($_POST['expense_id'] ?? 0);
        $targetStatus = trim((string) ($_POST['target_status'] ?? ''));
        $paymentSourceAccountId = (int) ($_POST['payment_source_account_id'] ?? 0);
        $paymentDate = trim((string) ($_POST['payment_date'] ?? $today));
        $paidBy = trim((string) ($_POST['paid_by'] ?? $adminName));

        $expenseRow = exp_find($pdo, $expenseId);
        if (!$expenseRow) {
            flash_set('error', 'Expense bill not found.');
            app_redirect('expenses/index.php');
        }
        if (!in_array($targetStatus, ['paid', 'unpaid'], true)) {
            flash_set('error', 'Invalid status.');
            app_redirect('expenses/index.php');
        }

        try {
            $pdo->beginTransaction();
            $snapshot = [
                'payment_source_type' => null,
                'payment_source_account_id' => null,
                'linked_wallet_txn_id' => null,
            ];
            if ($targetStatus === 'paid') {
                if ($paymentSourceAccountId <= 0) {
                    throw new RuntimeException('Please select a paid from account.');
                }
                $snapshot = exp_apply_payment_history($pdo, $expenseId, [
                    'bill_name' => (string) ($expenseRow['bill_name'] ?? ''),
                    'category' => (string) ($expenseRow['category'] ?? 'Other'),
                    'amount' => (float) ($expenseRow['amount'] ?? 0),
                    'payment_date' => $paymentDate,
                    'date' => (string) ($expenseRow['date'] ?? $today),
                    'paid_by' => $paidBy,
                    'notes' => (string) ($expenseRow['notes'] ?? ''),
                    'description' => (string) ($expenseRow['description'] ?? ''),
                ], [[
                    'account_id' => $paymentSourceAccountId,
                    'account_type' => (string) (wallet_account($pdo, $paymentSourceAccountId)['account_type'] ?? ''),
                    'account_name' => (string) (wallet_account($pdo, $paymentSourceAccountId)['account_name'] ?? ''),
                    'amount' => (float) ($expenseRow['amount'] ?? 0),
                ]]);
            } else {
                exp_reverse_payment_history($pdo, $expenseId, $paidBy !== '' ? $paidBy : null);
            }

            $stmt = $pdo->prepare("
                UPDATE expenses
                SET payment_status = :payment_status,
                    payment_source_type = :payment_source_type,
                    payment_source_account_id = :payment_source_account_id,
                    payment_date = :payment_date,
                    paid_by = :paid_by,
                    linked_wallet_txn_id = :linked_wallet_txn_id
                WHERE id = :id
            ");
            $stmt->execute([
                ':payment_status' => $targetStatus,
                ':payment_source_type' => $snapshot['payment_source_type'],
                ':payment_source_account_id' => $snapshot['payment_source_account_id'],
                ':payment_date' => $targetStatus === 'paid' ? $paymentDate : null,
                ':paid_by' => $targetStatus === 'paid' ? ($paidBy !== '' ? $paidBy : null) : null,
                ':linked_wallet_txn_id' => $snapshot['linked_wallet_txn_id'],
                ':id' => $expenseId,
            ]);
            $pdo->commit();
            flash_set('success', $targetStatus === 'paid' ? 'Bill marked as paid.' : 'Bill marked as unpaid.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('error', 'Could not update bill status.');
        }
        app_redirect('expenses/index.php');
    }
}

$todaySummary = exp_summary($pdo, $today, $today);
$monthlySummary = exp_summary($pdo, $monthStart, $monthEnd);

$params = [];
$where = 'WHERE 1=1';
if (($filters['status'] ?? '') !== '' && in_array($filters['status'], ['paid', 'unpaid'], true)) {
    $where .= ' AND e.payment_status = :payment_status';
    $params[':payment_status'] = $filters['status'];
}
if (($filters['category'] ?? '') !== '' && in_array($filters['category'], $categories, true)) {
    $where .= ' AND e.category = :category';
    $params[':category'] = $filters['category'];
}

$stmt = $pdo->prepare("
    SELECT e.*, paid_acc.account_name AS payment_account_name, paid_acc.account_type AS payment_account_type
    FROM expenses e
    LEFT JOIN accounts paid_acc ON paid_acc.id = e.payment_source_account_id
    {$where}
    ORDER BY e.date DESC, e.id DESC
    LIMIT 100
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$methodLabels = exp_method_labels();
$accountOptions = exp_account_options_by_type($pdo);
$paymentHistoryMap = exp_payment_history_map($pdo, array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows));

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4 animate-slide-up">
    <div>
        <h1 class="h3 mb-1 text-gray-800 font-bold">Expenses</h1>
        <div class="text-gray-500 text-sm">Manage shop expenses and daily outgoing costs</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary bg-white/60 border-0 shadow-sm hover-lift rounded-xl" href="<?= h(app_url('expenses/report.php')) ?>">
            <i class="bi bi-file-earmark-bar-graph"></i> Reports
        </a>
        <a class="btn btn-gradient shadow-glow rounded-xl" href="<?= h(app_url('expenses/add.php')) ?>">
            <i class="bi bi-plus-lg"></i> Add Bill
        </a>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success border-0 shadow-sm animate-slide-up"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger border-0 shadow-sm animate-slide-up"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-4 mb-4 animate-slide-up stagger-1">
    <div class="col-12 col-md-6">
        <div class="p-4 bg-light rounded-4 border-start border-danger border-4 h-100 transition-all hover-lift">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="text-muted small fw-bold text-uppercase tracking-wider">Today Expense</div>
                <div class="bg-danger bg-opacity-10 text-danger p-2 rounded-circle">
                    <i class="bi bi-calendar-day"></i>
                </div>
            </div>
            <div class="h3 mb-0 font-bold text-danger"><?= h(number_format((float) ($todaySummary['total_amount'] ?? 0), 2)) ?></div>
            <div class="small text-muted mt-1">Paid: Rs <?= h(number_format((float) ($todaySummary['paid_amount'] ?? 0), 2)) ?> | Unpaid: Rs <?= h(number_format((float) ($todaySummary['unpaid_amount'] ?? 0), 2)) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="p-4 bg-light rounded-4 border-start border-warning border-4 h-100 transition-all hover-lift">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="text-muted small fw-bold text-uppercase tracking-wider">Monthly Expense</div>
                <div class="bg-warning bg-opacity-10 text-warning p-2 rounded-circle">
                    <i class="bi bi-calendar-month"></i>
                </div>
            </div>
            <div class="h3 mb-0 font-bold text-warning"><?= h(number_format((float) ($monthlySummary['total_amount'] ?? 0), 2)) ?></div>
            <div class="small text-muted mt-1">Paid: <?= h((string) ($monthlySummary['paid_count'] ?? 0)) ?> | Unpaid: <?= h((string) ($monthlySummary['unpaid_count'] ?? 0)) ?></div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4 animate-slide-up stagger-2">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label" for="category_filter">Category</label>
                <select class="form-select" id="category_filter" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $categoryName): ?>
                        <option value="<?= h($categoryName) ?>" <?= ($filters['category'] ?? '') === $categoryName ? 'selected' : '' ?>><?= h($categoryName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label" for="status_filter">Payment Status</label>
                <select class="form-select" id="status_filter" name="status">
                    <option value="">All</option>
                    <option value="paid" <?= ($filters['status'] ?? '') === 'paid' ? 'selected' : '' ?>>Paid Bills</option>
                    <option value="unpaid" <?= ($filters['status'] ?? '') === 'unpaid' ? 'selected' : '' ?>>Unpaid Bills</option>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <button class="btn btn-outline-secondary">Apply Filters</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-4 mb-4 animate-slide-up stagger-2">
    <div class="col-12 col-xl-7">
        <div class="glass-card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="mb-0">Bill Categories</h5>
                    <div class="text-muted small">Add, edit, delete</div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>Category</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($categoryRows as $categoryRow): ?>
                            <tr>
                                <td><?= h((string) ($categoryRow['category_name'] ?? '')) ?></td>
                                <td class="text-end">
                                    <?php if ($canEditDelete): ?>
                                        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('expenses/index.php?edit_category=' . (int) ($categoryRow['id'] ?? 0))) ?>">Edit</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this category?');">
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="category_id" value="<?= (int) ($categoryRow['id'] ?? 0) ?>">
                                            <button class="btn btn-outline-danger btn-sm">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$categoryRows): ?>
                            <tr><td colspan="2" class="text-center text-muted py-3">No categories found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-5">
        <div class="glass-card h-100">
            <div class="card-body">
                <h5 class="mb-3"><?= $editCategory ? 'Edit Category' : 'Add Category' ?></h5>
                <form method="post" class="row g-3">
                    <input type="hidden" name="action" value="<?= $editCategory ? 'update_category' : 'add_category' ?>">
                    <?php if ($editCategory): ?>
                        <input type="hidden" name="category_id" value="<?= (int) ($editCategory['id'] ?? 0) ?>">
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label" for="category_name">Category Name</label>
                        <input class="form-control" type="text" id="category_name" name="category_name" value="<?= h($categoryNameInput) ?>" required>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-gradient shadow-glow"><?= $editCategory ? 'Update Category' : 'Add Category' ?></button>
                        <?php if ($editCategory): ?>
                            <a class="btn btn-outline-secondary" href="<?= h(app_url('expenses/index.php')) ?>">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="glass-card animate-slide-up stagger-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 custom-table">
                <thead class="bg-light bg-opacity-50">
                <tr>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Date</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Bill Name</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Category</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Status</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Paid From</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Amount</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Payment History</th>
                    <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider">Notes</th>
                    <?php if ($canEditDelete): ?>
                        <th class="border-0 px-4 py-3 text-uppercase text-xs font-bold text-gray-500 tracking-wider text-end">Actions</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody class="border-top-0">
                <?php foreach ($rows as $r): ?>
                    <tr class="transition-all hover-bg-light">
                        <td class="px-4 py-3 font-medium text-gray-600"><?= h((string) $r['date']) ?></td>
                        <td class="px-4 py-3 font-medium text-gray-800"><?= h((string) ($r['bill_name'] ?? '')) ?></td>
                        <td class="px-4 py-3">
                            <span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-1 rounded-pill border border-secondary border-opacity-25">
                                <?= h((string) $r['category']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="badge <?= h(exp_status_badge_class((string) ($r['payment_status'] ?? 'unpaid'))) ?>">
                                <?= h(exp_status_label((string) ($r['payment_status'] ?? 'unpaid'))) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            <?php if ((string) ($r['payment_status'] ?? 'unpaid') === 'paid'): ?>
                                <?php if ((string) ($r['payment_source_type'] ?? '') === 'multiple'): ?>
                                    <span class="fw-semibold">Multiple Accounts</span>
                                <?php else: ?>
                                    <?= h(exp_payment_source_display((string) ($r['payment_source_type'] ?? ''), (string) ($r['payment_account_name'] ?? ''))) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Not paid yet</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-end font-bold text-danger"><?= h(number_format((float) $r['amount'], 2)) ?></td>
                        <td class="px-4 py-3 text-gray-600">
                            <?php $historyRows = $paymentHistoryMap[(int) ($r['id'] ?? 0)] ?? []; ?>
                            <?php if ($historyRows): ?>
                                <?php foreach ($historyRows as $historyRow): ?>
                                    <div class="mb-2 pb-2 border-bottom">
                                        <div class="fw-semibold">
                                            <?= h((string) ($historyRow['payment_date'] ?? '')) ?>
                                            <?php if ((string) ($historyRow['status'] ?? '') === 'reversed'): ?>
                                                <span class="badge bg-warning text-dark ms-1">Reversed</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small text-muted">
                                            Paid By: <?= h((string) ($historyRow['paid_by'] ?? '')) ?> | Rs <?= h(number_format((float) ($historyRow['total_amount'] ?? 0), 2)) ?>
                                        </div>
                                        <?php foreach (($historyRow['items'] ?? []) as $historyItem): ?>
                                            <div class="small text-muted">
                                                <?= h(exp_payment_source_display((string) ($historyItem['payment_source_type'] ?? ''), (string) ($historyItem['account_name'] ?? ''))) ?>
                                                - Rs <?= h(number_format((float) ($historyItem['amount'] ?? 0), 2)) ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ((string) ($historyRow['notes'] ?? '') !== ''): ?>
                                            <div class="small text-muted"><?= h((string) ($historyRow['notes'] ?? '')) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="small text-muted">No payment history</div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            <div><?= h((string) ($r['notes'] ?? '')) ?></div>
                            <div class="small text-muted"><?= h((string) ($r['description'] ?? '')) ?></div>
                        </td>
                        <?php if ($canEditDelete): ?>
                            <td class="px-4 py-3 text-end">
                                <?php if ((string) ($r['payment_status'] ?? 'unpaid') === 'unpaid'): ?>
                                    <form method="post" class="d-inline-flex gap-2 flex-wrap justify-content-end align-items-center mb-1 expense-toggle-paid-form">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="expense_id" value="<?= (int) ($r['id'] ?? 0) ?>">
                                        <input type="hidden" name="target_status" value="paid">
                                        <input type="hidden" name="payment_date" value="<?= h(date('Y-m-d')) ?>">
                                        <input type="hidden" name="paid_by" value="<?= h($adminName) ?>">
                                        <select class="form-select form-select-sm expense-toggle-account" name="payment_source_account_id" style="min-width: 150px;">
                                            <option value="0">Select Account</option>
                                            <?php foreach ($accountOptions as $accountType => $accounts): ?>
                                                <?php if (!$accounts) { continue; } ?>
                                                <optgroup label="<?= h(exp_payment_source_display($accountType)) ?>">
                                                <?php foreach ($accounts as $account): ?>
                                                    <option value="<?= (int) ($account['id'] ?? 0) ?>">
                                                        <?= h((string) ($account['account_name'] ?? '')) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                </optgroup>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-success btn-sm">Mark Paid</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" class="d-inline mb-1" onsubmit="return confirm('Mark this bill as unpaid? Linked payment deduction will be reversed.');">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="expense_id" value="<?= (int) ($r['id'] ?? 0) ?>">
                                        <input type="hidden" name="target_status" value="unpaid">
                                        <button class="btn btn-outline-warning btn-sm">Mark Unpaid</button>
                                    </form>
                                <?php endif; ?>
                                <br>
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('expenses/edit.php?id=' . (int) $r['id'])) ?>">Edit</a>
                                <a class="btn btn-outline-danger btn-sm" href="<?= h(app_url('expenses/delete.php?id=' . (int) $r['id'])) ?>">Delete</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= h((string) (8 + ($canEditDelete ? 1 : 0))) ?>" class="text-center text-muted py-5">
                            <div class="d-flex flex-column align-items-center justify-content-center">
                                <i class="bi bi-receipt fs-1 text-gray-300 mb-2"></i>
                                <p class="mb-0">No bill records yet.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const expenseToggleForms = Array.from(document.querySelectorAll('.expense-toggle-paid-form'));

    expenseToggleForms.forEach((form) => {
        const accountSelect = form.querySelector('.expense-toggle-account');
        if (accountSelect && accountSelect.options.length > 1 && accountSelect.value === '0') {
            accountSelect.selectedIndex = 1;
        }
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
