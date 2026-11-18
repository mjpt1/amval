<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

if (is_client()) {
    redirect('client_view.php');
}
redirect('dashboard.php');
