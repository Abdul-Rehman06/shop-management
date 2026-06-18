<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
flash_set('success', 'Load Management has been updated to Daily Totals. Use the main Load Management page.');
app_redirect('load-management/index.php');
