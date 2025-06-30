<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Validar sesión y rol
require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['ID_Operador'])) {
    header('Location: ../login.php?mensaje=Debe iniciar sesión');
    exit;
}
$ID_Operador = (int) $_SESSION['ID_Operador'];

if ((int) $_SESSION['Rol'] !== 7) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Solo Responsable de Producción de Medios de Cultivo.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

$query = "
  SELECT 
    d.ID_Dilucion,
    m.Codigo_Medio,
    d.Fecha_Preparacion,
    d.Cantidad_MedioMadre,
    d.Volumen_Final,
    d.Tuppers_Llenos,
    o.Nombre,
    m.Cantidad_Disponible,
    (d.Cantidad_MedioMadre + m.Cantidad_Disponible) AS Cantidad_Original
  FROM dilucion_llenado_tuppers d
  JOIN medios_nutritivos_madre m ON d.ID_MedioNM = m.ID_MedioNM
  JOIN operadores o ON d.Operador_Responsable = o.ID_Operador
  ORDER BY m.Codigo_Medio, d.Fecha_Preparacion DESC
";

$resultado = $conn->query($query);

$colorMap = [];
$colorIndex = 1;
$historial = [];

while ($row = $resultado->fetch_assoc()) {
  $codigo = $row['Codigo_Medio'];
  if (!isset($colorMap[$codigo])) {
    $colorMap[$codigo] = $colorIndex;
    $colorIndex++;
    if ($colorIndex > 6) $colorIndex = 1;
  }
  $row['color_class'] = 'color-medio-' . $colorMap[$codigo];
  $historial[] = $row;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Historial de Homogenizaciones</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../style.css?v=<?=time();?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .color-medio-1 { background-color: #b3e5fc !important; }
    .color-medio-2 { background-color: #a5d6a7 !important; }
    .color-medio-3 { background-color: #ffe082 !important; }
    .color-medio-4 { background-color: #f8bbd0 !important; }
    .color-medio-5 { background-color: #d1c4e9 !important; }
    .color-medio-6 { background-color: #ce93d8 !important; }
  </style>
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>
<div class="contenedor-pagina">
  <header>
    <div class="encabezado">
      <a class="navbar-brand" href="#">
        <img src="../logoplantulas.png" alt="Logo" width="130" height="124">
      </a>
      <div>
        <h2>Historial de Homogenizaciones</h2>
        <p>Consulta las diluciones y tuppers llenados según el medio nutritivo utilizado.</p>
      </div>
    </div>
    <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_rpmc.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
  </header>

  <main class="container py-4">
    <h4 class="mb-4 text-center">📋 Registro de Diluciones y Tuppers Llenados</h4>

    <!-- Leyenda de colores -->
    <div class="mb-4">
      <strong>Leyenda de Medios Nutritivos:</strong>
      <div class="d-flex flex-wrap gap-3 mt-2">
        <?php foreach ($colorMap as $codigo => $colorNum): ?>
          <div class="d-flex align-items-center gap-2">
            <div style="width: 20px; height: 20px; border-radius: 4px;" class="color-medio-<?= $colorNum ?>"></div>
            <span><?= htmlspecialchars($codigo) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Tabla -->
    <div class="table-responsive">
      <table class="table table-bordered table-striped" id="tabla-historial">
        <thead>
          <tr>
            <th>ID Dilución</th>
            <th>Código del Medio</th>
            <th>Fecha</th>
            <th>Litros Usados</th>
            <th>Volumen Final</th>
            <th>Tuppers Llenados</th>
            <th>Cantidad Original</th>
            <th>Cantidad Disponible</th>
            <th>Responsable</th>
          </tr>
        </thead>
<tbody>
  <?php foreach ($historial as $row): ?>
  <tr>
    <td class="<?= $row['color_class'] ?>" data-label="ID Dilución"><?= $row['ID_Dilucion'] ?></td>
    <td class="<?= $row['color_class'] ?>" data-label="Código del Medio"><?= htmlspecialchars($row['Codigo_Medio']) ?></td>
    <td class="<?= $row['color_class'] ?>" data-label="Fecha"><?= $row['Fecha_Preparacion'] ?></td>
    <td class="<?= $row['color_class'] ?>" data-label="Litros Usados"><?= number_format($row['Cantidad_MedioMadre'], 2) ?> L</td>
    <td class="<?= $row['color_class'] ?>" data-label="Volumen Final"><?= number_format($row['Volumen_Final'], 2) ?> L</td>
    <td class="<?= $row['color_class'] ?>" data-label="Tuppers Llenados"><?= $row['Tuppers_Llenos'] ?></td>
    <td class="<?= $row['color_class'] ?>" data-label="Cantidad Original"><?= number_format($row['Cantidad_Original'], 2) ?> L</td>
    <td class="<?= $row['color_class'] ?>" data-label="Cantidad Disponible"><?= number_format($row['Cantidad_Disponible'], 2) ?> L</td>
    <td class="<?= $row['color_class'] ?>" data-label="Responsable"><?= htmlspecialchars($row['Nombre']) ?></td>
  </tr>
  <?php endforeach; ?>
</tbody>

      </table>
    </div>
  </main>

  <footer class="text-center p-3">
    &copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.
  </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Modal de advertencia de sesión + Ping por interacción que reinicia timers -->
<script>
(function(){
  let modalShown = false,
      warningTimer,
      expireTimer;

  function showModal() {
    modalShown = true;
    const modalHtml = `
      <div id="session-warning" class="modal-overlay">
        <div class="modal-box">
          <p>Tu sesión va a expirar pronto. ¿Deseas mantenerla activa?</p>
          <button id="keepalive-btn" class="btn-keepalive">Seguir activo</button>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    document.getElementById('keepalive-btn').addEventListener('click', () => {
      cerrarModalYReiniciar(); // 🔥 Aquí aplicamos el cambio
    });
  }

  function cerrarModalYReiniciar() {
    // 🔥 Cerrar modal inmediatamente
    const modal = document.getElementById('session-warning');
    if (modal) modal.remove();
    reiniciarTimers(); // Reinicia el temporizador visual

    // 🔄 Enviar ping a la base de datos en segundo plano
    fetch('../keepalive.php', { credentials: 'same-origin' })
      .then(res => res.json())
      .then(data => {
        if (data.status !== 'OK') {
          alert('No se pudo extender la sesión');
        }
      })
      .catch(() => {}); // Silenciar errores de red
  }

  function reiniciarTimers() {
    START_TS   = Date.now();
    modalShown = false;
    clearTimeout(warningTimer);
    clearTimeout(expireTimer);
    scheduleTimers();
  }

  function scheduleTimers() {
    const elapsed     = Date.now() - START_TS;
    const warnAfter   = SESSION_LIFETIME - WARNING_OFFSET;
    const expireAfter = SESSION_LIFETIME;

    warningTimer = setTimeout(showModal, Math.max(warnAfter - elapsed, 0));

    expireTimer = setTimeout(() => {
      if (!modalShown) {
        showModal();
      } else {
        window.location.href = '/plantulas/login.php?mensaje='
          + encodeURIComponent('Sesión caducada por inactividad');
      }
    }, Math.max(expireAfter - elapsed, 0));
  }

  ['click', 'keydown'].forEach(event => {
    document.addEventListener(event, () => {
      reiniciarTimers();
      fetch('../keepalive.php', { credentials: 'same-origin' }).catch(() => {});
    });
  });

  scheduleTimers();
})();
</script>

</body>
</html>
