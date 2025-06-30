<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Validar sesión y rol (Rol 5 = Encargado General de Producción)
require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

date_default_timezone_set('America/Mexico_City');
$conn->query("SET time_zone = '-06:00'");

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
$sessionLifetime = 60 * 3;
$warningOffset   = 60 * 1;
$nowTs           = time();

$mensajeExito = $_SESSION['flash_msg']   ?? '';
$mensajeError = $_SESSION['flash_error'] ?? '';

unset($_SESSION['flash_msg'], $_SESSION['flash_error']);
// ──────────────────────────────────────────────────────────────
// 3) Procesar envío del formulario
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $etapa       = $_POST['etapa']       ?? '';
    $idEtapa     = (int)($_POST['id_etapa'] ?? 0);
    $tuppersProj = (int)($_POST['tuppers']   ?? 0);
    $brotesProj  = (int)($_POST['brotes']    ?? 0);

    // Validaciones básicas
    if (!in_array($etapa, ['multiplicacion', 'enraizamiento'], true)) {
        $mensajeError = 'Etapa no válida.';
    } elseif ($idEtapa <= 0) {
        $mensajeError = 'Registro de etapa inválido.';
    } elseif ($tuppersProj < 1 || $brotesProj < 1) {
        $mensajeError = 'Las cantidades deben ser mayores a cero.';
    }

    // 3a) Validar disponibilidad en tiempo real
    if (!$mensajeError) {
        $sqlDisp = "SELECT
                        SUM(IF(TipoMovimiento='alta_inicial',  Tuppers,0)) -
                        SUM(IF(TipoMovimiento IN('reserva_lavado','merma'), Tuppers,0)) AS disp_tuppers,
                        SUM(IF(TipoMovimiento='alta_inicial',  Brotes,0)) -
                        SUM(IF(TipoMovimiento IN('reserva_lavado','merma'), Brotes,0))  AS disp_brotes
                     FROM movimientos_proyeccion
                     WHERE Etapa = ? AND ID_Etapa = ?";
        $stmt = $conn->prepare($sqlDisp);
        $stmt->bind_param('si', $etapa, $idEtapa);
        $stmt->execute();
        $disp = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $dispT = (int)($disp['disp_tuppers'] ?? 0);
        $dispB = (int)($disp['disp_brotes']  ?? 0);

        if ($tuppersProj > $dispT || $brotesProj > $dispB) {
            $mensajeError = 'No hay disponibilidad suficiente. Actualiza e intenta de nuevo.';
        }
    }

    // 3b) Insertar proyección si todo ok
if (!$mensajeError) {
    $sqlIns = "INSERT INTO proyecciones_lavado
                  (Etapa, ID_Etapa,
                   Tuppers_Proyectados, Brotes_Proyectados,
                   ID_Creador)
               VALUES (?,?,?,?,?)";
    $stmt = $conn->prepare($sqlIns);
    $stmt->bind_param('siisi',
        $etapa, $idEtapa,
        $tuppersProj, $brotesProj,
        $ID_Operador
    );

    if ($stmt->execute()) {
        /* ✔ éxito → guardo mensaje flash y redirijo */
        $_SESSION['flash_msg'] = '✅ Proyección registrada. Pendiente de verificación.';
    } else {
        /* ❌ error → guardo mensaje flash y redirijo */
        $_SESSION['flash_error'] = '❌ Error al registrar proyección: ' . $stmt->error;
    }
    $stmt->close();

    /* rompe el ciclo de reenvío */
    header("Location: proyeccion_lavado.php");
    exit;
}
}

// ──────────────────────────────────────────────────────────────
// 4) Inventario disponible para el selector
// ----------------------------------------------------------------
$inventario = [];

$consultaInv = "
    /* ─────────── MULTIPLICACIÓN ─────────── */
    SELECT
        'multiplicacion'                AS Etapa,
        m.ID_Multiplicacion             AS ID_Etapa,
        CONCAT(v.Nombre_Variedad,' (ID:',m.ID_Multiplicacion,')') AS Nombre,
        m.Fecha_Siembra                 AS Fecha_Siembra,
        x.disp_t                        AS Disp_Tuppers,
        x.disp_b                        AS Disp_Brotes
    FROM (
        SELECT ID_Etapa,
               SUM(IF(TipoMovimiento='alta_inicial',  Tuppers,0)) -
               SUM(IF(TipoMovimiento='reserva_lavado',Tuppers,0)) AS disp_t,
               SUM(IF(TipoMovimiento='alta_inicial',  Brotes,0)) -
               SUM(IF(TipoMovimiento='reserva_lavado',Brotes,0))  AS disp_b
        FROM movimientos_proyeccion
        WHERE Etapa = 'multiplicacion'
        GROUP BY ID_Etapa
    ) x
    JOIN multiplicacion m ON m.ID_Multiplicacion = x.ID_Etapa
    JOIN variedades     v ON v.ID_Variedad       = m.ID_Variedad
    WHERE m.Extraido_Lavado = 0
      AND x.disp_t > 0
      AND x.disp_b > 0

    UNION ALL

    /* ─────────── ENRAIZAMIENTO ─────────── */
    SELECT
        'enraizamiento'                 AS Etapa,
        e.ID_Enraizamiento              AS ID_Etapa,
        CONCAT(v.Nombre_Variedad,' (ID:',e.ID_Enraizamiento,')') AS Nombre,
        e.Fecha_Siembra                 AS Fecha_Siembra,
        y.disp_t                        AS Disp_Tuppers,
        y.disp_b                        AS Disp_Brotes
    FROM (
        SELECT ID_Etapa,
               SUM(IF(TipoMovimiento='alta_inicial',  Tuppers,0)) -
               SUM(IF(TipoMovimiento='reserva_lavado',Tuppers,0)) AS disp_t,
               SUM(IF(TipoMovimiento='alta_inicial',  Brotes,0)) -
               SUM(IF(TipoMovimiento='reserva_lavado',Brotes,0))  AS disp_b
        FROM movimientos_proyeccion
        WHERE Etapa = 'enraizamiento'
        GROUP BY ID_Etapa
    ) y
    JOIN enraizamiento  e ON e.ID_Enraizamiento = y.ID_Etapa
    JOIN variedades     v ON v.ID_Variedad      = e.ID_Variedad
    WHERE e.Extraido_Lavado = 0
      AND y.disp_t > 0
      AND y.disp_b > 0

    ORDER BY Etapa, Nombre";

    /* ─── Proyecciones existentes con datos de lote y variedad ─── */
$proyecciones = [];

$sqlProy = "
  /* ─── proyecciones ligadas a lotes de MULTIPLICACIÓN ─── */
  SELECT p.ID_Proyeccion,
         v.Nombre_Variedad,
         m.Fecha_Siembra,
         p.Tuppers_Proyectados,
         p.Brotes_Proyectados,
         p.Estado_Flujo AS Estado
  FROM proyecciones_lavado p
  JOIN multiplicacion m
        ON p.Etapa   = 'multiplicacion'
       AND p.ID_Etapa = m.ID_Multiplicacion
  JOIN variedades v
        ON v.ID_Variedad = m.ID_Variedad

  UNION ALL

  /* ─── proyecciones ligadas a lotes de ENRAIZAMIENTO ─── */
  SELECT p.ID_Proyeccion,
         v.Nombre_Variedad,
         e.Fecha_Siembra,
         p.Tuppers_Proyectados,
         p.Brotes_Proyectados,
         p.Estado_Flujo AS Estado
  FROM proyecciones_lavado p
  JOIN enraizamiento e
        ON p.Etapa   = 'enraizamiento'
       AND p.ID_Etapa = e.ID_Enraizamiento
  JOIN variedades v
        ON v.ID_Variedad = e.ID_Variedad

  ORDER BY ID_Proyeccion DESC
";

if ($resP = $conn->query($sqlProy)) {
    $proyecciones = $resP->fetch_all(MYSQLI_ASSOC);
}

$res = $conn->query($consultaInv);
if ($res) $inventario = $res->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>🧼 Proyección de Lavado</title>
  <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS           = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>

<header>
  <div class="encabezado">
    <a class="navbar-brand" href="#"><img src="../logoplantulas.png" alt="Logo" width="130" height="124"></a>
    <h2>🧼 Proyección de Lavado</h2>
  </div>
  <div class="barra-navegacion">
    <nav class="navbar bg-body-tertiary">
      <div class="container-fluid">
        <div class="Opciones-barra">
          <button onclick="window.location.href='dashboard_egp.php'">🏠 Volver al Inicio</button>
        </div>
      </div>
    </nav>
  </div>
</header>

<main class="container mt-4">
  <?php if ($mensajeExito): ?>
    <div class="alert alert-success text-center fw-bold">
      <?= htmlspecialchars($mensajeExito) ?>
    </div>
  <?php elseif ($mensajeError): ?>
    <div class="alert alert-danger text-center fw-bold">
      <?= htmlspecialchars($mensajeError) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($inventario)): ?>
    <div class="alert alert-info">No hay inventario disponible para proyectar.</div>
  <?php else: ?>
<div class="border p-4 mb-5">
  <h4 class="mb-3">Crear nueva proyección</h4>

  <form method="post" class="row g-3">

    <!-- ─────────────── SELECT de lotes ─────────────── -->
    <div class="col-md-6">
      <label class="form-label">Lote disponible</label>
      <select class="form-select" name="id_etapa" id="id_etapa" required>
        <option value="" disabled selected>Seleccione un lote…</option>
        <?php foreach ($inventario as $row): ?>
          <option
            value="<?= $row['ID_Etapa'] ?>"
            data-etapa="<?= $row['Etapa'] ?>"
            data-fecha="<?= $row['Fecha_Siembra'] ?>"
            data-tup="<?= $row['Disp_Tuppers'] ?>"
            data-brotes="<?= $row['Disp_Brotes'] ?>">
            <?= htmlspecialchars(
                  $row['Etapa'] . ' | ' .
                  $row['Nombre'] . ' | ' .
                  $row['Disp_Tuppers'] . ' tup | ' .
                  $row['Disp_Brotes'] . ' brotes'
            ) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="etapa" id="etapa-hidden">

      <!-- Detalle dinámico del lote escogido -->
      <div id="detalle-lote"
           class="alert alert-secondary mt-3"
           style="display:none;">
        <strong>Fecha de siembra:</strong> <span id="det-fecha"></span><br>
        <strong>Tuppers disponibles:</strong> <span id="det-tup"></span><br>
        <strong>Brotes disponibles:</strong> <span id="det-brotes"></span>
      </div>
    </div>

    <!-- ─────────────── Cantidades a proyectar ─────────────── -->
    <div class="col-md-3">
      <label class="form-label">Tuppers a proyectar</label>
      <input type="number" class="form-control" name="tuppers" min="1" required>
    </div>

    <div class="col-md-3">
      <label class="form-label">Brotes a proyectar</label>
      <input type="number" class="form-control" name="brotes" min="1" required>
    </div>

    <div class="col-12">
      <button type="submit" class="btn btn-primary">Guardar Proyección</button>
    </div>
  </form>
</div>

<?php if (!empty($proyecciones)): ?>
  <h5 class="mt-4">Proyecciones registradas</h5>
  <div class="table-responsive">
    <table class="table table-sm table-bordered align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Variedad</th>
          <th>Fecha siembra</th>
          <th class="text-end">Tuppers&nbsp;proyectados.</th>
          <th class="text-end">Brotes&nbsp;proyectados.</th>
          <th>Estado</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($proyecciones as $p): ?>
          <tr>
            <td><?= $p['ID_Proyeccion'] ?></td>
            <td><?= htmlspecialchars($p['Nombre_Variedad']) ?></td>
            <td><?= date('d-m-Y', strtotime($p['Fecha_Siembra'])) ?></td>
            <td class="text-end"><?= $p['Tuppers_Proyectados'] ?></td>
            <td class="text-end"><?= $p['Brotes_Proyectados'] ?></td>
            <td>
              <?php
$badge = match($p['Estado']) {
  'lavado'             => 'info',
  'enviado_tenancingo' => 'success',
  default              => 'secondary'   // pendiente
};
              ?>
              <span class="badge bg-<?= $badge ?>">
                <?= ucfirst($p['Estado']) ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php else: ?>
  <p class="text-muted">Aún no hay proyecciones registradas.</p>
<?php endif; ?>

  <?php endif; ?>
</main>

<footer class="text-center mt-5">
  <p>&copy; <?= date('Y') ?> PLANTAS AGRODEX. Todos los derechos reservados.</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const sel        = document.getElementById('id_etapa');
const hEtapa     = document.getElementById('etapa-hidden');
const box        = document.getElementById('detalle-lote');
const fSpan      = document.getElementById('det-fecha');
const tSpan      = document.getElementById('det-tup');
const bSpan      = document.getElementById('det-brotes');

sel?.addEventListener('change', () => {
  const opt = sel.options[sel.selectedIndex];
  hEtapa.value = opt.dataset.etapa || '';

  // Rellenar y mostrar detalle
  fSpan.textContent = opt.dataset.fecha  || '-';
  tSpan.textContent = opt.dataset.tup    || '0';
  bSpan.textContent = opt.dataset.brotes || '0';
  box.style.display = 'block';
});
</script>

<script>
// ─── lógica de modal de expiración de sesión ───
(function () {
  let modalShown = false, warningTimer, expireTimer;

  function showModal() {
    modalShown = true;
    const html = `
      <div id="session-warning" class="modal-overlay">
        <div class="modal-box">
          <p>Tu sesión va a expirar pronto. ¿Deseas mantenerla activa?</p>
          <button id="keepalive-btn" class="btn-keepalive">Seguir activo</button>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
    document.getElementById('keepalive-btn').addEventListener('click', cerrarModalYReiniciar);
  }

  function cerrarModalYReiniciar() {
    document.getElementById('session-warning')?.remove();
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
    const warnAfter   = SESSION_LIFETIME - WARNING_OFFSET;
    warningTimer = setTimeout(showModal, warnAfter);
    expireTimer  = setTimeout(() => window.location.href =
        '../login.php?mensaje=' + encodeURIComponent('Sesión caducada por inactividad'),
        SESSION_LIFETIME);
  }

  ['click', 'keydown'].forEach(evt =>
    document.addEventListener(evt, () => {
      reiniciarTimers();
      fetch('../keepalive.php', { credentials: 'same-origin' }).catch(() => {});
    })
  );

  scheduleTimers();
})();
</script>

</body>
</html>
