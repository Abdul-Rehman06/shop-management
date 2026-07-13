<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Bill Payments - Shop Management';

$pdo = db();
$success = flash_get('success');
$error = flash_get('error');
$canEditDelete = app_can_edit_delete_records();
$admin = app_current_admin();
$adminId = (int) ($admin['id'] ?? 0);

$today = date('Y-m-d');
$from = trim((string) ($_GET['from'] ?? $today));
$to = trim((string) ($_GET['to'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = $today;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = $today;
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$company = trim((string) ($_GET['company'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
if ($status !== '' && !in_array($status, ['pending', 'paid'], true)) {
    $status = '';
}
$q = trim((string) ($_GET['q'] ?? ''));

$billId = trim((string) ($_POST['bill_id'] ?? bill_generate_id()));
$customerName = trim((string) ($_POST['customer_name'] ?? ''));
$companyName = trim((string) ($_POST['company_name'] ?? $company));
$billAmountRaw = trim((string) ($_POST['bill_amount'] ?? ''));
$serviceChargeRaw = trim((string) ($_POST['service_charge'] ?? '0'));
$paymentDate = trim((string) ($_POST['payment_date'] ?? $today));
$dueDate = trim((string) ($_POST['due_date'] ?? ''));
$billStatus = trim((string) ($_POST['status'] ?? 'pending'));
$notes = trim((string) ($_POST['notes'] ?? ''));
$manageCompanyId = (int) ($_GET['edit_company'] ?? ($_POST['company_id'] ?? 0));
$companyCategory = trim((string) ($_POST['company_category'] ?? ''));
$companyShortCode = trim((string) ($_POST['company_short_code'] ?? ''));
$companyMasterName = trim((string) ($_POST['company_master_name'] ?? ''));
$returnQuery = 'from=' . urlencode($from) . '&to=' . urlencode($to) . '&company=' . urlencode($company) . '&status=' . urlencode($status) . '&q=' . urlencode($q);

$savedCustomers = [];
try {
    $stmt = $pdo->query("SELECT id, name, phone FROM customers ORDER BY updated_at DESC, id DESC LIMIT 300");
    $savedCustomers = $stmt->fetchAll();
} catch (Throwable $e) {
    $savedCustomers = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'add_company' || $action === 'update_company') {
        if (!$canEditDelete) {
            flash_set('error', 'Access denied.');
            app_redirect('bill-payments/index.php?' . $returnQuery);
        }

        if ($companyMasterName === '') {
            $error = 'Company name is required.';
        } elseif ($companyCategory === '') {
            $error = 'Category is required.';
        } else {
            try {
                if ($action === 'add_company') {
                    $stmt = $pdo->prepare("
                        INSERT INTO bill_companies (category_name, company_name, short_code, is_active, sort_order)
                        VALUES (:category_name, :company_name, :short_code, 1, :sort_order)
                    ");
                    $stmt->execute([
                        ':category_name' => $companyCategory,
                        ':company_name' => $companyMasterName,
                        ':short_code' => $companyShortCode !== '' ? $companyShortCode : null,
                        ':sort_order' => 999,
                    ]);
                    flash_set('success', 'Company added.');
                    app_redirect('bill-payments/index.php?' . $returnQuery);
                }

                $companyRow = bill_company_find($pdo, $manageCompanyId);
                if (!$companyRow) {
                    flash_set('error', 'Company not found.');
                    app_redirect('bill-payments/index.php?' . $returnQuery);
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("
                    UPDATE bill_companies
                    SET category_name = :category_name,
                        company_name = :company_name,
                        short_code = :short_code
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':category_name' => $companyCategory,
                    ':company_name' => $companyMasterName,
                    ':short_code' => $companyShortCode !== '' ? $companyShortCode : null,
                    ':id' => $manageCompanyId,
                ]);

                if ((string) ($companyRow['company_name'] ?? '') !== $companyMasterName) {
                    $stmt = $pdo->prepare("
                        UPDATE bill_payments
                        SET company_name = :new_name
                        WHERE company_name = :old_name
                    ");
                    $stmt->execute([
                        ':new_name' => $companyMasterName,
                        ':old_name' => (string) ($companyRow['company_name'] ?? ''),
                    ]);
                }
                $pdo->commit();
                flash_set('success', 'Company updated.');
                app_redirect('bill-payments/index.php?' . $returnQuery);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $action === 'add_company' ? 'Could not add company.' : 'Could not update company.';
            }
        }
    } elseif ($action === 'delete_company') {
        if (!$canEditDelete) {
            flash_set('error', 'Access denied.');
            app_redirect('bill-payments/index.php?' . $returnQuery);
        }

        $companyRow = bill_company_find($pdo, $manageCompanyId);
        if (!$companyRow) {
            flash_set('error', 'Company not found.');
            app_redirect('bill-payments/index.php?' . $returnQuery);
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM bill_companies WHERE id = :id");
            $stmt->execute([':id' => $manageCompanyId]);
            flash_set('success', 'Company deleted from bill company list.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not delete company.');
        }
        app_redirect('bill-payments/index.php?' . $returnQuery);
    } elseif ($action === 'add_bill') {
        $billId = trim((string) ($_POST['bill_id'] ?? ''));
        $customerName = trim((string) ($_POST['customer_name'] ?? ''));
        $companyName = trim((string) ($_POST['company_name'] ?? ''));
        $billAmountRaw = trim((string) ($_POST['bill_amount'] ?? ''));
        $serviceChargeRaw = trim((string) ($_POST['service_charge'] ?? '0'));
        $paymentDate = trim((string) ($_POST['payment_date'] ?? $today));
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        $billStatus = trim((string) ($_POST['status'] ?? 'pending'));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($billId === '') {
            $billId = bill_generate_id();
        }
        if ($customerName === '') {
            $error = 'Customer name is required.';
        } elseif ($companyName === '') {
            $error = 'Company is required.';
        } elseif ($billAmountRaw === '' || !is_numeric($billAmountRaw) || (float) $billAmountRaw <= 0) {
            $error = 'Bill amount must be a positive number.';
        } elseif ($serviceChargeRaw === '' || !is_numeric($serviceChargeRaw) || (float) $serviceChargeRaw < 0) {
            $error = 'Service charge must be zero or more.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            $error = 'Payment date is required.';
        } elseif ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $error = 'Due date is invalid.';
        } elseif (!in_array($billStatus, ['pending', 'paid'], true)) {
            $error = 'Invalid status.';
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM bill_payments WHERE bill_id = :bill_id");
            $stmt->execute([':bill_id' => $billId]);
            if ((int) $stmt->fetchColumn() > 0) {
                $error = 'Bill ID already exists.';
            }
        }

        if ($error === '') {
            $billAmount = (float) $billAmountRaw;
            $serviceCharge = (float) $serviceChargeRaw;
            $totalReceived = $billAmount + $serviceCharge;
            $cashAccountId = bill_cash_account_id($pdo);

            $pdo->beginTransaction();
            try {
                $collectedTxnId = bill_insert_cash_collection_txn(
                    $pdo,
                    $cashAccountId,
                    $billId,
                    $customerName,
                    $companyName,
                    $paymentDate,
                    $totalReceived,
                    $serviceCharge,
                    $notes !== '' ? $notes : null
                );

                $paidTxnId = null;
                $paidAt = null;
                if ($billStatus === 'paid') {
                    $paidTxnId = bill_insert_cash_payment_txn(
                        $pdo,
                        $cashAccountId,
                        $billId,
                        $customerName,
                        $companyName,
                        $paymentDate,
                        $billAmount,
                        $notes !== '' ? $notes : null
                    );
                    $paidAt = date('Y-m-d H:i:s');
                }

                $stmt = $pdo->prepare("
                    INSERT INTO bill_payments
                        (bill_id, customer_name, company_name, bill_amount, service_charge, total_received, payment_date, due_date, status, notes, collected_wallet_txn_id, paid_wallet_txn_id, paid_at, created_by)
                    VALUES
                        (:bill_id, :customer_name, :company_name, :bill_amount, :service_charge, :total_received, :payment_date, :due_date, :status, :notes, :collected_wallet_txn_id, :paid_wallet_txn_id, :paid_at, :created_by)
                ");
                $stmt->execute([
                    ':bill_id' => $billId,
                    ':customer_name' => $customerName,
                    ':company_name' => $companyName,
                    ':bill_amount' => $billAmount,
                    ':service_charge' => $serviceCharge,
                    ':total_received' => $totalReceived,
                    ':payment_date' => $paymentDate,
                    ':due_date' => $dueDate !== '' ? $dueDate : null,
                    ':status' => $billStatus,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':collected_wallet_txn_id' => $collectedTxnId,
                    ':paid_wallet_txn_id' => $paidTxnId,
                    ':paid_at' => $paidAt,
                    ':created_by' => $adminId > 0 ? $adminId : null,
                ]);

                $pdo->commit();
                flash_set('success', 'Bill payment saved.');
                app_redirect('bill-payments/index.php?' . $returnQuery);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Could not save bill payment.';
            }
        }
    } elseif ($action === 'mark_paid') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('error', 'Invalid bill payment.');
            app_redirect('bill-payments/index.php?' . $returnQuery);
        }

        $stmt = $pdo->prepare("SELECT * FROM bill_payments WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            flash_set('error', 'Bill payment not found.');
            app_redirect('bill-payments/index.php?' . $returnQuery);
        }
        if ((string) ($row['status'] ?? '') === 'paid') {
            flash_set('success', 'Bill already marked as paid.');
            app_redirect('bill-payments/index.php?' . $returnQuery);
        }

        $cashAccountId = bill_cash_account_id($pdo);
        $pdo->beginTransaction();
        try {
            $paidTxnId = bill_insert_cash_payment_txn(
                $pdo,
                $cashAccountId,
                (string) ($row['bill_id'] ?? ''),
                (string) ($row['customer_name'] ?? ''),
                (string) ($row['company_name'] ?? ''),
                $today,
                (float) ($row['bill_amount'] ?? 0),
                (string) ($row['notes'] ?? '')
            );

            $stmt = $pdo->prepare("
                UPDATE bill_payments
                SET status = 'paid',
                    paid_wallet_txn_id = :paid_wallet_txn_id,
                    paid_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':paid_wallet_txn_id' => $paidTxnId,
                ':id' => $id,
            ]);

            $pdo->commit();
            flash_set('success', 'Bill marked as paid.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('error', 'Could not mark bill as paid.');
        }

        app_redirect('bill-payments/index.php?' . $returnQuery);
    } elseif ($action === 'delete_bill') {
        if (!$canEditDelete) {
            flash_set('error', 'Access denied.');
            app_redirect('bill-payments/index.php?' . $returnQuery);
        }

        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM bill_payments WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            flash_set('error', 'Bill payment not found.');
            app_redirect('bill-payments/index.php?' . $returnQuery);
        }

        $pdo->beginTransaction();
        try {
            foreach (['paid_wallet_txn_id', 'collected_wallet_txn_id'] as $key) {
                $txnId = (int) ($row[$key] ?? 0);
                if ($txnId > 0) {
                    $stmt = $pdo->prepare("DELETE FROM wallet_transactions WHERE id = :id");
                    $stmt->execute([':id' => $txnId]);
                }
            }

            $stmt = $pdo->prepare("DELETE FROM bill_payments WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $pdo->commit();
            flash_set('success', 'Bill payment deleted.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('error', 'Could not delete bill payment.');
        }

        app_redirect('bill-payments/index.php?' . $returnQuery);
    }
}

$filters = [
    'from' => $from,
    'to' => $to,
    'company' => $company,
    'status' => $status,
    'q' => $q,
];

$rows = bill_list($pdo, $filters, 300);
$summary = bill_summary($pdo, $filters);
$currentPending = bill_current_overview($pdo);
$todaySummary = bill_summary($pdo, ['from' => $today, 'to' => $today]);
$todayPaidAmount = bill_paid_amount_by_date($pdo, $today, $today);
$companies = bill_fetch_companies($pdo);
$companyRows = bill_company_rows($pdo, false);
$editCompany = $manageCompanyId > 0 ? bill_company_find($pdo, $manageCompanyId) : null;
if ($editCompany && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $companyCategory = trim((string) ($editCompany['category_name'] ?? ''));
    $companyShortCode = trim((string) ($editCompany['short_code'] ?? ''));
    $companyMasterName = trim((string) ($editCompany['company_name'] ?? ''));
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h4 mb-1">Bill Payments</h1>
        <div class="text-muted">Manage pending utility bill collections and company payments</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('reports/index.php?module=bill_payments')) ?>">Open Report</a>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Pending Bills Amount</div>
                <div class="h4 mb-0"><?= h(number_format((float) ($currentPending['pending_amount'] ?? 0), 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Pending Bills Count</div>
                <div class="h4 mb-0"><?= h((string) (int) ($currentPending['pending_count'] ?? 0)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Today's Bill Commission</div>
                <div class="h4 mb-0 text-success"><?= h(number_format((float) ($todaySummary['service_charge'] ?? 0), 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Paid Bills Today</div>
                <div class="h4 mb-0 text-primary"><?= h(number_format($todayPaidAmount, 2)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12 col-xl-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6 mb-3">Add Bill Payment</h2>
                <form method="post" class="row g-3">
                    <input type="hidden" name="action" value="add_bill">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="bill_id">Bill ID</label>
                        <input class="form-control" id="bill_id" name="bill_id" value="<?= h($billId) ?>" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="saved_customer_select_bill">Saved Customer</label>
                        <select class="form-select" id="saved_customer_select_bill">
                            <option value="">-- Select Customer --</option>
                            <?php foreach ($savedCustomers as $customer): ?>
                                <option value="<?= (int) ($customer['id'] ?? 0) ?>" data-name="<?= h((string) ($customer['name'] ?? '')) ?>" data-phone="<?= h((string) ($customer['phone'] ?? '')) ?>">
                                    <?= h((string) ($customer['name'] ?? '')) ?><?= trim((string) ($customer['phone'] ?? '')) !== '' ? ' • ' . h((string) ($customer['phone'] ?? '')) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="customer_name">Customer Name</label>
                        <input class="form-control" id="customer_name" name="customer_name" value="<?= h($customerName) ?>" placeholder="Select or type customer name" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="company_name">Company</label>
                        <select class="form-select" id="company_name" name="company_name" required>
                            <option value="">-- Select Company --</option>
                            <?php foreach ($companyRows as $companyRow): ?>
                                <?php if ((int) ($companyRow['is_active'] ?? 0) !== 1) { continue; } ?>
                                <?php
                                $companyLabel = trim((string) ($companyRow['company_name'] ?? ''));
                                $categoryLabel = trim((string) ($companyRow['category_name'] ?? ''));
                                $codeLabel = trim((string) ($companyRow['short_code'] ?? ''));
                                ?>
                                <option value="<?= h($companyLabel) ?>" <?= $companyName === $companyLabel ? 'selected' : '' ?>>
                                    <?= h($companyLabel) ?><?= $codeLabel !== '' ? ' (' . h($codeLabel) . ')' : '' ?><?= $categoryLabel !== '' ? ' - ' . h($categoryLabel) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="bill_status">Status</label>
                        <select class="form-select" id="bill_status" name="status">
                            <option value="pending" <?= $billStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="paid" <?= $billStatus === 'paid' ? 'selected' : '' ?>>Paid</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="payment_date">Payment Date</label>
                        <input class="form-control" type="date" id="payment_date" name="payment_date" value="<?= h($paymentDate) ?>" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="bill_amount">Bill Amount</label>
                        <input class="form-control" type="number" step="0.01" min="0.01" id="bill_amount" name="bill_amount" value="<?= h($billAmountRaw) ?>" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="service_charge">Service Charge</label>
                        <input class="form-control" type="number" step="0.01" min="0" id="service_charge" name="service_charge" value="<?= h($serviceChargeRaw) ?>" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="due_date">Due Date</label>
                        <input class="form-control" type="date" id="due_date" name="due_date" value="<?= h($dueDate) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">Notes</label>
                        <input class="form-control" id="notes" name="notes" value="<?= h($notes) ?>" placeholder="Optional note">
                    </div>
                    <div class="col-12">
                        <div class="small text-muted">
                            Total Received = Bill Amount + Service Charge. Cash drawer increases by total received, service charge counts as income, and bill amount stays pending until company payment.
                        </div>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-gradient shadow-glow">Save Bill Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h2 class="h6 mb-0">Manage Bill Companies</h2>
                    <?php if ($editCompany): ?>
                        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('bill-payments/index.php?' . $returnQuery)) ?>">Cancel Edit</a>
                    <?php endif; ?>
                </div>

                <form method="post" class="row g-3 mb-4">
                    <input type="hidden" name="action" value="<?= $editCompany ? 'update_company' : 'add_company' ?>">
                    <input type="hidden" name="company_id" value="<?= h((string) $manageCompanyId) ?>">
                    <input type="hidden" name="from" value="<?= h($from) ?>">
                    <input type="hidden" name="to" value="<?= h($to) ?>">
                    <input type="hidden" name="company" value="<?= h($company) ?>">
                    <input type="hidden" name="status" value="<?= h($status) ?>">
                    <input type="hidden" name="q" value="<?= h($q) ?>">
                    <div class="col-12">
                        <label class="form-label" for="company_category">Category</label>
                        <select class="form-select" id="company_category" name="company_category" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach (['Electricity', 'Gas', 'Water'] as $categoryOption): ?>
                                <option value="<?= h($categoryOption) ?>" <?= $companyCategory === $categoryOption ? 'selected' : '' ?>><?= h($categoryOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="company_master_name">Company Name</label>
                        <input class="form-control" id="company_master_name" name="company_master_name" value="<?= h($companyMasterName) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="company_short_code">Short Code</label>
                        <input class="form-control" id="company_short_code" name="company_short_code" value="<?= h($companyShortCode) ?>" placeholder="KE / SSGC / KWSB">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-outline-primary"><?= $editCompany ? 'Update Company' : 'Add Company' ?></button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Company</th>
                            <th>Category</th>
                            <th>Code</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($companyRows as $companyRow): ?>
                            <tr>
                                <td class="fw-semibold"><?= h((string) ($companyRow['company_name'] ?? '')) ?></td>
                                <td><?= h((string) ($companyRow['category_name'] ?? '')) ?></td>
                                <td><?= h((string) ($companyRow['short_code'] ?? '')) ?></td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-2">
                                        <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('bill-payments/index.php?' . $returnQuery . '&edit_company=' . (int) ($companyRow['id'] ?? 0))) ?>">Edit</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this company from bill list?');">
                                            <input type="hidden" name="action" value="delete_company">
                                            <input type="hidden" name="company_id" value="<?= h((string) (int) ($companyRow['id'] ?? 0)) ?>">
                                            <input type="hidden" name="from" value="<?= h($from) ?>">
                                            <input type="hidden" name="to" value="<?= h($to) ?>">
                                            <input type="hidden" name="company" value="<?= h($company) ?>">
                                            <input type="hidden" name="status" value="<?= h($status) ?>">
                                            <input type="hidden" name="q" value="<?= h($q) ?>">
                                            <button class="btn btn-outline-danger btn-sm">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$companyRows): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-3">No bill companies found.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-md-2">
                <label class="form-label" for="from">From</label>
                <input class="form-control" type="date" id="from" name="from" value="<?= h($from) ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label" for="to">To</label>
                <input class="form-control" type="date" id="to" name="to" value="<?= h($to) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="company">Company</label>
                <select class="form-select" id="company" name="company">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $companyOption): ?>
                        <option value="<?= h($companyOption) ?>" <?= $company === $companyOption ? 'selected' : '' ?>><?= h($companyOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" value="<?= h($q) ?>" placeholder="Customer or Bill ID">
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-outline-primary">Apply Filters</button>
                <a class="btn btn-outline-secondary" href="<?= h(app_url('bill-payments/index.php')) ?>">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Filtered Bills</div>
                <div class="h5 mb-0"><?= h((string) (int) ($summary['count'] ?? 0)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total Received</div>
                <div class="h5 mb-0"><?= h(number_format((float) ($summary['total_received'] ?? 0), 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Bill Commission</div>
                <div class="h5 mb-0 text-success"><?= h(number_format((float) ($summary['service_charge'] ?? 0), 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Paid Bills Amount</div>
                <div class="h5 mb-0 text-primary"><?= h(number_format((float) ($summary['paid_amount'] ?? 0), 2)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Bill ID</th>
                    <th>Customer</th>
                    <th>Company</th>
                    <th class="text-end">Bill Amount</th>
                    <th class="text-end">Service Charge</th>
                    <th class="text-end">Total Received</th>
                    <th>Payment Date</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Paid At</th>
                    <th>Notes</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="fw-semibold"><?= h((string) ($row['bill_id'] ?? '')) ?></td>
                        <td><?= h((string) ($row['customer_name'] ?? '')) ?></td>
                        <td><?= h((string) ($row['company_name'] ?? '')) ?></td>
                        <td class="text-end"><?= h(number_format((float) ($row['bill_amount'] ?? 0), 2)) ?></td>
                        <td class="text-end text-success"><?= h(number_format((float) ($row['service_charge'] ?? 0), 2)) ?></td>
                        <td class="text-end fw-semibold"><?= h(number_format((float) ($row['total_received'] ?? 0), 2)) ?></td>
                        <td><?= h((string) ($row['payment_date'] ?? '')) ?></td>
                        <td><?= h((string) ($row['due_date'] ?? '')) ?></td>
                        <td>
                            <span class="badge <?= (string) ($row['status'] ?? '') === 'paid' ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis' ?>">
                                <?= h(ucfirst((string) ($row['status'] ?? 'pending'))) ?>
                            </span>
                        </td>
                        <td><?= h((string) ($row['paid_at'] ?? '')) ?></td>
                        <td><?= h((string) ($row['notes'] ?? '')) ?></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-2">
                                <?php if ((string) ($row['status'] ?? '') !== 'paid'): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="mark_paid">
                                        <input type="hidden" name="id" value="<?= h((string) (int) ($row['id'] ?? 0)) ?>">
                                        <input type="hidden" name="from" value="<?= h($from) ?>">
                                        <input type="hidden" name="to" value="<?= h($to) ?>">
                                        <input type="hidden" name="company" value="<?= h($company) ?>">
                                        <input type="hidden" name="status" value="<?= h($status) ?>">
                                        <input type="hidden" name="q" value="<?= h($q) ?>">
                                        <button class="btn btn-outline-success btn-sm">Mark Paid</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canEditDelete): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this bill payment?');">
                                        <input type="hidden" name="action" value="delete_bill">
                                        <input type="hidden" name="id" value="<?= h((string) (int) ($row['id'] ?? 0)) ?>">
                                        <input type="hidden" name="from" value="<?= h($from) ?>">
                                        <input type="hidden" name="to" value="<?= h($to) ?>">
                                        <input type="hidden" name="company" value="<?= h($company) ?>">
                                        <input type="hidden" name="status" value="<?= h($status) ?>">
                                        <input type="hidden" name="q" value="<?= h($q) ?>">
                                        <button class="btn btn-outline-danger btn-sm">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="12" class="text-center text-muted py-4">No bill payments found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const savedCustomerSelect = document.getElementById('saved_customer_select_bill');
    const customerNameInput = document.getElementById('customer_name');

    if (savedCustomerSelect && customerNameInput) {
        savedCustomerSelect.addEventListener('change', function () {
            const selected = savedCustomerSelect.options[savedCustomerSelect.selectedIndex];
            const customerName = selected ? (selected.getAttribute('data-name') || '') : '';
            if (customerName !== '') {
                customerNameInput.value = customerName;
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
