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

if ((int) $_SESSION['Rol'] !== 5) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Encargado General de Producción.</p>";
    exit;
}

// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// Parámetros de búsqueda
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';
$fecha_exacta = $_GET['fecha_exacta'] ?? '';
$busqueda_variedad = $_GET['busqueda_variedad'] ?? '';

// Consulta base
$sql = "SELECT D.ID_Desinfeccion, D.ID_Variedad, D.Origen_Explantes, D.Explantes_Iniciales,
               D.Explantes_Desinfectados, D.FechaHr_Desinfeccion, D.HrFn_Desinfeccion, 
               D.Estado_Desinfeccion, O.Nombre, O.Apellido_P, O.Apellido_M,
               V.Codigo_Variedad, V.Nombre_Variedad
        FROM desinfeccion_explantes D
        LEFT JOIN operadores O ON D.Operador_Responsable = O.ID_Operador
        LEFT JOIN variedades V ON D.ID_Variedad = V.ID_Variedad
        WHERE 1";

// Filtros dinámicos
$params = [];
$types = "";

if (!empty($fecha_desde)) {
    $sql .= " AND D.FechaHr_Desinfeccion >= ?";
    $params[] = $fecha_desde . " 00:00:00";
    $types .= "s";
}
if (!empty($fecha_hasta)) {
    $sql .= " AND D.FechaHr_Desinfeccion <= ?";
    $params[] = $fecha_hasta . " 23:59:59";
    $types .= "s";
}
if (!empty($fecha_exacta)) {
    $sql .= " AND DATE(D.FechaHr_Desinfeccion) = ?";
    $params[] = $fecha_exacta;
    $types .= "s";
}
if (!empty($busqueda_variedad)) {
    $sql .= " AND (V.Codigo_Variedad LIKE ? OR V.Nombre_Variedad LIKE ?)";
    $term = '%' . $busqueda_variedad . '%';
    $params[] = $term;
    $params[] = $term;
    $types .= "ss";
}

$sql .= " ORDER BY D.FechaHr_Desinfeccion DESC";
$stmt = $conn->prepare($sql);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Historial de Desinfección</title>
  <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
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
      <a class="navbar-brand"><img src="../logoplantulas.png" alt="Logo" width="130" height="124" /></a>
      <h2>Historial de Desinfección de Explantes</h2>
      <div></div>
    </div>

    <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_egp.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>

      <!-- Filtros -->
<nav class="filter-toolbar d-flex flex-wrap align-items-center gap-2 px-3 py-2" style="overflow-x:auto;">
  <div class="d-flex flex-column" style="min-width:120px;">
    <label for="filtro-fecha-desde" class="small mb-1">Desde</label>
    <input id="filtro-fecha-desde" type="date" class="form-control form-control-sm"
           value="<?= htmlspecialchars($fecha_desde) ?>">
  </div>

  <div class="d-flex flex-column" style="min-width:120px;">
    <label for="filtro-fecha-hasta" class="small mb-1">Hasta</label>
    <input id="filtro-fecha-hasta" type="date" class="form-control form-control-sm"
           value="<?= htmlspecialchars($fecha_hasta) ?>">
  </div>

  <div class="d-flex flex-column" style="min-width:120px;">
    <label for="fecha_exacta" class="small mb-1">Fecha Exacta</label>
    <input id="fecha_exacta" type="date" class="form-control form-control-sm"
           value="<?= htmlspecialchars($_GET['fecha_exacta'] ?? '') ?>">
  </div>

  <div class="d-flex flex-column" style="min-width:140px;">
    <label for="filtro-variedad" class="small mb-1">Variedad</label>
    <input id="filtro-variedad" type="text" class="form-control form-control-sm"
           placeholder="Nombre o Código"
           value="<?= htmlspecialchars($busqueda_variedad) ?>">
  </div>

  <button onclick="aplicarFiltros()" class="btn-inicio btn btn-success btn-sm ms-auto">
    Filtrar
  </button>
  <button onclick="limpiarFiltros()" type="button" class="btn btn-limpiar btn-sm ms-2">
  Limpiar filtros
</button>
</nav>
  </header>

  <main>
    <!-- Tabla -->
    <div class="table-responsive">
      <table class="table table-bordered">
        <thead>
          <tr>
            <th>ID</th>
            <th>Código Variedad</th>
            <th>Nombre Variedad</th>
            <th>Origen de los Explantes</th>
            <th>Cantidad de Explantes Iniciales</th>
            <th>Cantidad de Explantes Desinfectados</th>
            <th>Inicio</th>
            <th>Fin</th>
            <th>Estado</th>
            <th>Responsable</th>
          </tr>
        </thead>

        <tbody>
        <?php if ($result->num_rows > 0): ?>
          <?php while ($row = $result->fetch_assoc()) { ?>
            <tr>
              <td data-label="ID"><?= $row['ID_Desinfeccion'] ?></td>
              <td data-label="Código Variedad"><?= $row['Codigo_Variedad'] ?? '-' ?></td>
              <td data-label="Nombre Variedad"><?= $row['Nombre_Variedad'] ?? '-' ?></td>
              <td data-label="Origen"><?= htmlspecialchars($row['Origen_Explantes'] ?? '-') ?></td>
              <td data-label="Explantes Iniciales"><?= $row['Explantes_Iniciales'] ?></td>
              <td data-label="Explantes Desinfectados"><?= $row['Explantes_Desinfectados'] ?? '-' ?></td>
              <td data-label="Inicio"><?= $row['FechaHr_Desinfeccion'] ?></td>
              <td data-label="Fin"><?= $row['HrFn_Desinfeccion'] ?? '-' ?></td>
              <td data-label="Estado"><?= $row['Estado_Desinfeccion'] ?></td>
              <td data-label="Responsable"><?= $row['Nombre'] . ' ' . $row['Apellido_P'] . ' ' . $row['Apellido_M'] ?></td>
            </tr>
            <?php } ?>
            <?php else: ?>
              <tr>
              <td colspan="9" class="text-center text-muted py-3">
              No se encontraron registros con los filtros seleccionados.
            </td>
          </tr>
          <?php endif; ?>
        </tbody>

      </table>
    </div>
  </main>

  <footer>
    <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
  </footer>
</div>

<script>
function aplicarFiltros() {
  const desde = document.getElementById('filtro-fecha-desde').value;
  const hasta = document.getElementById('filtro-fecha-hasta').value;
  const exacta = document.getElementById('fecha_exacta').value;
  const variedad = document.getElementById('filtro-variedad').value;

  const params = new URLSearchParams();

  if (desde) params.append('fecha_desde', desde);
  if (hasta) params.append('fecha_hasta', hasta);
  if (exacta) params.append('fecha_exacta', exacta);
  if (variedad) params.append('busqueda_variedad', variedad);

  window.location.href = 'historial_desinfeccion_explantes.php?' + params.toString();
}
</script>

<script>
function limpiarFiltros() {
  // Recarga sin parámetros
  window.location.href = 'historial_desinfeccion_explantes.php';
}
</script>

<script>
function aplicarRestriccionFechas() {
  const desde  = document.getElementById('filtro-fecha-desde');
  const hasta  = document.getElementById('filtro-fecha-hasta');
  const exacta = document.getElementById('fecha_exacta');

  // Si se llena fecha exacta, deshabilitar desde/hasta
  if (exacta.value) {
    desde.disabled = true;
    hasta.disabled = true;
    desde.classList.add('disabled-date');
    hasta.classList.add('disabled-date');
  } else {
    desde.disabled = false;
    hasta.disabled = false;
    desde.classList.remove('disabled-date');
    hasta.classList.remove('disabled-date');
  }

  // Si se llena desde o hasta, deshabilitar fecha exacta
  if (desde.value || hasta.value) {
    exacta.disabled = true;
    exacta.classList.add('disabled-date');
  } else if (!exacta.value) {
    exacta.disabled = false;
    exacta.classList.remove('disabled-date');
  }
}

// Detectar cambios en todos los campos
['filtro-fecha-desde', 'filtro-fecha-hasta', 'fecha_exacta'].forEach(id => {
  const campo = document.getElementById(id);
  campo.addEventListener('input', aplicarRestriccionFechas);
});

// Ejecutar al cargar (por si vienen prellenados)
window.addEventListener('DOMContentLoaded', aplicarRestriccionFechas);
</script>

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
