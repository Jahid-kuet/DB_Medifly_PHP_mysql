<?php
require_once __DIR__ . '/auth.php';

$pageTitle = $pageTitle ?? APP_NAME;
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?php echo BASE_PATH; ?>/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo BASE_PATH; ?>/home.php"><?php echo htmlspecialchars(APP_NAME); ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if (isRole('admin')): ?>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>/admin/dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>/admin/hospitals.php"><i class="bi bi-hospital"></i> Hospitals</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>/admin/users.php"><i class="bi bi-people"></i> Users</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>/admin/drones.php"><i class="bi bi-airplane"></i> Drones</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>/admin/supplies.php"><i class="bi bi-box-seam"></i> Supplies</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>/admin/requests.php"><i class="bi bi-card-checklist"></i> Requests</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>/admin/payments.php"><i class="bi bi-credit-card"></i> Payments</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>/admin/operators.php"><i class="bi bi-person-badge"></i> Operators</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>/admin/logs.php"><i class="bi bi-journal-text"></i> Logs</a></li>
                <?php elseif (isRole('hospital')): ?>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>/hospital/dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>/hospital/requests.php"><i class="bi bi-card-checklist"></i> My Requests</a></li>
                <?php elseif (isRole('operator')): ?>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>/operator/dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_PATH; ?>/operator/requests.php"><i class="bi bi-truck"></i> Assigned Deliveries</a></li>
                <?php endif; ?>
            </ul>
            <div class="d-flex">
                <?php if (currentUserId()): ?>
                    <span class="navbar-text text-white me-3 text-capitalize">
                        Role: <?php echo htmlspecialchars(currentRole() ?? ''); ?>
                    </span>
                    <a class="btn btn-outline-light" href="<?php echo BASE_PATH; ?>/auth/logout.php">Logout</a>
                <?php else: ?>
                    <a class="btn btn-outline-light" href="<?php echo BASE_PATH; ?>/auth/login.php">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
<div class="container py-4">
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
