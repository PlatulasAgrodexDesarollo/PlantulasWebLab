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

if ((int) $_SESSION['Rol'] !== 4) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Supervisora de Incubadora.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// 1) Capturar filtros
$filter_estado      = $_GET['estado']      ?? '';
$filter_etapa       = $_GET['etapa']       ?? '';
$filter_variedad    = $_GET['variedad']    ?? '';
$filter_responsable = $_GET['responsable'] ?? '';
$filter_fecha       = $_GET['fecha']       ?? '';

// 2) Consulta base
$baseSQL = "
    SELECT 
        l.ID_Lote,
        CONCAT(v.Codigo_Variedad, ' – ', v.Nombre_Variedad) AS Variedad,
        v.Color,
        l.Fecha AS Fecha_Ingreso,
        CASE 
          WHEN l.ID_Etapa = 1 THEN COALESCE(s.Tuppers_Llenos,0) + COALESCE(d.Tuppers_Llenos,0)
          WHEN l.ID_Etapa = 2 THEN COALESCE(m.Tuppers_Llenos,0)
          WHEN l.ID_Etapa = 3 THEN COALESCE(e.Tuppers_Llenos,0)
          ELSE 0
        END AS Tuppers_Existentes,
        CASE l.ID_Etapa
          WHEN 1 THEN 'ECAS'
          WHEN 2 THEN 'Multiplicación'
          WHEN 3 THEN 'Enraizamiento'
        END AS Etapa,
        CASE 
          WHEN l.ID_Etapa=1 AND s.ID_Siembra IS NOT NULL THEN 'Siembra'
          WHEN l.ID_Etapa=1 AND d.ID_Division IS NOT NULL THEN 'División'
          ELSE NULL
        END AS Subetapa_ECAS,
        CONCAT(o.Nombre,' ',o.Apellido_P,' ',o.Apellido_M) AS Responsable,
        CASE 
          WHEN l.ID_Etapa=2 THEN COALESCE(m.Estado_Revision,'S/D')
          WHEN l.ID_Etapa=3 THEN COALESCE(e.Estado_Revision,'S/D')
          ELSE 'S/D'
        END AS Estado_Tupper
    FROM lotes l
    LEFT JOIN variedades v    ON l.ID_Variedad = v.ID_Variedad
    LEFT JOIN operadores o    ON l.ID_Operador  = o.ID_Operador
    LEFT JOIN siembra_ecas s  ON l.ID_Lote      = s.ID_Lote
    LEFT JOIN division_ecas d ON s.ID_Siembra   = d.ID_Siembra
    LEFT JOIN multiplicacion m ON l.ID_Lote     = m.ID_Lote
    LEFT JOIN enraizamiento e  ON l.ID_Lote     = e.ID_Lote
";

// 3) Aplicar filtros sobre alias (sub‐SELECT)
$where = [];
if ($filter_estado)      $where[] = "Estado_Tupper    = '" . $conn->real_escape_string($filter_estado) . "'";
if ($filter_etapa)       $where[] = "Etapa            = '" . $conn->real_escape_string($filter_etapa) . "'";
if ($filter_variedad)    $where[] = "Variedad LIKE   '%" . $conn->real_escape_string($filter_variedad) . "%'";
if ($filter_responsable) $where[] = "Responsable LIKE '%" . $conn->real_escape_string($filter_responsable) . "%'";
if ($filter_fecha)       $where[] = "Fecha_Ingreso   = '" . $conn->real_escape_string($filter_fecha) . "'";

$sql = "SELECT * FROM ( $baseSQL ) AS t";
if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY Fecha_Ingreso DESC";

$resultado = $conn->query($sql);

// 4) Opciones dinámicas para filtros
$estadosResult      = $conn->query("SELECT DISTINCT Estado_Tupper    FROM ( $baseSQL ) AS t");
$variedadesResult   = $conn->query("SELECT DISTINCT Variedad         FROM ( $baseSQL ) AS t ORDER BY Variedad");
$responsablesResult = $conn->query("SELECT DISTINCT Responsable      FROM ( $baseSQL ) AS t ORDER BY Responsable");
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vista General de Tuppers</title>
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
    <div class="encabezado d-flex align-items-center">
      <a class="navbar-brand me-3" href="#">
        <img src="../logoplantulas.png" width="130" height="124" alt="Logo">
      </a>
      <h2>📋 Vista General de Tuppers</h2>
    </div>
    <div class="barra-navegacion">

      <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_supervisora.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>

      <!-- Nav de Filtros compactos -->
<nav class="filter-toolbar d-flex flex-wrap align-items-center gap-2 px-3 py-2" style="overflow-x:auto;">
  <div class="d-flex flex-column" style="min-width:120px;">
    <label for="filtro-estado" class="small mb-1">Estado</label>
    <select id="filtro-estado" name="estado" form="filtrosForm" class="form-select form-select-sm">
      <option value="">— Todos Estados —</option>
      <?php while($e = $estadosResult->fetch_assoc()): ?>
        <option value="<?= $e['Estado_Tupper'] ?>" <?= $filter_estado === $e['Estado_Tupper'] ? 'selected':''?>>
          <?= htmlspecialchars($e['Estado_Tupper']) ?>
        </option>
      <?php endwhile; ?>
    </select>
  </div>

  <div class="d-flex flex-column" style="min-width:120px;">
    <label for="filtro-etapa" class="small mb-1">Etapa</label>
    <select id="filtro-etapa" name="etapa" form="filtrosForm" class="form-select form-select-sm">
      <option value="">— Todas Etapas —</option>
      <option value="ECAS"           <?= $filter_etapa==='ECAS'           ? 'selected':'' ?>>ECAS</option>
      <option value="Multiplicación" <?= $filter_etapa==='Multiplicación' ? 'selected':'' ?>>Multiplicación</option>
      <option value="Enraizamiento"  <?= $filter_etapa==='Enraizamiento'  ? 'selected':'' ?>>Enraizamiento</option>
    </select>
  </div>

  <div class="d-flex flex-column" style="min-width:160px;">
    <label for="filtro-variedad" class="small mb-1">Variedad</label>
    <select id="filtro-variedad" name="variedad" form="filtrosForm" class="form-select form-select-sm">
      <option value="">— Todas Variedades —</option>
      <?php while($v = $variedadesResult->fetch_assoc()): ?>
        <option value="<?= htmlspecialchars($v['Variedad'])?>" <?= $filter_variedad === $v['Variedad'] ? 'selected':''?>>
          <?= htmlspecialchars($v['Variedad']) ?>
        </option>
      <?php endwhile; ?>
    </select>
  </div>

  <div class="d-flex flex-column" style="min-width:160px;">
    <label for="filtro-responsable" class="small mb-1">Responsable</label>
    <select id="filtro-responsable" name="responsable" form="filtrosForm" class="form-select form-select-sm">
      <option value="">— Todos Responsables —</option>
      <?php while($o = $responsablesResult->fetch_assoc()): ?>
        <option value="<?= htmlspecialchars($o['Responsable'])?>" <?= $filter_responsable === $o['Responsable'] ? 'selected':''?>>
          <?= htmlspecialchars($o['Responsable']) ?>
        </option>
      <?php endwhile; ?>
    </select>
  </div>

  <div class="d-flex flex-column" style="min-width:130px;">
    <label for="filtro-fecha" class="small mb-1">Fecha</label>
    <input id="filtro-fecha" type="date" name="fecha" form="filtrosForm"
           class="form-control form-control-sm"
           value="<?= htmlspecialchars($filter_fecha) ?>">
  </div>

  <button form="filtrosForm" type="submit" class="btn-inicio btn btn-success btn-sm ms-auto">
    Filtrar
  </button>

  <a href="existencias_tuppers.php" class="btn btn-outline-secondary btn-sm">
    Limpiar filtros
  </a>
</nav>
    </div>
  </header>

  <!-- Formulario oculto para filtros -->
  <form id="filtrosForm" method="GET" class="d-none"></form>

  <main class="container-fluid mt-3">
    <div class="table-responsive">
      <table class="table text-center">
        <thead class="table-dark">
          <tr>
            <th>ID Lote</th>
            <th>Variedad</th>
            <th>Color</th>
            <th>Fecha Ingreso</th>
            <th>Cantidad Tuppers</th>
            <th>Etapa</th>
            <th>Subetapa ECAS</th>
            <th>Responsable</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($resultado->num_rows): ?>
            <?php while ($row = $resultado->fetch_assoc()):
              $clase = match($row['Etapa']) {
                'ECAS'           => ($row['Subetapa_ECAS']==='Siembra' ? 'subetapa-siembra' : ($row['Subetapa_ECAS']==='División' ? 'subetapa-division' : 'etapa-ecas')),
                'Multiplicación' => 'etapa-multiplicacion',
                'Enraizamiento'  => 'etapa-enraizamiento',
                default          => ''
              };
            ?>
<tr class="<?= $clase ?>">
  <td data-label="ID Lote"><?= $row['ID_Lote'] ?></td>
  <td data-label="Variedad"><?= htmlspecialchars($row['Variedad']) ?></td>
  <td data-label="Color"><?= htmlspecialchars($row['Color'] ?? 'N/A') ?></td>
  <td data-label="Fecha Ingreso"><?= htmlspecialchars($row['Fecha_Ingreso']) ?></td>
  <td data-label="Cantidad Tuppers"><?= $row['Tuppers_Existentes'] ?></td>
  <td data-label="Etapa"><?= htmlspecialchars($row['Etapa']) ?></td>
  <td data-label="Subetapa ECAS"><?= htmlspecialchars($row['Subetapa_ECAS'] ?? '-') ?></td>
  <td data-label="Responsable"><?= htmlspecialchars($row['Responsable']) ?></td>
  <td data-label="Estado"><?= htmlspecialchars($row['Estado_Tupper']) ?></td>
</tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="9">No se encontraron tuppers.</td></tr>
          <?php endif; ?>
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
