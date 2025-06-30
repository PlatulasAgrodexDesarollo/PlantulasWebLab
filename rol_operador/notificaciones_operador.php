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

if ((int) $_SESSION['Rol'] !== 2) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Solo Operador.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// Consulta para reportes rechazados de Multiplicación
$sql_multiplicacion = "SELECT M.ID_Multiplicacion, V.Codigo_Variedad, V.Nombre_Variedad, M.Fecha_Siembra, M.Observaciones_Revision
    FROM multiplicacion M
    LEFT JOIN variedades V ON M.ID_Variedad = V.ID_Variedad
    WHERE M.Operador_Responsable = ? AND M.Estado_Revision = 'Rechazado'";
$stmt_m = $conn->prepare($sql_multiplicacion);
$stmt_m->bind_param("i", $ID_Operador);
$stmt_m->execute();
$result_m = $stmt_m->get_result();

// Consulta para reportes rechazados de Enraizamiento
$sql_enraizamiento = "SELECT E.ID_Enraizamiento, V.Codigo_Variedad, V.Nombre_Variedad, E.Fecha_Siembra, E.Observaciones_Revision
    FROM enraizamiento E
    LEFT JOIN variedades V ON E.ID_Variedad = V.ID_Variedad
    WHERE E.Operador_Responsable = ? AND E.Estado_Revision = 'Rechazado'";
$stmt_e = $conn->prepare($sql_enraizamiento);
$stmt_e->bind_param("i", $ID_Operador);
$stmt_e->execute();
$result_e = $stmt_e->get_result();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Correcciones de reportes - Operador</title>
  <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
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
      <h2>Correcciónes de reportes</h2>
    </div>

    <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_cultivo.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
  </header>
<main class="container mt-4">
      <h3>Tienes correcciones pendientes en reportes de siembra</h3>
      <?php if($result_m->num_rows == 0 && $result_e->num_rows == 0): ?>
        <div class="alert alert-info">No tienes notificaciones de rechazo.</div>
      <?php else: ?>
<div class="table-responsive">
  <table class="table tabla-asignaciones table-bordered table-hover table-striped">
    <thead>
      <tr>
        <th class="text-center align-middle p-1">Tipo</th>
        <th class="text-center align-middle p-1">Variedad</th>
        <th class="text-center align-middle p-1">Fecha de Siembra</th>
        <th class="text-center align-middle p-1">Observaciones</th>
        <th class="text-center align-middle p-1">Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php while($row = $result_m->fetch_assoc()): ?>
<tr class="text-center align-middle">
  <td data-label="Tipo">Multiplicación</td>
  <td class="text-center align-middle" data-label="Variedad"><?= $row['Codigo_Variedad'] . " - " . $row['Nombre_Variedad'] ?></td>
  <td class="text-center align-middle" data-label="Fecha de Siembra"><?= $row['Fecha_Siembra'] ?></td>
  <td class="text-break small text-center align-middle" data-label="Observaciones"><?= htmlspecialchars($row['Observaciones_Revision']) ?></td>
  <td class="text-center align-middle" data-label="Acciones">
    <a href="corregir_reporte.php?tipo=multiplicacion&id=<?= $row['ID_Multiplicacion'] ?>" class="btn btn-warning btn-sm w-100">Corregir</a>
  </td>
</tr>
      <?php endwhile; ?>
      <?php while($row = $result_e->fetch_assoc()): ?>
<tr class="text-center align-middle">
  <td data-label="Tipo">Enraizamiento</td>
  <td class="text-center align-middle" data-label="Variedad"><?= $row['Codigo_Variedad'] . " - " . $row['Nombre_Variedad'] ?></td>
  <td class="text-center align-middle" data-label="Fecha de Siembra"><?= $row['Fecha_Siembra'] ?></td>
  <td class="text-break small text-center align-middle" data-label="Observaciones"><?= htmlspecialchars($row['Observaciones_Revision']) ?></td>
  <td class="text-center align-middle" data-label="Acciones">
    <a href="corregir_reporte.php?tipo=enraizamiento&id=<?= $row['ID_Enraizamiento'] ?>" class="btn btn-warning btn-sm w-100">Corregir</a>
  </td>
</tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>
      <?php endif; ?>
    </div>
  </main>
  <footer class="mt-4">
    <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
  </footer>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    
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
