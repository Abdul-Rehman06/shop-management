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
$receivedInType = trim((string) ($_POST['received_in_type'] ?? 'cash'));
$receivedInAccountId = (int) ($_POST['received_in_account_id'] ?? 0);
$paidFromType = trim((string) ($_POST['paid_from_type'] ?? 'cash'));
$paidFromAccountId = (int) ($_POST['paid_from_account_id'] ?? 0);
$manageCompanyId = (int) ($_GET['edit_company'] ?? ($_POST['company_id'] ?? 0));
$manageCategoryId = (int) ($_GET['edit_category'] ?? ($_POST['category_id'] ?? 0));
$companyCategory = trim((string) ($_POST['company_category'] ?? ''));
$companyShortCode = trim((string) ($_POST['company_short_code'] ?? ''));
$companyMasterName = trim((string) ($_POST['company_master_name'] ?? ''));
$billCategoryName = trim((string) ($_POST['bill_category_name'] ?? ''));
$returnQuery = 'from=' . urlencode($from) . '&to=' . urlencode($to) . '&company=' . urlencode($company) . '&status=' . urlencode($status) . '&q=' . urlencode($q);

$savedCustomers = [];
try {
    $stmt = $pdo->query("SELECT id, name, phone FROM customers ORDER BY updated_at DESC, id DESC LIMIT 300");
    $savedCustomers = $stmt->fetchAll();
} catch (Throwable $e) {
    $savedCustomers = [];
}

$billMethodLabels = bill_method_labels();
$billMethodAccounts = bill_accounts_by_method($pdo);
$billCategoryRows = bill_category_rows($pdo, false);
$billCategoryNames = bill_category_names($pdo);
$flatPayAccounts = [];
foreach (['cash', 'bank', 'jazzcash', 'easypaisa'] as $methodKey) {
    foreach (($billMethodAccounts[$methodKey] ?? []) as $account) {
        $flatPayAccounts[] = [
            'id' => (int) ($account['id'] ?? 0),
            'account_name' => (string) ($account['account_name'] ?? ''),
            'account_type' => (string) ($account['account_type'] ?? $methodKey),
        ];
    }
}
$resolvePaidAccount = static function (int $accountId) use ($flatPayAccounts): ?array {
    foreach ($flatPayAccounts as $account) {
        if ((int) ($account['id'] ?? 0) === $accountId) {
            return $account;
        }
    }
    return null;
};
$createBillHistory = static function (
    PDO $pdo,
    array $rows,
    string $paidFromType,
    ?int $paidFromAccountId,
    int $paidWalletTxnId,
    string $paymentDate,
    string $paidBy,
    ?string $notes = null
): void {
    $paymentGroupId = bill_generate_payment_group_id();
    $totalAmount = 0.0;
    foreach ($rows as $row) {
        $totalAmount += (float) ($row['bill_amount'] ?? 0);
    }

    $stmt = $pdo->prepare("
        INSERT INTO bill_payment_history
            (payment_group_id, payment_date, total_amount, paid_from_type, paid_from_account_id, paid_wallet_txn_id, paid_by, notes)
        VALUES
            (:payment_group_id, :payment_date, :total_amount, :paid_from_type, :paid_from_account_id, :paid_wallet_txn_id, :paid_by, :notes)
    ");
    $stmt->execute([
        ':payment_group_id' => $paymentGroupId,
        ':payment_date' => $paymentDate,
        ':total_amount' => $totalAmount,
        ':paid_from_type' => $paidFromType,
        ':paid_from_account_id' => $paidFromAccountId,
        ':paid_wallet_txn_id' => $paidWalletTxnId,
        ':paid_by' => $paidBy !== '' ? $paidBy : null,
        ':notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
    ]);
    $historyId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare("
        INSERT INTO bill_payment_history_items
            (history_id, bill_payment_id, bill_id, company_name, category_name, bill_amount)
        VALUES
            (:history_id, :bill_payment_id, :bill_id, :company_name, :category_name, :bill_amount)
    ");
    foreach ($rows as $row) {
        $itemStmt->execute([
            ':history_id' => $historyId,
            ':bill_payment_id' => (int) ($row['id'] ?? 0),
            ':bill_id' => (string) ($row['bill_id'] ?? ''),
            ':company_name' => (string) ($row['company_name'] ?? ''),
            ':category_name' => (string) ($row['category_name'] ?? ''),
            ':bill_amount' => (float) ($row['bill_amount'] ?? 0),
        ]);
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'add_category' || $action === 'update_category') {
        if (!$canEditDelete) {
            flash_set('error', 'Access denied.');
            app_redirect('bill-payments/index.php?' . $returnQuery);
        }

        if ($billCategoryName === '') {
            $error = 'Category name is required.';
        } else {
            try {
                if ($action === 'add_category') {
                    $stmt = $pdo->prepare("
                        INSERT INTO bill_categories (category_name, is_active, sort_order)
                        VALUES (:category_name, 1, :sort_order)
                    ");
                    $stmt->execute([
                        ':category_name' => $billCategoryName,
                        ':sort_order' => 999,
                    ]);
                    flash_set('success', 'Bill category added.');
                    app_redirect('bill-payments/index.php?' . $returnQuery);
                }

                $categoryRow = bill_category_find($pdo, $manageCategoryId);
                if (!$categoryRow) {
                    flash_set('error', 'Category not found.');
                    app_redirect('bill-payments/index.php?' . $returnQuery);
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE bill_categories SET category_name = :category_name WHERE id = :id");
                $stmt->execute([
                    ':category_name' => $billCategoryName,
                    ':id' => $manageCategoryId,
                ]);

                if ((string) ($categoryRow['category_name'] ?? '') !== $billCategoryName) {
                    $stmt = $pdo->prepare("UPDATE bill_companies SET category_name = :new_name WHERE category_name = :old_name");
                    $stmt->execute([
                        ':new_name' => $billCategoryName,
                        ':old_name' => (string) ($categoryRow['category_name'] ?? ''),
                    ]);
                    $stmt = $pdo->prepare("UPDATE bill_payments SET category_name = :new_name WHERE category_name = :old_name");
                    $stmt->execute([
                        ':new_name' => $billCategoryName,
                        ':old_name' => (string) ($categoryRow['category_name'] ?? ''),
                    ]);
                    $stmt = $pdo->prepare("UPDATE bill_payment_history_items SET category_name = :new_name WHERE category_name = :old_name");
                    $stmt->execute([
                        ':new_name' => $billCategoryName,
                        ':old_name' => (string) ($categoryRow['category_name'] ?? ''),
                    ]);
                }
                $pdo->commit();
                flash_set('success', 'Bill category updated.');
                app_redirect('bill-payments/index.php?' . $returnQuery);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $action === 'add_category' ? 'Could not add category.' : 'Could not update category.';
            }
        }
    } elseif ($action === 'delete_category') {
        if (!$canEditDelete) {
            flash_set('error', 'Access denied.');
            app_redirect('bill-payments/index.php?' . $returnQuery);
        }

        $categoryRow = bill_category_find($pdo, $manageCategoryId);
        if (!$categoryRow) {
            flash_set('error', 'Category not found.');
            app_redirect('bill-payments/index.php?' . $returnQuery);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bill_companies WHERE category_name = :category_name");
        $stmt->execute([':category_name' => (string) ($categoryRow['category_name'] ?? '')]);
        if ((int) $stmt->fetchColumn() > 0) {
            flash_set('error', 'This category is linked with bill companies. Update companies first.');
            app_redirect('bill-payments/index.php?' . $returnQuery);
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM bill_categories WHERE id = :id");
            $stmt->execute([':id' => $manageCategoryId]);
            flash_set('success', 'Bill category deleted.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not delete category.');
        }
        app_redirect('bill-payments/index.php?' . $returnQuery);
    } elseif ($action === 'add_company' || $action === 'update_company') {
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
        $receivedInType = trim((string) ($_POST['received_in_type'] ?? 'cash'));
        $receivedInAccountId = (int) ($_POST['received_in_account_id'] ?? 0);
        $paidFromType = trim((string) ($_POST['paid_from_type'] ?? 'cash'));
        $paidFromAccountId = (int) ($_POST['paid_from_account_id'] ?? 0);

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
        } elseif (!array_key_exists($receivedInType, $billMethodLabels)) {
            $error = 'Invalid collection method.';
        } elseif ($receivedInType !== 'cash' && $receivedInType !== 'other' && $receivedInAccountId <= 0) {
            $error = 'Please select where the customer payment was received.';
        } elseif ($billStatus === 'paid' && !array_key_exists($paidFromType, $billMethodLabels)) {
            $error = 'Invalid paid from method.';
        } elseif ($billStatus === 'paid' && $paidFromType !== 'cash' && $paidFromType !== 'other' && $paidFromAccountId <= 0) {
            $error = 'Please select the account used to pay the bill.';
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
            $stmt = $pdo->prepare("SELECT category_name FROM bill_companies WHERE company_name = :company_name LIMIT 1");
            $stmt->execute([':company_name' => $companyName]);
            $selectedCategoryName = trim((string) ($stmt->fetchColumn() ?: ''));

            $pdo->beginTransaction();
            try {
                $collectedTxnId = bill_insert_collection_txn(
                    $pdo,
                    $receivedInType,
                    $receivedInAccountId > 0 ? $receivedInAccountId : null,
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
                    $paidTxnId = bill_insert_payment_txn(
                        $pdo,
                        $paidFromType,
                        $paidFromAccountId > 0 ? $paidFromAccountId : null,
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
                        (bill_id, customer_name, company_name, category_name, bill_amount, service_charge, total_received, payment_date, due_date, received_in_type, received_in_account_id, status, paid_from_type, paid_from_account_id, notes, collected_wallet_txn_id, paid_wallet_txn_id, paid_at, paid_by, created_by)
                    VALUES
                        (:bill_id, :customer_name, :company_name, :category_name, :bill_amount, :service_charge, :total_received, :payment_date, :due_date, :received_in_type, :received_in_account_id, :status, :paid_from_type, :paid_from_account_id, :notes, :collected_wallet_txn_id, :paid_wallet_txn_id, :paid_at, :paid_by, :created_by)
                ");
                $stmt->execute([
                    ':bill_id' => $billId,
                    ':customer_name' => $customerName,
                    ':company_name' => $companyName,
                    ':category_name' => $selectedCategoryName !== '' ? $selectedCategoryName : null,
                    ':bill_amount' => $billAmount,
                    ':service_charge' => $serviceCharge,
                    ':total_received' => $totalReceived,
                    ':payment_date' => $paymentDate,
                    ':due_date' => $dueDate !== '' ? $dueDate : null,
                    ':received_in_type' => $receivedInType,
                    ':received_in_account_id' => $receivedInType === 'cash' ? bill_cash_account_id($pdo) : ($receivedInAccountId > 0 ? $receivedInAccountId : null),
                    ':status' => $billStatus,
                    ':paid_from_type' => $billStatus === 'paid' ? $paidFromType : null,
                    ':paid_from_account_id' => $billStatus === 'paid'
                        ? ($paidFromType === 'cash' ? bill_cash_account_id($pdo) : ($paidFromAccountId > 0 ? $paidFromAccountId : null))
                        : null,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':collected_wallet_txn_id' => $collectedTxnId,
                    ':paid_wallet_txn_id' => $paidTxnId,
                    ':paid_at' => $paidAt,
                    ':paid_by' => $billStatus === 'paid' ? (string) ($admin['name'] ?? '') : null,
                    ':created_by' => $adminId > 0 ? $adminId : null,
                ]);

                $insertedBillPaymentId = (int) $pdo->lastInsertId();
                if ($billStatus === 'paid' && $paidTxnId) {
                    $createBillHistory($pdo, [[
                        'id' => $insertedBillPaymentId,
                        'bill_id' => $billId,
                        'company_name' => $companyName,
                        'category_name' => $selectedCategoryName,
                        'bill_amount' => $billAmount,
                    ]], $paidFromType, $paidFromType === 'cash' ? bill_cash_account_id($pdo) : ($paidFromAccountId > 0 ? $paidFromAccountId : null), $paidTxnId, $paymentDate, (string) ($admin['name'] ?? ''), $notes !== '' ? $notes : null);
                }

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
    } elseif ($action === 'mark_paid' || $action === 'bulk_mark_paid') {
        $selectedBillIds = array_map('intval', $_POST['selected_bill_ids'] ?? []);
        $singleId = (int) ($_POST['id'] ?? 0);
        if ($singleId > 0) {
            $selectedBillIds[] = $singleId;
        }
        $selectedBillIds = array_values(array_unique(array_filter($selectedBillIds, static fn (int $id): bool => $id > 0)));
        if (!$selectedBillIds) {
            flash_set('error', 'Please select at least one pending bill.');
            app_redirect('bill-payments/index.php?' . $returnQuery);
        }

        $selectedPaidAccountId = (int) ($_POST['paid_from_account_id'] ?? 0);
        $selectedPaidAccount = $resolvePaidAccount($selectedPaidAccountId);
        if (!$selectedPaidAccount) {
            flash_set('error', 'Please select a valid payment account.');
            app_redirect('bill-payments/index.php?' . $returnQuery);
        }
        $paidFromType = (string) ($selectedPaidAccount['account_type'] ?? 'cash');

        $stmt = $pdo->prepare("SELECT * FROM bill_payments WHERE id IN (" . implode(',', array_fill(0, count($selectedBillIds), '?')) . ") ORDER BY payment_date ASC, id ASC");
        $stmt->execute($selectedBillIds);
        $rowsToPay = array_values(array_filter($stmt->fetchAll(), static fn (array $row): bool => (string) ($row['status'] ?? '') !== 'paid'));
        if (!$rowsToPay) {
            flash_set('success', 'Selected bills are already paid.');
            app_redirect('bill-payments/index.php?' . $returnQuery);
        }

        $totalBillAmount = 0.0;
        $billNames = [];
        foreach ($rowsToPay as $row) {
            $totalBillAmount += (float) ($row['bill_amount'] ?? 0);
            $billNames[] = (string) ($row['company_name'] ?? '');
        }
        $paymentSummary = implode(', ', array_slice($billNames, 0, 5));
        if (count($billNames) > 5) {
            $paymentSummary .= ' +' . (count($billNames) - 5) . ' more';
        }

        $pdo->beginTransaction();
        try {
            $paymentGroupId = bill_generate_payment_group_id();
            $paidTxnId = bill_insert_bulk_payment_txn(
                $pdo,
                $paidFromType,
                $selectedPaidAccountId,
                $paymentGroupId,
                $today,
                $totalBillAmount,
                $paymentSummary,
                'Bills paid together'
            );

            $updateStmt = $pdo->prepare("
                UPDATE bill_payments
                SET status = 'paid',
                    paid_from_type = :paid_from_type,
                    paid_from_account_id = :paid_from_account_id,
                    paid_wallet_txn_id = :paid_wallet_txn_id,
                    paid_at = NOW(),
                    paid_by = :paid_by
                WHERE id = :id
            ");
            foreach ($rowsToPay as $row) {
                $updateStmt->execute([
                    ':paid_from_type' => $paidFromType,
                    ':paid_from_account_id' => $selectedPaidAccountId,
                    ':paid_wallet_txn_id' => $paidTxnId,
                    ':paid_by' => (string) ($admin['name'] ?? ''),
                    ':id' => (int) ($row['id'] ?? 0),
                ]);
            }

            $createBillHistory($pdo, $rowsToPay, $paidFromType, $selectedPaidAccountId, $paidTxnId, $today, (string) ($admin['name'] ?? ''), 'Bulk bill payment');
            $pdo->commit();
            flash_set('success', count($rowsToPay) . ' bill(s) marked as paid.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('error', 'Could not mark selected bills as paid.');
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
$pendingRows = bill_list($pdo, ['status' => 'pending'], 500);
$summary = bill_summary($pdo, $filters);
$currentPending = bill_current_overview($pdo);
$todaySummary = bill_summary($pdo, ['from' => $today, 'to' => $today]);
$todayPaidAmount = bill_paid_amount_by_date($pdo, $today, $today);
$companies = bill_fetch_companies($pdo);
$companyRows = bill_company_rows($pdo, false);
$billCategoryRows = bill_category_rows($pdo, false);
$paymentHistoryMap = bill_payment_history_map($pdo, array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $pendingRows));
$selectedCompanyCategoryDisplay = '';
foreach ($companyRows as $companyRow) {
    if (trim((string) ($companyRow['company_name'] ?? '')) === $companyName) {
        $selectedCompanyCategoryDisplay = trim((string) ($companyRow['category_name'] ?? ''));
        break;
    }
}
$editCompany = $manageCompanyId > 0 ? bill_company_find($pdo, $manageCompanyId) : null;
$editCategory = $manageCategoryId > 0 ? bill_category_find($pdo, $manageCategoryId) : null;
if ($editCompany && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $companyCategory = trim((string) ($editCompany['category_name'] ?? ''));
    $companyShortCode = trim((string) ($editCompany['short_code'] ?? ''));
    $companyMasterName = trim((string) ($editCompany['company_name'] ?? ''));
}
if ($editCategory && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $billCategoryName = trim((string) ($editCategory['category_name'] ?? ''));
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
                                <option value="<?= h($companyLabel) ?>" data-category="<?= h($categoryLabel) ?>" <?= $companyName === $companyLabel ? 'selected' : '' ?>>
                                    <?= h($companyLabel) ?><?= $codeLabel !== '' ? ' (' . h($codeLabel) . ')' : '' ?><?= $categoryLabel !== '' ? ' - ' . h($categoryLabel) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="company_category_display">Category</label>
                        <input class="form-control bg-light" id="company_category_display" value="<?= h($selectedCompanyCategoryDisplay) ?>" readonly>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="received_in_type">Received In</label>
                        <select class="form-select bill-method-toggle" id="received_in_type" name="received_in_type" data-target-prefix="received_in">
                            <option value="cash" <?= $receivedInType === 'cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="jazzcash" <?= $receivedInType === 'jazzcash' ? 'selected' : '' ?>>JazzCash</option>
                            <option value="easypaisa" <?= $receivedInType === 'easypaisa' ? 'selected' : '' ?>>EasyPaisa</option>
                            <option value="bank" <?= $receivedInType === 'bank' ? 'selected' : '' ?>>Bank Account</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4 bill-method-account" data-method-prefix="received_in" data-method-type="jazzcash"<?= $receivedInType === 'jazzcash' ? '' : ' style="display:none;"' ?>>
                        <label class="form-label" for="received_in_jazzcash_account_id">JazzCash Account</label>
                        <select class="form-select" id="received_in_jazzcash_account_id" name="received_in_account_id">
                            <option value="">-- Select JazzCash --</option>
                            <?php foreach (($billMethodAccounts['jazzcash'] ?? []) as $account): ?>
                                <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= $receivedInType === 'jazzcash' && $receivedInAccountId === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>><?= h((string) ($account['account_name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4 bill-method-account" data-method-prefix="received_in" data-method-type="easypaisa"<?= $receivedInType === 'easypaisa' ? '' : ' style="display:none;"' ?>>
                        <label class="form-label" for="received_in_easypaisa_account_id">EasyPaisa Account</label>
                        <select class="form-select" id="received_in_easypaisa_account_id" name="received_in_account_id">
                            <option value="">-- Select EasyPaisa --</option>
                            <?php foreach (($billMethodAccounts['easypaisa'] ?? []) as $account): ?>
                                <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= $receivedInType === 'easypaisa' && $receivedInAccountId === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>><?= h((string) ($account['account_name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4 bill-method-account" data-method-prefix="received_in" data-method-type="bank"<?= $receivedInType === 'bank' ? '' : ' style="display:none;"' ?>>
                        <label class="form-label" for="received_in_bank_account_id">Bank Account</label>
                        <select class="form-select" id="received_in_bank_account_id" name="received_in_account_id">
                            <option value="">-- Select Bank Account --</option>
                            <?php foreach (($billMethodAccounts['bank'] ?? []) as $account): ?>
                                <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= $receivedInType === 'bank' && $receivedInAccountId === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>><?= h((string) ($account['account_name'] ?? '')) ?></option>
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
                    <div class="col-12 col-md-4 bill-paid-fields"<?= $billStatus === 'paid' ? '' : ' style="display:none;"' ?>>
                        <label class="form-label" for="paid_from_type">Paid From</label>
                        <select class="form-select bill-method-toggle" id="paid_from_type" name="paid_from_type" data-target-prefix="paid_from">
                            <option value="cash" <?= $paidFromType === 'cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="jazzcash" <?= $paidFromType === 'jazzcash' ? 'selected' : '' ?>>JazzCash</option>
                            <option value="easypaisa" <?= $paidFromType === 'easypaisa' ? 'selected' : '' ?>>EasyPaisa</option>
                            <option value="bank" <?= $paidFromType === 'bank' ? 'selected' : '' ?>>Bank Account</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4 bill-method-account bill-paid-fields" data-method-prefix="paid_from" data-method-type="jazzcash"<?= $billStatus === 'paid' && $paidFromType === 'jazzcash' ? '' : ' style="display:none;"' ?>>
                        <label class="form-label" for="paid_from_jazzcash_account_id">Paid From JazzCash</label>
                        <select class="form-select" id="paid_from_jazzcash_account_id" name="paid_from_account_id">
                            <option value="">-- Select JazzCash --</option>
                            <?php foreach (($billMethodAccounts['jazzcash'] ?? []) as $account): ?>
                                <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= $billStatus === 'paid' && $paidFromType === 'jazzcash' && $paidFromAccountId === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>><?= h((string) ($account['account_name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4 bill-method-account bill-paid-fields" data-method-prefix="paid_from" data-method-type="easypaisa"<?= $billStatus === 'paid' && $paidFromType === 'easypaisa' ? '' : ' style="display:none;"' ?>>
                        <label class="form-label" for="paid_from_easypaisa_account_id">Paid From EasyPaisa</label>
                        <select class="form-select" id="paid_from_easypaisa_account_id" name="paid_from_account_id">
                            <option value="">-- Select EasyPaisa --</option>
                            <?php foreach (($billMethodAccounts['easypaisa'] ?? []) as $account): ?>
                                <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= $billStatus === 'paid' && $paidFromType === 'easypaisa' && $paidFromAccountId === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>><?= h((string) ($account['account_name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4 bill-method-account bill-paid-fields" data-method-prefix="paid_from" data-method-type="bank"<?= $billStatus === 'paid' && $paidFromType === 'bank' ? '' : ' style="display:none;"' ?>>
                        <label class="form-label" for="paid_from_bank_account_id">Paid From Bank</label>
                        <select class="form-select" id="paid_from_bank_account_id" name="paid_from_account_id">
                            <option value="">-- Select Bank Account --</option>
                            <?php foreach (($billMethodAccounts['bank'] ?? []) as $account): ?>
                                <option value="<?= (int) ($account['id'] ?? 0) ?>" <?= $billStatus === 'paid' && $paidFromType === 'bank' && $paidFromAccountId === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>><?= h((string) ($account['account_name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">Notes</label>
                        <input class="form-control" id="notes" name="notes" value="<?= h($notes) ?>" placeholder="Optional note">
                    </div>
                    <div class="col-12">
                        <div class="small text-muted">
                            Customer collection and company payment are separate. Received In updates only the selected source. Paid From deducts only from the selected source.
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
                <details class="mb-4" <?= $editCategory ? 'open' : '' ?>>
                    <summary class="fw-semibold">Bill Categories</summary>
                    <div class="pt-3">
                        <?php if ($editCategory): ?>
                            <div class="mb-3">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('bill-payments/index.php?' . $returnQuery)) ?>">Cancel Edit</a>
                            </div>
                        <?php endif; ?>
                        <form method="post" class="row g-3 mb-4">
                            <input type="hidden" name="action" value="<?= $editCategory ? 'update_category' : 'add_category' ?>">
                            <input type="hidden" name="category_id" value="<?= h((string) $manageCategoryId) ?>">
                            <input type="hidden" name="from" value="<?= h($from) ?>">
                            <input type="hidden" name="to" value="<?= h($to) ?>">
                            <input type="hidden" name="company" value="<?= h($company) ?>">
                            <input type="hidden" name="status" value="<?= h($status) ?>">
                            <input type="hidden" name="q" value="<?= h($q) ?>">
                            <div class="col-12">
                                <label class="form-label" for="bill_category_name">Category Name</label>
                                <input class="form-control" id="bill_category_name" name="bill_category_name" value="<?= h($billCategoryName) ?>" placeholder="Electricity / Internet / PTCL" required>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-outline-primary"><?= $editCategory ? 'Update Category' : 'Add Category' ?></button>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                <tr>
                                    <th>Category</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($billCategoryRows as $categoryRow): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= h((string) ($categoryRow['category_name'] ?? '')) ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2">
                                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('bill-payments/index.php?' . $returnQuery . '&edit_category=' . (int) ($categoryRow['id'] ?? 0))) ?>">Edit</a>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this bill category?');">
                                                    <input type="hidden" name="action" value="delete_category">
                                                    <input type="hidden" name="category_id" value="<?= h((string) (int) ($categoryRow['id'] ?? 0)) ?>">
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
                                <?php if (!$billCategoryRows): ?>
                                    <tr>
                                        <td colspan="2" class="text-center text-muted py-3">No bill categories found.</td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>

                <details <?= $editCompany ? 'open' : '' ?>>
                    <summary class="fw-semibold">Manage Bill Companies</summary>
                    <div class="pt-3">
                        <?php if ($editCompany): ?>
                            <div class="mb-3">
                                <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('bill-payments/index.php?' . $returnQuery)) ?>">Cancel Edit</a>
                            </div>
                        <?php endif; ?>

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
                                    <?php foreach ($billCategoryRows as $categoryOptionRow): ?>
                                        <?php $categoryOption = trim((string) ($categoryOptionRow['category_name'] ?? '')); ?>
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
                </details>
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

<form method="post" id="bulkPayForm" class="d-none">
    <input type="hidden" name="action" value="bulk_mark_paid">
    <input type="hidden" name="from" value="<?= h($from) ?>">
    <input type="hidden" name="to" value="<?= h($to) ?>">
    <input type="hidden" name="company" value="<?= h($company) ?>">
    <input type="hidden" name="status" value="<?= h($status) ?>">
    <input type="hidden" name="q" value="<?= h($q) ?>">
    <input type="hidden" name="paid_from_account_id" id="bulk_paid_from_account_hidden" value="">
</form>
<div class="card border-0 shadow-sm">
        <div class="card-body border-bottom">
            <div class="d-flex flex-wrap gap-3 justify-content-between align-items-start">
                <div>
                    <div class="fw-semibold">All Pending Bills</div>
                    <div class="text-muted small">Yahan hamesha saare pending bills show honge, chahe filters kuch bhi hon.</div>
                    <div class="small mt-2">
                        <span class="text-muted">Selected Bills:</span>
                        <span id="bulkPaySelectedBills" class="fw-semibold">No bills selected.</span>
                    </div>
                    <div class="small">
                        <span class="text-muted">Total Bill Amount:</span>
                        <span id="bulkPayTotal" class="fw-semibold">Rs 0.00</span>
                    </div>
                </div>
                <div id="bulkActionBar" class="d-flex flex-wrap gap-2 align-items-end">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="selectAllBills">Select All</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="deselectAllBills">Deselect All</button>
                        <button type="button" class="btn btn-success btn-sm" id="openBulkPayModal">Mark Selected As Paid</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th style="width:48px;"><input type="checkbox" id="billSelectAllCheckbox"></th>
                        <th>Bill ID</th>
                        <th>Customer</th>
                        <th>Company</th>
                        <th>Category</th>
                        <th>Received In</th>
                        <th class="text-end">Bill Amount</th>
                        <th class="text-end">Service Charge</th>
                        <th class="text-end">Total Received</th>
                        <th>Payment Date</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th>Paid From</th>
                        <th>Paid At</th>
                        <th>History</th>
                        <th>Notes</th>
                        <th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingRows as $row): ?>
                        <?php
                        $isPending = (string) ($row['status'] ?? '') !== 'paid';
                        $historyRows = $paymentHistoryMap[(int) ($row['id'] ?? 0)] ?? [];
                        ?>
                        <tr>
                            <td>
                                <?php if ($isPending): ?>
                                    <input class="form-check-input bill-row-checkbox" type="checkbox" form="bulkPayForm" name="selected_bill_ids[]" value="<?= (int) ($row['id'] ?? 0) ?>" data-company="<?= h((string) ($row['company_name'] ?? '')) ?>" data-amount="<?= h(number_format((float) ($row['bill_amount'] ?? 0), 2, '.', '')) ?>">
                                <?php endif; ?>
                            </td>
                            <td class="fw-semibold"><?= h((string) ($row['bill_id'] ?? '')) ?></td>
                            <td><?= h((string) ($row['customer_name'] ?? '')) ?></td>
                            <td><?= h((string) ($row['company_name'] ?? '')) ?></td>
                            <td><?= h((string) ($row['category_name'] ?? '')) ?></td>
                            <td>
                                <?php
                                $receivedLabel = bill_method_label((string) ($row['received_in_type'] ?? 'cash'));
                                $receivedAccountLabel = trim((string) ($row['received_in_account_name'] ?? ''));
                                ?>
                                <?= h($receivedLabel) ?><?= $receivedAccountLabel !== '' ? ' - ' . h($receivedAccountLabel) : '' ?>
                            </td>
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
                            <td>
                                <?php
                                $paidLabel = trim((string) ($row['paid_from_type'] ?? '')) !== '' ? bill_method_label((string) ($row['paid_from_type'] ?? '')) : '-';
                                $paidAccountLabel = trim((string) ($row['paid_from_account_name'] ?? ''));
                                ?>
                                <?= h($paidLabel) ?><?= $paidAccountLabel !== '' ? ' - ' . h($paidAccountLabel) : '' ?>
                            </td>
                            <td><?= h((string) ($row['paid_at'] ?? '')) ?></td>
                            <td>
                                <?php if ($historyRows): ?>
                                    <?php foreach ($historyRows as $historyRow): ?>
                                        <div class="small mb-1">
                                            <strong><?= h((string) ($historyRow['payment_date'] ?? '')) ?></strong><br>
                                            <?= h(bill_method_label((string) ($historyRow['paid_from_type'] ?? ''))) ?><?= trim((string) ($historyRow['account_name'] ?? '')) !== '' ? ' - ' . h((string) ($historyRow['account_name'] ?? '')) : '' ?><br>
                                            Rs <?= h(number_format((float) ($historyRow['total_amount'] ?? 0), 2)) ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted small">No payment history</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h((string) ($row['notes'] ?? '')) ?></td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-2">
                                    <?php if ($isPending): ?>
                                        <button type="button" class="btn btn-outline-success btn-sm pay-single-bill" data-bill-id="<?= (int) ($row['id'] ?? 0) ?>">Mark Paid</button>
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
                    <?php if (!$pendingRows): ?>
                        <tr>
                            <td colspan="17" class="text-center text-muted py-4">No pending bills found.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<div id="bulkPayModal" class="position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 d-none" style="z-index:1080;">
    <div class="d-flex align-items-center justify-content-center w-100 h-100 p-3">
        <div class="card border-0 shadow-lg w-100" style="max-width: 520px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start justify-content-between mb-3">
                    <div>
                        <h3 class="h5 mb-1">Pay Bills From</h3>
                        <div class="text-muted small">Selected bills ko paid mark karne se pehle account select karo.</div>
                    </div>
                    <button type="button" class="btn-close" id="closeBulkPayModal" aria-label="Close"></button>
                </div>
                <div class="mb-3">
                    <div class="small text-muted">Selected Bills</div>
                    <div class="fw-semibold" id="modalSelectedBills">No bills selected.</div>
                </div>
                <div class="mb-3">
                    <div class="small text-muted">Total Bill Amount</div>
                    <div class="h5 mb-0" id="modalSelectedTotal">Rs 0.00</div>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="bulk_paid_from_account_id">Pay From Account</label>
                    <select class="form-select" id="bulk_paid_from_account_id">
                        <option value="">-- Select Account --</option>
                        <?php foreach ($flatPayAccounts as $account): ?>
                            <option value="<?= (int) ($account['id'] ?? 0) ?>">
                                <?= h((string) ($account['account_name'] ?? '')) ?> (<?= h(bill_method_label((string) ($account['account_type'] ?? 'cash'))) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="cancelBulkPayModal">Cancel</button>
                    <button type="button" class="btn btn-success" id="confirmBulkPayModal">Confirm</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const savedCustomerSelect = document.getElementById('saved_customer_select_bill');
    const customerNameInput = document.getElementById('customer_name');
    const companySelect = document.getElementById('company_name');
    const companyCategoryDisplay = document.getElementById('company_category_display');
    const billStatusSelect = document.getElementById('bill_status');
    const methodToggles = document.querySelectorAll('.bill-method-toggle');
    const rowCheckboxes = Array.from(document.querySelectorAll('.bill-row-checkbox'));
    const selectAllBtn = document.getElementById('selectAllBills');
    const deselectAllBtn = document.getElementById('deselectAllBills');
    const selectAllCheckbox = document.getElementById('billSelectAllCheckbox');
    const openBulkPayModalBtn = document.getElementById('openBulkPayModal');
    const bulkActionBar = document.getElementById('bulkActionBar');
    const bulkPaySelectedBills = document.getElementById('bulkPaySelectedBills');
    const bulkPayTotal = document.getElementById('bulkPayTotal');
    const bulkPayModal = document.getElementById('bulkPayModal');
    const closeBulkPayModalBtn = document.getElementById('closeBulkPayModal');
    const cancelBulkPayModalBtn = document.getElementById('cancelBulkPayModal');
    const confirmBulkPayModalBtn = document.getElementById('confirmBulkPayModal');
    const modalSelectedBills = document.getElementById('modalSelectedBills');
    const modalSelectedTotal = document.getElementById('modalSelectedTotal');
    const bulkPaidFromAccountSelect = document.getElementById('bulk_paid_from_account_id');
    const bulkPaidFromAccountHidden = document.getElementById('bulk_paid_from_account_hidden');
    const paySingleButtons = Array.from(document.querySelectorAll('.pay-single-bill'));

    if (savedCustomerSelect && customerNameInput) {
        savedCustomerSelect.addEventListener('change', function () {
            const selected = savedCustomerSelect.options[savedCustomerSelect.selectedIndex];
            const customerName = selected ? (selected.getAttribute('data-name') || '') : '';
            if (customerName !== '') {
                customerNameInput.value = customerName;
            }
        });
    }

    const syncCompanyCategory = function () {
        if (!companySelect || !companyCategoryDisplay) {
            return;
        }
        const selected = companySelect.options[companySelect.selectedIndex];
        companyCategoryDisplay.value = selected ? (selected.getAttribute('data-category') || '') : '';
    };
    if (companySelect) {
        syncCompanyCategory();
        companySelect.addEventListener('change', syncCompanyCategory);
    }

    const syncMethodFields = function (prefix, value) {
        document.querySelectorAll('.bill-method-account[data-method-prefix="' + prefix + '"]').forEach(function (wrap) {
            const show = wrap.getAttribute('data-method-type') === value;
            wrap.style.display = show ? '' : 'none';
            wrap.querySelectorAll('select, input').forEach(function (field) {
                field.disabled = !show;
            });
        });
    };

    methodToggles.forEach(function (select) {
        syncMethodFields(select.getAttribute('data-target-prefix') || '', select.value);
        select.addEventListener('change', function () {
            syncMethodFields(select.getAttribute('data-target-prefix') || '', select.value);
        });
    });

    if (billStatusSelect) {
        const syncPaidFields = function () {
            const show = billStatusSelect.value === 'paid';
            document.querySelectorAll('.bill-paid-fields').forEach(function (field) {
                field.style.display = show ? '' : 'none';
            });
            if (show) {
                const paidFrom = document.getElementById('paid_from_type');
                if (paidFrom) {
                    syncMethodFields('paid_from', paidFrom.value);
                }
            }
        };
        syncPaidFields();
        billStatusSelect.addEventListener('change', syncPaidFields);
    }

    const syncBulkSummary = function () {
        const selectedRows = rowCheckboxes.filter(function (checkbox) {
            return checkbox.checked;
        });
        const names = selectedRows.map(function (checkbox) {
            return checkbox.getAttribute('data-company') || '';
        }).filter(Boolean);
        const total = selectedRows.reduce(function (sum, checkbox) {
            return sum + (parseFloat(checkbox.getAttribute('data-amount') || '0') || 0);
        }, 0);
        if (bulkPaySelectedBills) {
            bulkPaySelectedBills.textContent = names.length ? names.join(', ') : 'No bills selected.';
        }
        if (bulkPayTotal) {
            bulkPayTotal.textContent = 'Rs ' + total.toFixed(2);
        }
        if (modalSelectedBills) {
            modalSelectedBills.textContent = names.length ? names.join(', ') : 'No bills selected.';
        }
        if (modalSelectedTotal) {
            modalSelectedTotal.textContent = 'Rs ' + total.toFixed(2);
        }
        if (openBulkPayModalBtn) {
            openBulkPayModalBtn.disabled = names.length === 0;
        }
        if (selectAllCheckbox) {
            const allChecked = rowCheckboxes.length > 0 && rowCheckboxes.every(function (checkbox) { return checkbox.checked; });
            selectAllCheckbox.checked = allChecked;
        }
    };

    const focusBulkActionBar = function () {
        if (bulkActionBar) {
            bulkActionBar.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        if (bulkPaidFromAccountSelect) {
            window.setTimeout(function () {
                bulkPaidFromAccountSelect.focus();
            }, 150);
        }
    };

    const openPaymentModal = function () {
        const selectedRows = rowCheckboxes.filter(function (checkbox) {
            return checkbox.checked;
        });
        if (!selectedRows.length || !bulkPayModal) {
            return;
        }
        bulkPayModal.classList.remove('d-none');
        document.body.classList.add('overflow-hidden');
        if (bulkPaidFromAccountSelect) {
            window.setTimeout(function () {
                bulkPaidFromAccountSelect.focus();
            }, 100);
        }
    };

    const closePaymentModal = function () {
        if (!bulkPayModal) {
            return;
        }
        bulkPayModal.classList.add('d-none');
        document.body.classList.remove('overflow-hidden');
    };

    rowCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', syncBulkSummary);
    });
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function () {
            rowCheckboxes.forEach(function (checkbox) { checkbox.checked = true; });
            syncBulkSummary();
        });
    }
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function () {
            rowCheckboxes.forEach(function (checkbox) { checkbox.checked = false; });
            syncBulkSummary();
        });
    }
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            rowCheckboxes.forEach(function (checkbox) { checkbox.checked = selectAllCheckbox.checked; });
            syncBulkSummary();
        });
    }
    paySingleButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const billId = button.getAttribute('data-bill-id');
            rowCheckboxes.forEach(function (checkbox) {
                checkbox.checked = checkbox.value === billId;
            });
            syncBulkSummary();
            openPaymentModal();
        });
    });
    if (openBulkPayModalBtn) {
        openBulkPayModalBtn.addEventListener('click', function (event) {
            if (openBulkPayModalBtn.disabled) {
                event.preventDefault();
                return;
            }
            event.preventDefault();
            openPaymentModal();
        });
    }
    if (closeBulkPayModalBtn) {
        closeBulkPayModalBtn.addEventListener('click', closePaymentModal);
    }
    if (cancelBulkPayModalBtn) {
        cancelBulkPayModalBtn.addEventListener('click', closePaymentModal);
    }
    if (bulkPayModal) {
        bulkPayModal.addEventListener('click', function (event) {
            if (event.target === bulkPayModal) {
                closePaymentModal();
            }
        });
    }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && bulkPayModal && !bulkPayModal.classList.contains('d-none')) {
            closePaymentModal();
        }
    });
    if (confirmBulkPayModalBtn) {
        confirmBulkPayModalBtn.addEventListener('click', function () {
            if (!bulkPaidFromAccountSelect || (bulkPaidFromAccountSelect.value || '') === '') {
                if (bulkPaidFromAccountSelect) {
                    bulkPaidFromAccountSelect.focus();
                }
                return;
            }
            if (bulkPaidFromAccountHidden) {
                bulkPaidFromAccountHidden.value = bulkPaidFromAccountSelect.value || '';
            }
            const bulkPayForm = document.getElementById('bulkPayForm');
            if (bulkPayForm) {
                bulkPayForm.submit();
            }
        });
    }
    syncBulkSummary();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
