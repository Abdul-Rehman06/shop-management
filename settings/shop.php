<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Shop Settings - Shop Management';

$pdo = db();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS shop_settings (
        id TINYINT UNSIGNED NOT NULL,
        company_name VARCHAR(150) NOT NULL DEFAULT '',
        phone VARCHAR(50) NOT NULL DEFAULT '',
        address VARCHAR(255) NOT NULL DEFAULT '',
        logo_path VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("INSERT IGNORE INTO shop_settings (id) VALUES (1)");

$stmt = $pdo->query('SELECT * FROM shop_settings WHERE id = 1');
$settings = $stmt->fetch() ?: ['company_name' => '', 'phone' => '', 'address' => '', 'logo_path' => null];

$companyName = (string) ($settings['company_name'] ?? '');
$phone = (string) ($settings['phone'] ?? '');
$address = (string) ($settings['address'] ?? '');
$logoPath = (string) ($settings['logo_path'] ?? '');

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $companyName = trim((string) ($_POST['company_name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));

    if ($companyName === '') {
        $error = 'Company name is required.';
    } else {
        $newLogoPath = $logoPath;
        if (!empty($_FILES['logo']['name'])) {
            $file = $_FILES['logo'];
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Logo upload failed.';
            } else {
                $tmp = (string) $file['tmp_name'];
                $size = (int) $file['size'];
                $original = (string) $file['name'];
                $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
                $allowed = ['png', 'jpg', 'jpeg', 'webp'];
                if ($size <= 0 || $size > 2 * 1024 * 1024) {
                    $error = 'Logo must be less than 2MB.';
                } elseif (!in_array($ext, $allowed, true)) {
                    $error = 'Logo must be PNG, JPG, JPEG, or WEBP.';
                } else {
                    $imagesDir = realpath(__DIR__ . '/../assets/images');
                    if ($imagesDir === false) {
                        $error = 'Images folder not found.';
                    } else {
                        $fileName = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $dest = $imagesDir . DIRECTORY_SEPARATOR . $fileName;
                        if (!move_uploaded_file($tmp, $dest)) {
                            $error = 'Failed to save uploaded logo.';
                        } else {
                            $newLogoPath = 'assets/images/' . $fileName;
                        }
                    }
                }
            }
        }

        if ($error === '') {
            $stmt = $pdo->prepare('
                UPDATE shop_settings
                SET company_name = :company_name,
                    phone = :phone,
                    address = :address,
                    logo_path = :logo_path
                WHERE id = 1
            ');
            $stmt->execute([
                ':company_name' => $companyName,
                ':phone' => $phone,
                ':address' => $address,
                ':logo_path' => $newLogoPath !== '' ? $newLogoPath : null,
            ]);
            $logoPath = $newLogoPath;
            $success = 'Shop settings saved successfully.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Shop Settings</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= h(app_url('settings/index.php')) ?>">Back</a>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label" for="company_name">Company Name</label>
                    <input class="form-control" type="text" id="company_name" name="company_name" value="<?= h($companyName) ?>" required>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label" for="phone">Phone</label>
                    <input class="form-control" type="text" id="phone" name="phone" value="<?= h($phone) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="address">Address</label>
                    <input class="form-control" type="text" id="address" name="address" value="<?= h($address) ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label" for="logo">Company Logo</label>
                    <input class="form-control" type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,.webp">
                    <div class="text-muted small mt-1">Max 2MB. PNG/JPG/JPEG/WEBP.</div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label d-block">Current Logo</label>
                    <?php if ($logoPath !== ''): ?>
                        <img src="<?= h(app_url($logoPath)) ?>" alt="Logo" style="max-height:60px;max-width:100%;background:#fff;border:1px solid #eee;padding:6px;border-radius:6px;">
                    <?php else: ?>
                        <div class="text-muted">No logo uploaded.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-3">
                <button class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

