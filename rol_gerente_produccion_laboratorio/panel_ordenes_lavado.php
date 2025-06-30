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

if ((int) $_SESSION['Rol'] !== 6) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Gerente de Producción de Laboratorio.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// Captura de filtros desde el formulario
$fechaDesde = $_GET['fecha_desde'] ?? '';
$fechaHasta = $_GET['fecha_hasta'] ?? '';
$estado     = $_GET['estado'] ?? '';


$sql = "
  SELECT ol.ID_Orden, v.Nombre_Variedad, v.Especie, ol.Fecha_Lavado, ol.Cantidad_Lavada, ol.Estado
  FROM orden_tuppers_lavado ol
  INNER JOIN lotes l ON ol.ID_Lote = l.ID_Lote
  INNER JOIN variedades v ON l.ID_Variedad = v.ID_Variedad
  WHERE 1=1
";

$params = [];
$types  = '';

if (!empty($fechaDesde)) {
  $sql .= " AND ol.Fecha_Lavado >= ?";
  $params[] = $fechaDesde;
  $types   .= 's';
}

if (!empty($fechaHasta)) {
  $sql .= " AND ol.Fecha_Lavado <= ?";
  $params[] = $fechaHasta;
  $types   .= 's';
}

if (!empty($estado)) {
  $sql .= " AND ol.Estado = ?";
  $params[] = $estado;
  $types   .= 's';
}

$sql .= " ORDER BY ol.Fecha_Creacion DESC";

$stmt = $conn->prepare($sql);
if ($params) {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$ordenes_panel = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Panel de Clasificación de planta</title>
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
        <div>
          <h2>📦 Panel de Clasificación de planta</h2>
          <p>Consulta y administra las órdenes enviadas a clasificación de plantas.</p>
        </div>
      </div>

      <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_gpl.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>

<nav class="filter-toolbar d-flex flex-wrap align-items-center gap-2 px-3 py-2 mb-3" style="overflow-x:auto;">
  <div class="d-flex flex-column" style="min-width:120px;">
    <label for="filtro-desde" class="small mb-1">Desde</label>
    <input id="filtro-desde" type="date" name="fecha_desde" form="filtrosForm"
           class="form-control form-control-sm"
           value="<?= htmlspecialchars($_GET['fecha_desde'] ?? '') ?>">
  </div>

  <div class="d-flex flex-column" style="min-width:120px;">
    <label for="filtro-hasta" class="small mb-1">Hasta</label>
    <input id="filtro-hasta" type="date" name="fecha_hasta" form="filtrosForm"
           class="form-control form-control-sm"
           value="<?= htmlspecialchars($_GET['fecha_hasta'] ?? '') ?>">
  </div>

  <div class="d-flex flex-column" style="min-width:140px;">
    <label for="filtro-estado" class="small mb-1">Estado</label>
    <select id="filtro-estado" name="estado" form="filtrosForm"
            class="form-select form-select-sm">
      <option value="">— Todos —</option>
      <option value="Pendiente"      <?= ($_GET['estado'] ?? '')==='Pendiente'      ? 'selected':'' ?>>Pendiente</option>
      <option value="Asignado"       <?= ($_GET['estado'] ?? '')==='Asignado'       ? 'selected':'' ?>>Asignado</option>
      <option value="Completado"     <?= ($_GET['estado'] ?? '')==='Completado'     ? 'selected':'' ?>>Completado</option>
      <option value="Caja Preparada" <?= ($_GET['estado'] ?? '')==='Caja Preparada' ? 'selected':'' ?>>Caja Preparada</option>
      <option value="En Lavado"      <?= ($_GET['estado'] ?? '')==='En Lavado'      ? 'selected':'' ?>>En Lavado</option>
    </select>
  </div>

  <button form="filtrosForm" type="submit"
          class="btn-inicio btn btn-success btn-sm ms-auto">
    Filtrar
  </button>
  <button type="button" onclick="limpiarFiltros()" class="btn btn-outline-secondary btn-sm ms-2">
  Limpiar filtros
</button>
</nav>
<form id="filtrosForm" method="GET" class="d-none"></form>
    </header>

    <main class="container mt-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-bordered table-hover text-center align-middle text-nowrap">
              <thead class="table-light">
                <tr>
                  <th>ID Orden</th>
                  <th>Variedad</th>
                  <th>Especie</th>
                  <th>Fecha de clasificación</th>
                  <th>Cantidad de Tuppers</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($ordenes_panel->num_rows > 0): ?>
                  <?php while ($orden = $ordenes_panel->fetch_assoc()): ?>
                    <tr>
<td data-label="ID Orden"><?= $orden['ID_Orden'] ?></td>
<td data-label="Variedad"><?= $orden['Nombre_Variedad'] ?></td>
<td data-label="Especie"><?= $orden['Especie'] ?></td>
<td data-label="Fecha de clasificación"><?= $orden['Fecha_Lavado'] ?></td>
<td data-label="Cantidad de Tuppers"><?= $orden['Cantidad_Lavada'] ?></td>
<td data-label="Estado">
                        <?php if ($orden['Estado'] == 'Pendiente'): ?>
                          <span class="badge bg-warning text-dark">Pendiente</span>
                        <?php elseif ($orden['Estado'] == 'Asignado'): ?>
                          <span class="badge bg-info text-dark">Asignado</span>
                        <?php elseif ($orden['Estado'] == 'Completado'): ?>
                          <span class="badge bg-success">Completado</span>
                        <?php elseif ($orden['Estado'] == 'Caja Preparada'): ?>
                          <span class="badge bg-success">Caja Preparada</span>
                        <?php elseif ($orden['Estado'] == 'En Lavado'): ?>
                          <span class="badge bg-success">En Clasificación</span>
                        <?php else: ?>
                          <span class="badge bg-secondary">Otro</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="6">No hay órdenes registradas.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </main>

    <footer>
      <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
    </footer>
  </div>

  <script>
function limpiarFiltros() {
  window.location.href = 'panel_ordenes_lavado.php';
}
</script>

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
