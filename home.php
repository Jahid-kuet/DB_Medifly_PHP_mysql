<?php
require_once __DIR__ . '/includes/auth.php';

// If not logged in show a simple landing with register/login links
if (!currentUserId()) {
    $pageTitle = APP_NAME;
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="row justify-content-center py-5">
        <div class="col-md-8 text-center">
            <h1 class="mb-3"><?php echo htmlspecialchars(APP_NAME); ?></h1>
            <p class="lead">Welcome to the MediFly delivery management system. Please sign in or register to continue.</p>
            <div class="d-flex justify-content-center gap-2 mt-4">
                <a class="btn btn-primary" href="<?php echo BASE_PATH; ?>/auth/login.php">Sign in</a>
                <a class="btn btn-outline-secondary" href="<?php echo BASE_PATH; ?>/auth/self_register.php">Register</a>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

redirectToDashboard();
