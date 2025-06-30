<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Validar sesión y rol
require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

date_default_timezone_set('America/Mexico_City');
$conn->query("SET time_zone = '-06:00'");

if (!isset($_SESSION['ID_Operador'])) {
    header('Location: ../login.php?mensaje=Debe iniciar sesión');
    exit;
}
$ID_Operador = (int) $_SESSION['ID_Operador'];

if ((int) $_SESSION['Rol'] !== 2) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Solo Operador.</p>";
    exit;
}

// 2) Variables para el modal de sesión
$sessionLifetime = 60 * 3;
$warningOffset   = 60 * 1;
$nowTs           = time();

// 3) Consulta de juegos asignados al operador hoy
$stmt = $conn->prepare("
  SELECT 
    ajo.fecha_asignacion,
    CONCAT(asg.Nombre,' ',asg.Apellido_P,' ',asg.Apellido_M) AS quien_asigna,
    COUNT(*) AS juegos_asignados
  FROM asignacion_juego_operadora ajo
  JOIN operadores asg ON ajo.id_operador_asigna = asg.ID_Operador
  WHERE ajo.id_operador_asignado = ?
    AND DATE(ajo.fecha_asignacion) = CURDATE()
  GROUP BY ajo.fecha_asignacion, ajo.id_operador_asigna
  ORDER BY ajo.fecha_asignacion DESC
");
$stmt->bind_param("i", $ID_Operador);
$stmt->execute();
$resultado = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mis Juegos Asignados</title>
  <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
      <h2>📦 Juegos Asignados</h2>
    </div>
    <div class="barra-navegacion">
      <nav class="navbar bg-body-tertiary">
        <div class="container-fluid">
          <div class="Opciones-barra">
            <button onclick="window.location.href='dashboard_cultivo.php'">🏠 Volver al Inicio</button>
          </div>
        </div>
      </nav>
    </div>
  </header>

  <main class="container mt-4">
    <h3 class="mb-4 text-center">📋 Juegos asignados para hoy: <?= date('d-m-Y') ?></h3>

    <?php if ($resultado->num_rows > 0): ?>
    <div class="table-responsive">
      <table class="table table-striped table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th>Fecha de Asignación</th>
            <th>Asignado por</th>
            <th>Cantidad de Juegos</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($juego = $resultado->fetch_assoc()): ?>
          <tr class="align-middle text-nowrap">
            <td><?= htmlspecialchars($juego['fecha_asignacion']) ?></td>
            <td><?= htmlspecialchars($juego['quien_asigna']) ?></td>
            <td><?= (int)$juego['juegos_asignados'] ?></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="alert alert-warning text-center">
      <strong>🔔 No tienes juegos asignados para hoy.</strong>
    </div>
    <?php endif; ?>
  </main>

  <footer>
    <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
  </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Modal de sesión -->
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
      cerrarModalYReiniciar();
    });
  }

  function cerrarModalYReiniciar() {
    const modal = document.getElementById('session-warning');
    if (modal) modal.remove();
    reiniciarTimers();
    fetch('../keepalive.php', { credentials: 'same-origin' }).catch(() => {});
  }

  function reiniciarTimers() {
    START_TS = Date.now();
    modalShown = false;
    clearTimeout(warningTimer);
    clearTimeout(expireTimer);
    scheduleTimers();
  }

  function scheduleTimers() {
    const elapsed = Date.now() - START_TS;
    warningTimer = setTimeout(showModal, Math.max(SESSION_LIFETIME - WARNING_OFFSET - elapsed, 0));
    expireTimer = setTimeout(() => {
      if (!modalShown) showModal();
      else window.location.href = '/plantulas/login.php?mensaje=' + encodeURIComponent('Sesión caducada por inactividad');
    }, Math.max(SESSION_LIFETIME - elapsed, 0));
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
