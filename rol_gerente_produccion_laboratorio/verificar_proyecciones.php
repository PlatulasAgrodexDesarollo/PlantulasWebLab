<?php
ini_set('display_errors',1); 
ini_set('display_startup_errors',1); 
error_reporting(E_ALL);

require_once __DIR__.'/../session_manager.php';
require_once __DIR__.'/../db.php';

date_default_timezone_set('America/Mexico_City');
$conn->query("SET time_zone = '-06:00'");

if (!isset($_SESSION['ID_Operador'])) {
    header('Location: ../login.php?mensaje=Debe iniciar sesión'); exit;
}
if ((int)$_SESSION['Rol'] !== 6) {                    // ← ajusta si tu ID-rol es otro
    echo "<p class='error'>⚠️ Acceso denegado. Solo Gerente de Producción.</p>"; exit;
}

$sessionLifetime = 180;  $warningOffset = 60;  $nowTs = time();

/* ─── avisos flash tras redirect ─── */
$mensajeExito = $_SESSION['flash_msg']   ?? '';
$mensajeError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_error']);

/* ─────────────────  Procesar POST  ───────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $idProy  = (int)($_POST['id_proyeccion'] ?? 0);
    $accion  = $_POST['accion'] ?? '';
    $motivo  = trim($_POST['motivo'] ?? '');

    /* NUEVO → lista de parámetros seleccionados en el modal */
    $campos  = json_encode($_POST['campos_rechazados'] ?? []);

    /* Validación básica */
    if ($idProy <= 0 || !in_array($accion, ['aprobar', 'corregir'], true)) {
        $_SESSION['flash_error'] = 'Solicitud no válida.';
        header('Location: verificar_proyecciones.php'); exit;
    }

    /* ───── aprobar ───── */
    if ($accion === 'aprobar') {

        $stmt = $conn->prepare("
            UPDATE proyecciones_lavado
               SET Estado_Flujo = 'lavado'
             WHERE ID_Proyeccion = ?
               AND Estado_Flujo  = 'pendiente'
        ");
        $stmt->bind_param('i', $idProy);

    /* ───── corregir ───── */
    } else {

        if ($motivo === '') {
            $_SESSION['flash_error'] = 'Debes indicar el motivo de corrección.';
            header('Location: verificar_proyecciones.php'); exit;
        }

        $stmt = $conn->prepare("
            UPDATE proyecciones_lavado
               SET Estado_Flujo      = 'correccion',
                   Motivo_Correccion = ?,
                   Campos_Rechazados = ?
             WHERE ID_Proyeccion = ?
               AND Estado_Flujo  = 'pendiente'
        ");
        /* motivo (TEXT) | campos (JSON) | id (INT) */
        $stmt->bind_param('ssi', $motivo, $campos, $idProy);
    }

    /* Ejecutar y dejar mensaje flash */
    if ($stmt->execute()) {
        $_SESSION['flash_msg'] = ($accion === 'aprobar')
            ? '✅ Proyección aprobada.'
            : '⚠️ Proyección marcada para corrección.';
    } else {
        $_SESSION['flash_error'] = 'Error al actualizar: '.$stmt->error;
    }
    $stmt->close();

    /* Rompe el ciclo PRG */
    header('Location: verificar_proyecciones.php'); exit;
}

/* ───────────────────────- Consultar proyecciones pendientes -───────────── */
$pendientes = [];

$sql = "
  /* MULTIPLICACIÓN */
  SELECT p.ID_Proyeccion, 'multiplicacion' AS Etapa,
         v.Codigo_Variedad, v.Nombre_Variedad,
         m.Fecha_Siembra,
         p.Tuppers_Proyectados, p.Brotes_Proyectados
  FROM proyecciones_lavado p
  JOIN multiplicacion m ON p.Etapa='multiplicacion' AND p.ID_Etapa=m.ID_Multiplicacion
  JOIN variedades      v ON v.ID_Variedad=m.ID_Variedad
  WHERE p.Estado_Flujo='pendiente'

  UNION ALL

  /* ENRAIZAMIENTO */
  SELECT p.ID_Proyeccion, 'enraizamiento',
         v.Codigo_Variedad, v.Nombre_Variedad,
         e.Fecha_Siembra,
         p.Tuppers_Proyectados, p.Brotes_Proyectados
  FROM proyecciones_lavado p
  JOIN enraizamiento e ON p.Etapa='enraizamiento' AND p.ID_Etapa=e.ID_Enraizamiento
  JOIN variedades    v ON v.ID_Variedad=e.ID_Variedad
  WHERE p.Estado_Flujo='pendiente'

  ORDER BY Fecha_Siembra, Codigo_Variedad
";
if ($rs = $conn->query($sql)) $pendientes = $rs->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Verificación de Proyecciones de Lavado</title>
<link rel="stylesheet" href="../style.css?v=<?=time()?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script>
const SESSION_LIFETIME = <?= $sessionLifetime*1000 ?>;
const WARNING_OFFSET   = <?= $warningOffset*1000 ?>;
let   START_TS         = <?= $nowTs*1000 ?>;
</script>
</head>
<body>
<div class="contenedor-pagina">
<header>
  <div class="encabezado">
    <a class="navbar-brand"><img src="../logoplantulas.png" alt="Logo" width="130" height="124"></a>
    <h2>Verificación de Proyecciones de Lavado</h2>
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

<main>
<div class="form-container">
<div class="form-center">
  <h2>Proyecciones pendientes</h2>

<?php if ($mensajeExito): ?>
  <div class="alert alert-success text-center fw-bold"><?=htmlspecialchars($mensajeExito)?></div>
<?php elseif ($mensajeError): ?>
  <div class="alert alert-danger  text-center fw-bold"><?=htmlspecialchars($mensajeError)?></div>
<?php endif; ?>

<?php if (empty($pendientes)): ?>
  <div class="alert alert-info">No hay proyecciones por revisar.</div>
<?php else: ?>

<div class="table-responsive">
<table class="table table-bordered">
  <thead>
    <tr>
      <th>Variedad</th>
      <th>Etapa</th>
      <th>Fecha&nbsp;Siembra</th>
      <th>Tuppers&nbsp;Proyectados</th>
      <th>Brotes&nbsp;Proyectados</th>
      <th>Acciones</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($pendientes as $p): ?>
  <tr>
    <td data-label="Variedad"><?=htmlspecialchars($p['Codigo_Variedad'].' - '.$p['Nombre_Variedad'])?></td>
    <td data-label="Etapa"><?=ucfirst($p['Etapa'])?></td>
    <td data-label="Fecha"><?=date('d-m-Y',strtotime($p['Fecha_Siembra']))?></td>
    <td data-label="Tuppers" class="text-end"><?=$p['Tuppers_Proyectados']?></td>
    <td data-label="Brotes"  class="text-end"><?=$p['Brotes_Proyectados']?></td>
<td data-label="Acción">
  <div class="botones-contenedor">
    <!--Verificar -->
    <form method="POST" class="form-boton">
      <input type="hidden" name="id_proyeccion" value="<?= $p['ID_Proyeccion'] ?>">
      <input type="hidden" name="accion"        value="aprobar">
      <button class="save-button verificar">✔ Verificar</button>
    </form>

    <!--Incorrecto (abre modal) -->
    <button type="button"
            class="save-button incorrecto"
            data-id="<?= $p['ID_Proyeccion'] ?>"
            onclick="mostrarRechazoModal(this)">
      ✖ Incorrecto
    </button>
  </div>
</td>
  </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div> <!-- /.table-responsive -->
<?php endif; ?>

</div><!-- /.form-center -->
</div><!-- /.form-container -->
</main>

<!-- Modal para marcar proyección incorrecta -->
<div class="modal fade" id="rechazoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" id="rechazoForm" onsubmit="return confirmarRechazo(this);">
        <div class="modal-header">
          <h5 class="modal-title">Rechazo de proyección</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="id_proyeccion" id="rechazoId">
          <input type="hidden" name="accion" value="corregir">

          <div class="mb-3">
            <label class="form-label">¿Qué parámetro está incorrecto?</label>

            <div class="form-check">
              <input class="form-check-input" type="checkbox"
                     name="campos_rechazados[]" value="Tuppers_Proyectados"
                     id="chk_tup">
              <label class="form-check-label" for="chk_tup">
                Tuppers proyectados
              </label>
            </div>

            <div class="form-check">
              <input class="form-check-input" type="checkbox"
                     name="campos_rechazados[]" value="Brotes_Proyectados"
                     id="chk_bro">
              <label class="form-check-label" for="chk_bro">
                Brotes proyectados
              </label>
            </div>

            <div class="form-check">
              <input class="form-check-input" type="checkbox"
                     name="campos_rechazados[]" value="Variedad"
                     id="chk_var">
              <label class="form-check-label" for="chk_var">
                Variedad
              </label>
            </div>

            <div class="form-check">
              <input class="form-check-input" type="checkbox"
                     name="campos_rechazados[]" value="Fecha_Siembra"
                     id="chk_fecha">
              <label class="form-check-label" for="chk_fecha">
                Fecha de siembra
              </label>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Motivo del rechazo</label>
            <textarea name="motivo" class="form-control" required></textarea>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary"
                  data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Enviar rechazo</button>
        </div>
      </form>
    </div>
  </div>
</div>

<footer>
  <p>&copy; <?=date('Y')?> PLANTAS AGRODEX. Todos los derechos reservados.</p>
</footer>
</div><!-- /.contenedor-pagina -->

<script>
/* abre el modal y precarga el ID de la proyección */
function mostrarRechazoModal(btn){
  document.getElementById('rechazoId').value = btn.dataset.id;
  // limpiar selección anterior
  document.querySelectorAll("input[name='campos_rechazados[]']")
          .forEach(cb => cb.checked = false);
  document.querySelector("textarea[name='motivo']").value = "";

  const modal = new bootstrap.Modal(document.getElementById('rechazoModal'));
  modal.show();
}

/* validación antes de enviar */
function confirmarRechazo(form){
  const motivo  = form.motivo.value.trim();
  const checks  = form.querySelectorAll("input[name='campos_rechazados[]']:checked");

  if(!motivo){
    alert('Debes indicar un motivo.');
    return false;
  }
  if(checks.length === 0){
    alert('Debes seleccionar al menos un parámetro incorrecto.');
    return false;
  }
  return true;
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function pedirMotivo(f){
  const txt = prompt('Describe el motivo de corrección:');
  if(!txt || txt.trim()===''){ alert('Debes indicar un motivo.'); return false; }
  f.motivo.value = txt.trim();
  return true;
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
