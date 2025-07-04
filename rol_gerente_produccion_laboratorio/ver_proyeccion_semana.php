<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Validar sesión y rol (Rol 6 = Gerente de Producción de Laboratorio)
require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['ID_Operador'])) {
    header('Location: ../login.php?mensaje=Debe iniciar sesión');
    exit;
}
$ID_Operador = (int) $_SESSION['ID_Operador'];
if ((int) $_SESSION['Rol'] !== 6) {
    echo "<p class=\"error\">⚠️ Acceso denegado.</p>";
    exit;
}

date_default_timezone_set('America/Mexico_City');
$conn->query("SET time_zone = '-06:00'");

// ─────────────────────────── Semana actual (lunes‑viernes) ───────────────────────────
$today = new DateTime();
$week  = (int)$today->format('W');
$year  = (int)$today->format('o');

$start = new DateTime();
$start->setISODate($year, $week);            // Lunes 00:00:00
$end   = clone $start;
$end->modify('+4 days');                     // Viernes 23:59:59

$fechaInicio = $start->format('Y-m-d 00:00:00');
$fechaFin    = $end->format('Y-m-d 23:59:59');

// ─────────────────────────── 1) Totales por variedad ───────────────────────────
$sqlTot = "
SELECT resumen.ID_Variedad,
       resumen.Codigo_Variedad,
       resumen.Nombre_Variedad,
       SUM(resumen.Total_Tuppers) AS Total_Tuppers,
       SUM(resumen.Total_Brotes)  AS Total_Brotes
FROM (
  /* MULTIPLICACIÓN */
  SELECT v.ID_Variedad,
         v.Codigo_Variedad,
         v.Nombre_Variedad,
         SUM(p.Tuppers_Proyectados) AS Total_Tuppers,
         SUM(p.Brotes_Proyectados)  AS Total_Brotes
  FROM   proyecciones_lavado p
  JOIN   multiplicacion m ON p.Etapa='multiplicacion' AND p.ID_Etapa=m.ID_Multiplicacion
  JOIN   variedades      v ON v.ID_Variedad = m.ID_Variedad
  WHERE  p.Estado_Flujo IN('lavado', 'pendiente_acomodo')
    AND  IFNULL(p.Fecha_Verificacion,p.Fecha_Creacion) BETWEEN ? AND ?
  GROUP  BY v.ID_Variedad

  UNION ALL

  /* ENRAIZAMIENTO */
  SELECT v.ID_Variedad,
         v.Codigo_Variedad,
         v.Nombre_Variedad,
         SUM(p.Tuppers_Proyectados),
         SUM(p.Brotes_Proyectados)
  FROM   proyecciones_lavado p
  JOIN   enraizamiento   e ON p.Etapa='enraizamiento' AND p.ID_Etapa=e.ID_Enraizamiento
  JOIN   variedades      v ON v.ID_Variedad = e.ID_Variedad
WHERE  p.Estado_Flujo IN('lavado', 'pendiente_acomodo')
    AND  IFNULL(p.Fecha_Verificacion,p.Fecha_Creacion) BETWEEN ? AND ?
  GROUP  BY v.ID_Variedad
) AS resumen
GROUP BY resumen.ID_Variedad
ORDER BY resumen.Codigo_Variedad";

$stmt = $conn->prepare($sqlTot);
$stmt->bind_param('ssss', $fechaInicio, $fechaFin, $fechaInicio, $fechaFin);
$stmt->execute();
$totales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── 2) Detalle de proyecciones verificadas ──
$sqlDet = "
SELECT p.ID_Proyeccion,
       v.Codigo_Variedad,
       v.Nombre_Variedad,
       p.Tuppers_Proyectados,
       p.Brotes_Proyectados,
       p.Estado_Flujo
FROM   proyecciones_lavado p
LEFT   JOIN multiplicacion m ON p.Etapa = 'multiplicacion'
                             AND p.ID_Etapa = m.ID_Multiplicacion
LEFT   JOIN enraizamiento  e ON p.Etapa = 'enraizamiento'
                             AND p.ID_Etapa = e.ID_Enraizamiento
JOIN   variedades v ON v.ID_Variedad = COALESCE(m.ID_Variedad, e.ID_Variedad)
WHERE  p.Estado_Flujo IN('lavado','enviado_tenancingo','pendiente_acomodo','acomodados')
  AND  IFNULL(p.Fecha_Verificacion,p.Fecha_Creacion) BETWEEN ? AND ?
ORDER BY v.Codigo_Variedad, p.ID_Proyeccion";

$stmt = $conn->prepare($sqlDet);
$stmt->bind_param('ss', $fechaInicio, $fechaFin);
$stmt->execute();
$detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─────────────────────────── Timers de sesión ───────────────────────────
$sessionLifetime = 60*3;  // 180 s
$warningOffset   = 60;    // 60 s
$nowTs           = time();
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Resumen semanal de lavado</title>
<link rel="stylesheet" href="../style.css?v=<?= time() ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script>
const SESSION_LIFETIME = <?= $sessionLifetime*1000 ?>;
const WARNING_OFFSET   = <?= $warningOffset*1000 ?>;
let   START_TS         = <?= $nowTs*1000 ?>;
</script>
</head>
<body>
<div class="contenedor-pagina d-flex flex-column min-vh-100">
<header>
  <div class="encabezado">
    <a class="navbar-brand"><img src="../logoplantulas.png" alt="Logo" width="130" height="124"></a>
    <h2>Resumen semanal de lavado<br><small>(<?= $start->format('d/m') ?>–<?= $end->format('d/m/Y') ?>)</small></h2>
  </div>
  <div class="barra-navegacion">
    <nav class="navbar bg-body-tertiary">
      <div class="container-fluid">
        <div class="Opciones-barra">
          <button onclick="location.href='dashboard_gpl.php'">🏠 Volver al Inicio</button>
        </div>
      </div>
    </nav>
  </div>
</header>

<main class="container-fluid mt-3 flex-grow-1">
<?php if (empty($totales)): ?>
  <div class="alert alert-info">
    No hay proyecciones verificadas (lunes-viernes) en la semana <?= $week ?>.
  </div>
<?php else: ?>

  <!-- ─── Tabla 1: Totales por variedad ─── -->
  <section class="section mb-4">
    <h4 class="mb-2">📦 Proyección Lavado Verificados – Totales por variedad</h4>
    <div class="table-responsive">
      <table class="table table-bordered table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th>Variedad</th>
            <th class="text-end">Tuppers</th>
            <th class="text-end">Brotes</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($totales as $r): ?>
          <tr>
            <td data-label="Variedad"><?= htmlspecialchars($r['Codigo_Variedad'].' - '.$r['Nombre_Variedad']) ?></td>
            <td class="text-end" data-label="Tuppers"><?= $r['Total_Tuppers'] ?></td>
            <td class="text-end" data-label="Brotes"><?= $r['Total_Brotes'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ─── Tabla 2: Detalle por proyección con Estatus ─── -->
  <section class="section">
    <h5 class="mb-2">🧪 Detalle de proyecciones verificadas</h5>
    <div class="table-responsive">
      <table class="table table-bordered table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th># Proy</th>
            <th>Variedad</th>
            <th class="text-end">Tuppers</th>
            <th class="text-end">Brotes</th>
            <th>Estatus</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($detalles as $d): ?>
          <tr>
            <td data-label="# Proy"><?= $d['ID_Proyeccion'] ?></td>
            <td data-label="Variedad"><?= htmlspecialchars($d['Codigo_Variedad'].' - '.$d['Nombre_Variedad']) ?></td>
            <td class="text-end" data-label="Tuppers"><?= $d['Tuppers_Proyectados'] ?></td>
            <td class="text-end" data-label="Brotes"><?= $d['Brotes_Proyectados'] ?></td>
            <td data-label="Estatus">
              <?php
                $badge = match($d['Estado_Flujo']) {
                   'enviado_tenancingo' => 'success',
                   'lavado'             => 'info',
                   default              => 'secondary'
                };
              ?>
              <span class="badge bg-<?= $badge ?>">
                <?= ucfirst(str_replace('_',' ',$d['Estado_Flujo'])) ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

<?php endif; ?>
</main>
<footer class="text-center py-3 mt-5">&copy; <?= date('Y') ?> PLANTAS AGRODEX</footer>
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
