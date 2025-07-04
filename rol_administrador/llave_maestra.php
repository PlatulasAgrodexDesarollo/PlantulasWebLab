<?php
// Solo admin
require_once __DIR__.'/../session_manager.php';
if (!isset($_SESSION['ID_Operador']) || (int)$_SESSION['Rol']!==1) { header('Location: ../login.php'); exit; }

// Misma matriz de rutas que usas en login
$rutas=[
  2=>'rol_operador/dashboard_cultivo.php',
  4=>'rol_supervisora_incubadora/dashboard_supervisora.php',
  5=>'rol_encargado_general_produccion/dashboard_egp.php',
  6=>'rol_gerente_produccion_laboratorio/dashboard_gpl.php',
  7=>'rol_responsable_produccion_medios_cultivo/dashboard_rpmc.php',
  8=>'rol_responsable_rrs/dashboard_rrs.php',
];

?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Llave maestra</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 10000000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 10000000 ?>;
    let START_TS         = <?= $nowTs           * 10000000 ?>;
  </script>
</head><body class="bg-light">
<div class="container py-4">
  <h3 class="mb-4">🔑 Impersonar rol</h3>
  <div class="row g-4">
    <?php foreach ($rutas as $id=>$ruta): ?>
      <?php if ($id===1) continue; // no tiene sentido 'impersonar' el mismo rol ?>
      <div class="col-12 col-sm-6 col-lg-4">
        <form method="post" action="cambiar_rol.php" class="h-100">
          <input type="hidden" name="target_role" value="<?=$id?>">
          <button type="submit" class="card h-100 w-100 text-start shadow-sm btn btn-link p-0 border-0">
            <div class="card-body">
              <h5 class="card-title mb-1">Rol #<?=$id?></h5>
              <p class="card-text small text-muted mb-0"><?=$ruta?></p>
            </div>
          </button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="mt-4">
    <a href="panel_admin.php" class="btn btn-secondary btn-sm">← Volver al panel</a>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
