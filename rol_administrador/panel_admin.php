<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);

// 1) Sesión y rol
require_once __DIR__.'/../session_manager.php';
require_once __DIR__.'/../db.php';

if (!isset($_SESSION['ID_Operador'])) {
    header('Location: ../login.php?mensaje=Debe iniciar sesión'); exit;
}
if ((int)$_SESSION['Rol'] !== 1) {   // Rol 1 = Administrador
    echo "<p class='error'>⚠️ Acceso denegado.</p>"; exit;
}


?>
<!DOCTYPE html><html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel Administrador — Plantulas Agrodex</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../style.css?v=<?=filemtime(__DIR__.'/../style.css')?>">
<style>
body{background:#f3f4f6}
.card-admin{min-height:160px;transition:transform .15s}
.card-admin:hover{transform:translateY(-4px)}
</style>
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 10000000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 10000000 ?>;
    let START_TS         = <?= $nowTs           * 10000000 ?>;
  </script>
</head>
<body>
<div class="container-fluid py-3">

  <!-- Encabezado -->
  <header class="d-flex align-items-center justify-content-between mb-4">
    <div class="d-flex align-items-center gap-3">
      <a href="panel_admin.php"><img src="../logoplantulas.png" alt="Logo" width="90"></a>
      <div>
        <h2 class="mb-0">Panel de Administración</h2>
        <small class="text-muted">Control y configuración</small>
      </div>
    </div>

    <div class="d-flex gap-2 align-items-center">
      <?php if (!empty($_SESSION['Rol_Original'])): ?>
        <a href="volver_rol.php" class="btn btn-outline-warning btn-sm">Volver a rol administrador</a>
      <?php endif; ?>
      <button onclick="location.href='../logout.php'" class="btn btn-outline-danger btn-sm">Cerrar sesión</button>
    </div>
  </header>

  <!-- Tarjetas -->
  <h5 class="mb-3">¡Hola, <?=htmlspecialchars($_SESSION['Nombre'])?>!</h5>
  <div class="row g-4">

    <!-- Alta operador -->
    <div class="col-12 col-sm-6 col-lg-4">
      <a href="registro_operador.php" class="text-decoration-none">
        <div class="card shadow-sm card-admin h-100">
          <div class="card-body text-center">
            <h4 class="card-title">➕ Registrar operador</h4>
            <p class="card-text small text-muted">Crear nuevos usuarios</p>
          </div>
        </div>
      </a>
    </div>

    <!-- Gestionar operadores -->
    <div class="col-12 col-sm-6 col-lg-4">
      <a href="gestionar_operadores.php" class="text-decoration-none">
        <div class="card shadow-sm card-admin h-100">
          <div class="card-body text-center">
            <h4 class="card-title">👥 Gestionar operadores</h4>
            <p class="card-text small text-muted">Editar / desactivar usuarios</p>
          </div>
        </div>
      </a>
    </div>

    <!-- Reportes -->
    <div class="col-12 col-sm-6 col-lg-4">
      <a href="ver_reportes.php" class="text-decoration-none">
        <div class="card shadow-sm card-admin h-100">
          <div class="card-body text-center">
            <h4 class="card-title">📊 Reportes</h4>
            <p class="card-text small text-muted">Estadísticas generales</p>
          </div>
        </div>
      </a>
    </div>

    <!-- Llave maestra -->
    <div class="col-12 col-sm-6 col-lg-4">
      <a href="llave_maestra.php" class="text-decoration-none">
        <div class="card shadow-sm card-admin h-100 bg-warning-subtle">
          <div class="card-body text-center">
            <h4 class="card-title">🔑 Llave maestra</h4>
            <p class="card-text small text-muted">Impersonar otros roles</p>
          </div>
        </div>
      </a>
    </div>

  </div><!-- /row -->
</div><!-- /container -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
