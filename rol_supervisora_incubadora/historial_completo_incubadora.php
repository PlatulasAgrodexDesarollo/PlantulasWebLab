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

// 2) Capturar filtros
$filter_fecha       = $_GET['fecha']        ?? '';
$filter_fecha_inicio = $_GET['fecha_inicio'] ?? '';
$filter_fecha_fin    = $_GET['fecha_fin']    ?? '';
$filter_tipo         = $_GET['tipo']         ?? 'all';

$where = [];

if ($filter_fecha) {
    $where[] = "DATE(r.fecha_hora_registro) = '" . $conn->real_escape_string($filter_fecha) . "'";
} elseif ($filter_fecha_inicio && $filter_fecha_fin) {
    $where[] = "DATE(r.fecha_hora_registro) BETWEEN '" .
                $conn->real_escape_string($filter_fecha_inicio) . "' AND '" .
                $conn->real_escape_string($filter_fecha_fin) . "'";
} elseif ($filter_fecha_inicio) {
    $where[] = "DATE(r.fecha_hora_registro) >= '" . $conn->real_escape_string($filter_fecha_inicio) . "'";
} elseif ($filter_fecha_fin) {
    $where[] = "DATE(r.fecha_hora_registro) <= '" . $conn->real_escape_string($filter_fecha_fin) . "'";
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// 3) Consulta
$sql = "
    SELECT
      r.fecha_hora_registro,
      r.turno,
      r.temperatura_inferior,
      r.temperatura_media,
      r.temperatura_superior,
      r.humedad_superior,
      r.humedad_inferior,
      CONCAT(o.Nombre, ' ', o.Apellido_P, ' ', o.Apellido_M) AS operador
    FROM registro_parametros_incubadora r
    JOIN operadores o ON r.id_operador = o.ID_Operador
    $where_sql
    ORDER BY r.fecha_hora_registro DESC
";

$result = $conn->query($sql);

// 4) Colores por fecha
$colors = ['#f1f8e9','#e1f5fe','#fff3e0','#f3e5f5','#e8f5e9','#e3f2fd'];
$date_colors = []; $idx = 0;
foreach ($result as $row) {
$f = date('Y-m-d', strtotime($row['fecha_hora_registro']));
if (!isset($date_colors[$f])) {
    $date_colors[$f] = $colors[$idx++ % count($colors)];
}
}
// volver a ejecutar
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Historial Completo - Incubadora</title>
  <link rel="stylesheet" href="../style.css?v=<?=time();?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
</head>
<script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
<body>
<div class="contenedor-pagina">
<header>
  <div class="encabezado d-flex align-items-center">
    <a class="navbar-brand me-3" href="dashboard_eism.php">
      <img src="../logoplantulas.png" width="130" height="124" alt="Logo"/>
    </a>
    <div>
      <h2>Historial Completo de Parámetros</h2>
      <p class="mb-0">Filtra antes de ver los datos</p>
    </div>
  </div>

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

<nav class="filter-toolbar d-flex flex-wrap gap-3 px-3 py-2 w-100" style="overflow-x: auto;">
  <div class="d-flex flex-column" style="min-width: 130px;">
    <label for="filtro-tipo" class="small mb-1">Tipo</label>
    <select id="filtro-tipo" name="tipo" form="filtrarForm" class="form-select form-select-sm">
      <option value="all"         <?= $filter_tipo==='all'         ? 'selected':'' ?>>— Todos —</option>
      <option value="temperaturas"<?= $filter_tipo==='temperaturas'? 'selected':'' ?>>Temperaturas</option>
      <option value="humedades"   <?= $filter_tipo==='humedades'   ? 'selected':'' ?>>Humedades</option>
    </select>
  </div>

  <div class="d-flex flex-column" style="min-width: 130px;">
    <label for="fecha" class="small mb-1">Fecha exacta</label>
    <input id="fecha" name="fecha" type="date" form="filtrarForm" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_fecha) ?>">
  </div>

  <div class="d-flex flex-column" style="min-width: 130px;">
    <label for="fecha_inicio" class="small mb-1">Fecha – Inicio</label>
    <input id="fecha_inicio" name="fecha_inicio" type="date" form="filtrarForm" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['fecha_inicio'] ?? '') ?>">
  </div>

  <div class="d-flex flex-column" style="min-width: 130px;">
    <label for="fecha_fin" class="small mb-1">Fecha – Fin</label>
    <input id="fecha_fin" name="fecha_fin" type="date" form="filtrarForm" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['fecha_fin'] ?? '') ?>">
  </div>

<div class="d-flex flex-column gap-2">
  <button form="filtrarForm" type="submit" class="btn btn-success btn-sm">Filtrar</button>
  <a href="historial_completo_incubadora.php" class="btn btn-outline-secondary btn-sm">Limpiar</a>
</div>

</nav>
</header>


  <!-- Form oculto para los filtros -->
  <form id="filtrarForm" method="GET" class="d-none"></form>

  <main class="container-fluid mt-4 flex-fill">
    <div class="table-responsive w-100">
      <table class="table table-striped align-middle w-100">
        <thead class="table-light">
          <tr>
            <th>Fecha</th><th>Turno</th>
            <?php if ($filter_tipo==='all' || $filter_tipo==='temperaturas'): ?>
              <th>Inf. (°C)</th><th>Med. (°C)</th><th>Sup. (°C)</th>
            <?php endif; ?>
            <?php if ($filter_tipo==='all' || $filter_tipo==='humedades'): ?>
              <th>Hum. Sup. (%)</th><th>Hum. Inf. (%)</th>
            <?php endif; ?>
            <th>Operador</th><th>Registrado a las</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($r = $result->fetch_assoc()): ?>
<tr style="background-color: <?= $date_colors[date('Y-m-d', strtotime($r['fecha_hora_registro']))] ?>;">
  <td data-label="Fecha"><?= date('Y-m-d H:i:s', strtotime($r['fecha_hora_registro'])) ?></td>
  <td data-label="Turno"><?= htmlspecialchars($r['turno']) ?></td>

  <?php if ($filter_tipo==='all' || $filter_tipo==='temperaturas'): ?>
    <td data-label="Inf. (°C)" style="background-color: rgba(255,0,0,0.1);">
      <?= htmlspecialchars($r['temperatura_inferior']) ?>
    </td>
    <td data-label="Med. (°C)" style="background-color: rgba(255,0,0,0.1);">
      <?= htmlspecialchars($r['temperatura_media']) ?>
    </td>
    <td data-label="Sup. (°C)" style="background-color: rgba(255,0,0,0.1);">
      <?= htmlspecialchars($r['temperatura_superior']) ?>
    </td>
  <?php endif; ?>

  <?php if ($filter_tipo==='all' || $filter_tipo==='humedades'): ?>
    <td data-label="Hum. Sup. (%)" style="background-color: rgba(0,0,255,0.1);">
      <?= htmlspecialchars($r['humedad_superior']) ?>
    </td>
    <td data-label="Hum. Inf. (%)" style="background-color: rgba(0,0,255,0.1);">
      <?= htmlspecialchars($r['humedad_inferior']) ?>
    </td>
  <?php endif; ?>

  <td data-label="Operador"><?= htmlspecialchars($r['operador']) ?></td>
  <td data-label="Registrado a las"><?= htmlspecialchars($r['fecha_hora_registro']) ?></td>
</tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </main>

  <footer class="text-center py-3">
    &copy; 2025 PLANTAS AGRODEX
  </footer>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const fecha         = document.getElementById('fecha');
  const fechaInicio   = document.getElementById('fecha_inicio');
  const fechaFin      = document.getElementById('fecha_fin');

  function toggleBloqueo() {
    if (fecha.value) {
      fechaInicio.disabled = true;
      fechaFin.disabled = true;
    } else if (fechaInicio.value || fechaFin.value) {
      fecha.disabled = true;
    } else {
      fecha.disabled = false;
      fechaInicio.disabled = false;
      fechaFin.disabled = false;
    }
  }

  [fecha, fechaInicio, fechaFin].forEach(input => {
    input.addEventListener('input', toggleBloqueo);
  });

  toggleBloqueo(); // ejecutar al cargar
});
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
