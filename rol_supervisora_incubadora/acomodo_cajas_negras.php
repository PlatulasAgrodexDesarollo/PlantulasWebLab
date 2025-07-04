<?php
// 0) Mostrar errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

date_default_timezone_set('America/Mexico_City');
$conn->query("SET time_zone = '-06:00'");

if (!isset($_SESSION['ID_Operador'])) {
    header('Location: ../login.php?mensaje=Debe iniciar sesión');
    exit;
}
$ID_Operador = (int) $_SESSION['ID_Operador'];
if ((int) $_SESSION['Rol'] !== 4) {
    echo "<p class=\"error\">⚠️ Acceso denegado.</p>";
    exit;
}

// ─────── POST: Registro del acomodo ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idProy    = (int) $_POST['ID_Proyeccion'];
    $tupAcom   = (int) $_POST['Tuppers_Acomodados'];
    $broAcom   = $tupAcom * 12;
    $cont      = $_POST['Contaminacion'] ?? 'no';
    $tupCont   = isset($_POST['Tuppers_Contaminados']) ? (int) $_POST['Tuppers_Contaminados'] : 0;
    $destCont  = $_POST['Destino_Contaminados'] ?? '';
    date_default_timezone_set('America/Mexico_City');
$fecha = date('Y-m-d H:i:s');

// Validación: evitar registrar más de lo permitido
$stmt = $conn->prepare("
    SELECT Tuppers_Proyectados,
           COALESCE(Tuppers_Acomodados, 0),
           COALESCE(Tuppers_Contaminados, 0)
    FROM proyecciones_lavado
    WHERE ID_Proyeccion = ?
");
$stmt->bind_param("i", $idProy);
$stmt->execute();
$stmt->bind_result($tupProy, $tupAcomPrevio, $tupContPrevio);
$stmt->fetch();
$stmt->close();

$totalUsados = (int)$tupAcomPrevio + (int)$tupContPrevio;
$tuppersDisponibles = (int)$tupProy - $totalUsados;
$totalIngresados = $tupAcom + $tupCont;

if ($totalIngresados > $tuppersDisponibles) {
    die("<p class='error'>⚠️ Error: No puedes registrar más de los tuppers disponibles (" . $tuppersDisponibles . " restantes).</p>");
}

    // 1. Actualizar proyección
    $sql = "UPDATE proyecciones_lavado
            SET Estado_Flujo = 'acomodados',
                Tuppers_Acomodados = ?,
                Brotes_Acomodados = ?,
                Fecha_Acomodados = ?,
                ID_Supervisora = ?
            WHERE ID_Proyeccion = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iisii', $tupAcom, $broAcom, $fecha, $ID_Operador, $idProy);
    $stmt->execute();
    $stmt->close();

    // 2. Si hay contaminación
if ($cont === 'si' && $tupCont > 0) {
    $tipo = $destCont === 'lavado' ? 'Reprocesado (Lavado)' : 'Pérdida directa';
    
    // Actualizar la misma proyección con la contaminación
$sql = "UPDATE proyecciones_lavado
        SET Tuppers_Contaminados = ?,
            Tipo_Contaminacion = ?,
            Estado_Flujo = 'acomodados'
        WHERE ID_Proyeccion = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('isi', $tupCont, $tipo, $idProy);
    $stmt->execute();
    $stmt->close();

    // Si fue pérdida, insertar también en perdidas_laboratorio
    if ($destCont === 'perdida') {
        $sql = "INSERT INTO perdidas_laboratorio
                (ID_Entidad, Tipo_Entidad, ID_Estado, Fecha_Perdida, Tuppers_Perdidos, Brotes_Perdidos, Motivo, Operador_Entidad, Operador_Chequeo)
                VALUES (?, 'proyeccion_lavado', NULL, ?, ?, ?, 'Contaminación detectada en acomodo', ?, ?)";
        $stmt = $conn->prepare($sql);
        $broPerdidos = $tupCont * 12;
        $stmt->bind_param('isiiii', $idProy, $fecha, $tupCont, $broPerdidos, $ID_Operador, $ID_Operador);
        $stmt->execute();
        $stmt->close();
    }
}

    header('Location: acomodo_cajas_negras.php?mensaje=Registrado');
    exit;
}

// ─────── GET: Mostrar proyecciones ───────
$sql = "
SELECT p.ID_Proyeccion,
       v.Codigo_Variedad,
       v.Nombre_Variedad,
       p.Tuppers_Proyectados,
       p.Brotes_Proyectados,
       COALESCE(p.Tuppers_Acomodados, 0) AS Tuppers_Acomodados,
       COALESCE(p.Tuppers_Contaminados, 0) AS Tuppers_Contaminados
FROM   proyecciones_lavado p
JOIN   multiplicacion m ON p.Etapa = 'multiplicacion' AND p.ID_Etapa = m.ID_Multiplicacion
JOIN   variedades v ON v.ID_Variedad = m.ID_Variedad
WHERE  p.Estado_Flujo = 'pendiente_acomodo'
UNION
SELECT p.ID_Proyeccion,
       v.Codigo_Variedad,
       v.Nombre_Variedad,
       p.Tuppers_Proyectados,
       p.Brotes_Proyectados,
       COALESCE(p.Tuppers_Acomodados, 0) AS Tuppers_Acomodados,
       COALESCE(p.Tuppers_Contaminados, 0) AS Tuppers_Contaminados
FROM   proyecciones_lavado p
JOIN   enraizamiento e ON p.Etapa = 'enraizamiento' AND p.ID_Etapa = e.ID_Enraizamiento
JOIN   variedades v ON v.ID_Variedad = e.ID_Variedad
WHERE  p.Estado_Flujo = 'pendiente_acomodo'
ORDER BY Codigo_Variedad";

$resultado = $conn->query($sql);
$proyecciones = $resultado->fetch_all(MYSQLI_ASSOC);

$sessionLifetime = 180;
$warningOffset   = 60;
$nowTs           = time();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Acomodo en Cajas Negras</title>
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
      <h2>Acomodo en Cajas Negras</h2>
    </div>
    <div class="barra-navegacion">
      <nav class="navbar bg-body-tertiary">
        <div class="container-fluid">
          <div class="Opciones-barra">
            <button onclick="location.href='dashboard_supervisora.php'">🏠 Volver al Inicio</button>
          </div>
        </div>
      </nav>
    </div>
  </header>

  <main class="container-fluid mt-3 flex-grow-1">
    <section class="section mb-4">
      <h4 class="mb-2">📦 Proyecciones pendientes para acomodo</h4>
      <?php if (empty($proyecciones)): ?>
        <div class="alert alert-info">No hay proyecciones pendientes de acomodo.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-bordered table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th># Proy</th>
                <th>Variedad</th>
                <th class="text-end">Tuppers</th>
                <th class="text-end">Brotes</th>
                <th class="text-center">Acción</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($proyecciones as $p): ?>
                <?php
  $tupTotal = (int)$p['Tuppers_Proyectados'];
  $tupUsados = (int)$p['Tuppers_Acomodados'] + (int)$p['Tuppers_Contaminados'];
  $tupDisponibles = $tupTotal - $tupUsados;
?>
              <tr>
  <td data-label="# Proy"><?= $p['ID_Proyeccion'] ?></td>
  <td data-label="Variedad"><?= htmlspecialchars($p['Codigo_Variedad'] . ' - ' . $p['Nombre_Variedad']) ?></td>
  <td class="text-end" data-label="Tuppers"><?= $p['Tuppers_Proyectados'] ?></td>
  <td class="text-end" data-label="Brotes"><?= $p['Brotes_Proyectados'] ?></td>
  <td class="text-center" data-label="Acción">
<?php if ($tupDisponibles > 0): ?>
  <button class="btn btn-sm btn-primary"
          onclick="abrirModal(<?= $p['ID_Proyeccion'] ?>, <?= $tupDisponibles ?>)">
    Acomodar
  </button>
<?php else: ?>
  <span class="text-muted">✔️ Completado</span>
<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
    
  </main>
<?php if (!empty($_GET['mensaje'])): ?>
  <div class="alert alert-success text-center mx-3">
    <?= htmlspecialchars($_GET['mensaje']) ?>
  </div>
<?php endif; ?>

  <footer class="text-center py-3 mt-5">&copy; <?= date('Y') ?> PLANTAS AGRODEX</footer>
</div>

<!-- Modal para acomodo -->
<div class="modal fade" id="modalAcomodo" tabindex="-1" aria-labelledby="modalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title" id="modalLabel">Acomodo en cajas negras</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="ID_Proyeccion" id="inputIDProyeccion">
          <div class="mb-2">
            <label for="inputTuppers" class="form-label">Tuppers acomodados</label>
            <small class="text-muted" id="leyendaDisponibles">(Disponibles: )</small>
            <input type="number" min="1" step="1" class="form-control" name="Tuppers_Acomodados" id="inputTuppers" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Brotes (calculado)</label>
            <input type="number" class="form-control" id="inputBrotesCalculado" readonly>
          </div>
          <div class="mb-2">
            <label class="form-label">¿Hubo contaminación?</label>
            <select class="form-select" name="Contaminacion" id="selectContaminacion" required>
              <option value="no">No</option>
              <option value="si">Sí</option>
            </select>
          </div>
          <div id="seccionContaminacion" style="display:none;">
            <div class="mb-2">
              <label class="form-label">Tuppers contaminados</label>
              <input type="number" class="form-control" name="Tuppers_Contaminados" id="inputContaminados" min="1" step="1">
            </div>
            <div class="mb-2">
              <label class="form-label">¿Destino de contaminados?</label>
              <select class="form-select" name="Destino_Contaminados" id="selectDestino">
                <option value="lavado">Se van a lavado</option>
                <option value="perdida">Son pérdida</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">Registrar acomodo</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let tuppersDisponibles = 0;

function abrirModal(idProyeccion, tuppersMax) {
  const inputTuppers = document.getElementById('inputTuppers');
  const inputBrotes  = document.getElementById('inputBrotesCalculado');
  const inputID      = document.getElementById('inputIDProyeccion');
  const seccionCont  = document.getElementById('seccionContaminacion');
  const selectCont   = document.getElementById('selectContaminacion');
  const inputContam  = document.getElementById('inputContaminados');
  const leyenda      = document.getElementById('leyendaDisponibles');

  tuppersDisponibles = tuppersMax;

  inputID.value = idProyeccion;
  inputTuppers.value = '';
  inputBrotes.value = '';
  inputContam.value = '';
  leyenda.innerText = `(Disponibles: ${tuppersDisponibles})`;
  selectCont.value = 'no';
  seccionCont.style.display = 'none';

  const actualizarValidacion = () => {
  const acomInput = document.getElementById('inputTuppers');
  const contInput = document.getElementById('inputContaminados');
  const brotesOutput = document.getElementById('inputBrotesCalculado');

  let acom = parseInt(acomInput.value) || 0;
  let cont = parseInt(contInput.value) || 0;
  let total = acom + cont;

  // Ajuste automático si el total supera los disponibles
  if (total > tuppersDisponibles) {
    const diferencia = tuppersDisponibles - cont;
    acom = Math.max(diferencia, 0);
    acomInput.value = acom;
    total = acom + cont;
  }

  brotesOutput.value = acom * 12;

  if (total > tuppersDisponibles) {
    acomInput.setCustomValidity("La suma excede los disponibles.");
    contInput.setCustomValidity("La suma excede los disponibles.");
  } else {
    acomInput.setCustomValidity("");
    contInput.setCustomValidity("");
  }
};

  inputTuppers.oninput = actualizarValidacion;
  inputContam.oninput = actualizarValidacion;
  selectCont.onchange = () => {
    seccionCont.style.display = (selectCont.value === 'si') ? 'block' : 'none';
    actualizarValidacion();
  };

  new bootstrap.Modal(document.getElementById('modalAcomodo')).show();
}
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
