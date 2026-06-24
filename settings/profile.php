<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$pageTitle = 'Edit Profile - Shop Management';
$pdo = db();

$admin = app_current_admin();
$adminId = (int) ($admin['id'] ?? 0);

if ($adminId <= 0) {
    app_redirect('dashboard/index.php');
}

$stmt = $pdo->prepare("SELECT * FROM admins WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $adminId]);
$user = $stmt->fetch();

if (!$user) {
    app_redirect('dashboard/index.php');
}

$name = (string) ($user['name'] ?? '');
$email = (string) ($user['email'] ?? '');
$profileImage = (string) ($user['profile_image'] ?? '');
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));

    if ($name === '') {
        $error = 'Name is required.';
    } elseif ($email === '') {
        $error = 'Email is required.';
    } else {
        // Handle file upload
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['profile_image']['tmp_name'];
            $fileName = $_FILES['profile_image']['name'];
            $fileSize = $_FILES['profile_image']['size'];
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (!in_array($fileExt, $allowed, true)) {
                $error = 'Invalid image format. Allowed: JPG, PNG, GIF, WEBP.';
            } elseif ($fileSize > 2 * 1024 * 1024) {
                $error = 'Image size must be less than 2MB.';
            } else {
                $newName = 'profile_' . $adminId . '_' . time() . '.' . $fileExt;
                $uploadDir = __DIR__ . '/../uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $dest = $uploadDir . $newName;

                if (move_uploaded_file($tmpName, $dest)) {
                    // Delete old image if exists
                    if ($profileImage !== '' && file_exists($uploadDir . $profileImage)) {
                        unlink($uploadDir . $profileImage);
                    }
                    $profileImage = $newName;
                } else {
                    $error = 'Failed to upload image.';
                }
            }
        }

        if ($error === '') {
            $stmt = $pdo->prepare("
                UPDATE admins
                SET name = :name, email = :email, profile_image = :profile_image
                WHERE id = :id
            ");
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':profile_image' => $profileImage !== '' ? $profileImage : null,
                ':id' => $adminId,
            ]);

            // Update session
            $_SESSION['admin']['name'] = $name;
            $_SESSION['admin']['email'] = $email;
            $_SESSION['admin']['profile_image'] = $profileImage !== '' ? $profileImage : null;

            $success = 'Profile updated successfully.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 animate-slide-up">
    <div>
        <h1 class="h3 mb-1 text-gray-800 font-bold">Edit Profile</h1>
        <div class="text-gray-500 text-sm">Update your personal details and profile picture</div>
    </div>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success border-0 shadow-sm animate-slide-up"><?= h($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger border-0 shadow-sm animate-slide-up"><?= h($error) ?></div>
<?php endif; ?>

<div class="glass-card animate-slide-up stagger-1 max-w-2xl">
    <div class="card-body p-4 p-md-5">
        <form method="post" enctype="multipart/form-data" class="row g-4">
            <div class="col-12 d-flex flex-column align-items-center justify-content-center mb-4">
                <div class="position-relative mb-3 group">
                    <?php if ($profileImage !== ''): ?>
                        <img src="<?= h(app_url('uploads/' . $profileImage)) ?>" alt="Profile" class="rounded-circle object-fit-cover shadow-sm border border-4 border-white" style="width: 120px; height: 120px;">
                    <?php else: ?>
                        <div class="rounded-circle bg-gradient-premium d-flex items-center justify-center shadow-sm border border-4 border-white" style="width: 120px; height: 120px;">
                            <span class="text-white font-bold" style="font-size: 48px;"><?= h(strtoupper(substr($name, 0, 1))) ?></span>
                        </div>
                    <?php endif; ?>
                    <label for="profile_image" class="position-absolute bottom-0 end-0 bg-white text-primary rounded-circle shadow p-2 cursor-pointer hover-lift d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; right: 4px; bottom: 4px;" title="Change Image">
                        <i data-lucide="camera" class="w-4 h-4"></i>
                    </label>
                    <input type="file" id="profile_image" name="profile_image" class="d-none" accept="image/*">
                </div>
                <div class="text-gray-500 text-sm">Allowed: JPG, PNG, GIF. Max 2MB.</div>
            </div>

            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold text-gray-700" for="name">Name <span class="text-danger">*</span></label>
                <input class="form-control form-control-lg bg-light border-0 shadow-none focus-ring" type="text" id="name" name="name" value="<?= h($name) ?>" required>
            </div>
            
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold text-gray-700" for="email">Email <span class="text-danger">*</span></label>
                <input class="form-control form-control-lg bg-light border-0 shadow-none focus-ring" type="email" id="email" name="email" value="<?= h($email) ?>" required>
            </div>
            
            <div class="col-12 mt-5 d-flex gap-3">
                <button class="btn btn-gradient shadow-glow btn-lg px-5 rounded-xl hover-lift d-inline-flex align-items-center gap-2">
                    <i data-lucide="save" class="w-5 h-5"></i> Save Profile
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('profile_image').addEventListener('change', function(e) {
    if (this.files && this.files[0]) {
        // Preview logic could go here
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = document.querySelector('.position-relative img');
            if (img) {
                img.src = e.target.result;
            } else {
                const container = document.querySelector('.position-relative');
                const newImg = document.createElement('img');
                newImg.src = e.target.result;
                newImg.className = 'rounded-circle object-fit-cover shadow-sm border border-4 border-white';
                newImg.style.width = '120px';
                newImg.style.height = '120px';
                const avatar = container.querySelector('.bg-gradient-premium');
                if (avatar) container.removeChild(avatar);
                container.insertBefore(newImg, container.firstChild);
            }
        }
        reader.readAsDataURL(this.files[0]);
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
