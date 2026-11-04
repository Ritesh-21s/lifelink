<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
logoutUser();
redirect(APP_URL . '/pages/login.php', 'success', 'You have been logged out successfully.');
