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

if ((int) $_SESSION['Rol'] !== 10) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Encargado de Organización y Limpieza de Incubadora.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// 3) Capturar filtros
$filter_fecha  = $_GET['fecha']  ?? '';
$filter_area   = $_GET['area']   ?? '';
$filter_estado = $_GET['estado'] ?? '';

// 4) Construir consulta con WHERE dinámico
$where = [];
if ($filter_fecha)  $where[] = "LR.Fecha = '" . $conn->real_escape_string($filter_fecha) . "'";
if ($filter_area)   $where[] = "LR.Area  = '" . $conn->real_escape_string($filter_area)   . "'";
if ($filter_estado) $where[] = "LR.Estado_Limpieza = '" . $conn->real_escape_string($filter_estado) . "'";

$sql = "
  SELECT
    LR.ID_Limpieza         AS id,
    CONCAT(O.Nombre,' ',O.Apellido_P,' ',O.Apellido_M) AS operador,
    LR.Fecha               AS fecha,
    TIME(LR.Hora_Registro) AS hora,
    LR.Area                AS area,
    LR.Estado_Limpieza     AS estado
  FROM registro_limpieza LR
  JOIN operadores O ON LR.ID_Operador = O.ID_Operador
";
if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY LR.Fecha DESC, LR.Hora_Registro DESC";

$result = $conn->query($sql);
if (!$result) {
    die("Error en la consulta: " . $conn->error);
}

// 5) Datos para selects dinámicos
$areasResult   = $conn->query("SELECT DISTINCT Area FROM registro_limpieza ORDER BY Area");
$estadosResult = $conn->query("SELECT DISTINCT Estado_Limpieza FROM registro_limpieza ORDER BY Estado_Limpieza");
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Limpieza de Repisas</title>
  <link rel="stylesheet" href="../style.css?v=<?= time(); ?>"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>
  <div class="contenedor-pagina">
  <header>
  <div class="encabezado d-flex align-items-center">
    <a class="navbar-brand me-3" href="dashboard_eol.php">
      <img src="../logoplantulas.png" width="130" height="124" alt="Logo">
    </a>
    <div>
      <h2>Historial de Limpieza de Repisas</h2>
      <p class="mb-0">Revisa qué repisas y áreas se han limpiado.</p>
    </div>
  </div>

  <div class="barra-navegacion">
    <nav class="navbar bg-body-tertiary">
      <div class="container-fluid">
        <div class="Opciones-barra">
          <button onclick="window.location.href='dashboard_eol.php'">
            🏠 Volver al Inicio
          </button>
        </div>
      </div>
    </nav>
  </div>

  <nav class="filter-toolbar d-flex flex-wrap align-items-center gap-2 px-3 py-2" style="overflow-x:auto;">
    <div class="d-flex flex-column" style="min-width:120px;">
      <label for="filtro-fecha" class="small mb-1">Fecha</label>
      <input id="filtro-fecha" type="date" name="fecha" form="filtrosForm"
             class="form-control form-control-sm"
             value="<?= htmlspecialchars($filter_fecha) ?>">
    </div>

    <div class="d-flex flex-column" style="min-width:140px;">
      <label for="filtro-area" class="small mb-1">Área</label>
      <select id="filtro-area" name="area" form="filtrosForm"
              class="form-select form-select-sm">
        <option value="">— Todas Áreas —</option>
        <?php while($a = $areasResult->fetch_assoc()): ?>
          <option value="<?= htmlspecialchars($a['Area'])?>"
            <?= $filter_area === $a['Area'] ? 'selected':''?>>
            <?= htmlspecialchars($a['Area']) ?>
          </option>
        <?php endwhile; ?>
      </select>
    </div>

    <div class="d-flex flex-column" style="min-width:140px;">
      <label for="filtro-estado" class="small mb-1">Estado</label>
      <select id="filtro-estado" name="estado" form="filtrosForm"
              class="form-select form-select-sm">
        <option value="">— Todos Estados —</option>
        <?php while($e = $estadosResult->fetch_assoc()): ?>
          <option value="<?= htmlspecialchars($e['Estado_Limpieza'])?>"
            <?= $filter_estado === $e['Estado_Limpieza'] ? 'selected':''?>>
            <?= htmlspecialchars($e['Estado_Limpieza']) ?>
          </option>
        <?php endwhile; ?>
      </select>
    </div>

    <button form="filtrosForm" type="submit"
            class="btn-inicio btn btn-success btn-sm ms-auto">
      Filtrar
    </button>
  </nav>
</header>


    <!-- Formulario oculto para filtros -->
    <form id="filtrosForm" method="GET" class="d-none"></form>

    <main class="container mt-4">
      <div class="table-responsive mb-4">
        <table class="table table-striped table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th>Operador</th>
              <th>Fecha</th>
              <th>Hora</th>
              <th>Área</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
              <td data-label="ID"><?= $row['id'] ?></td>
              <td data-label="Operador"><?= htmlspecialchars($row['operador']) ?></td>
              <td data-label="Fecha"><?= htmlspecialchars($row['fecha']) ?></td>
              <td data-label="Hora"><?= htmlspecialchars($row['hora']) ?></td>
              <td data-label="Área"><?= htmlspecialchars($row['area']) ?></td>
              <td data-label="Estado"><?= htmlspecialchars($row['estado']) ?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </main>

    <footer class="text-center py-3">&copy; 2025 PLANTAS AGRODEX</footer>
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
