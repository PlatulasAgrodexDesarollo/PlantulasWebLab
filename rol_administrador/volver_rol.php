<?php
require_once __DIR__.'/../session_manager.php';

if (isset($_SESSION['origin'])) {
    $_SESSION['Rol'] = $_SESSION['origin'];      // regresa a rol 1
    unset($_SESSION['origin'], $_SESSION['Impersonando']);
}

header('Location: panel_admin.php');
exit;
?>
