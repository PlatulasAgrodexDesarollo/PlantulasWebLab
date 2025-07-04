<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Validar sesión y rol (Rol 5 = Encargado General de Producción)
require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

date_default_timezone_set('America/Mexico_City');
$conn->query("SET time_zone = '-06:00'");

/* ──────────────────────────────────────────────────────────────
   Autocompletado AJAX de variedades.
   Se dispara con ?action=buscar_variedad&term=...
───────────────────────────────────────────────────────────────── */
if (isset($_GET['action']) && $_GET['action']==='buscar_variedad') {
    $term = $_GET['term'] ?? '';
    $sql  = "SELECT ID_Variedad, Codigo_Variedad, Nombre_Variedad, Especie
               FROM variedades
              WHERE Estado = 'Activa'
                AND (Codigo_Variedad LIKE ? OR Nombre_Variedad LIKE ?)
              LIMIT 10";
    $stmt = $conn->prepare($sql);
    $like = "%$term%";
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $rs   = $stmt->get_result();
    $out  = [];
    while ($row = $rs->fetch_assoc()) {
        $out[] = [
            'id'     => $row['ID_Variedad'],
            'especie'=> $row['Especie'],
            'label'  => $row['Codigo_Variedad'].' - '.$row['Nombre_Variedad'],
            'value'  => $row['Codigo_Variedad'].' - '.$row['Nombre_Variedad']
        ];
    }
    echo json_encode($out);
    exit;
}

/* ───────────────────────  Seguridad de sesión ─────────────────────── */
if (!isset($_SESSION['ID_Operador'])) {
    header('Location: ../login.php?mensaje=Debe iniciar sesión');
    exit;
}
$ID_Operador = (int) $_SESSION['ID_Operador'];
if ((int) $_SESSION['Rol'] !== 5) {
    echo "<p class='error'>⚠️ Acceso denegado.</p>";  exit;
}

/* timers de sesión para JS */
$sessionLifetime = 60*3;  // 180 s
$warningOffset   = 60;    // 60 s
$nowTs           = time();

/******************** 1. Procesar envío de corrección *******************/
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $idProy  = (int)($_POST['id_proyeccion'] ?? 0);
    $tuppers = (int)($_POST['tuppers'] ?? 0);
    $brotes  = (int)($_POST['brotes']  ?? 0);
    $extraSql = '';

    $camposErr = [];
    if ($idProy<=0)               $camposErr[] = 'Proyección inválida';
    if ($tuppers<1)               $camposErr[] = 'Tuppers debe ser >0';
    if ($brotes<1)                $camposErr[] = 'Brotes debe ser >0';

    if ($camposErr) {
        echo "<script>alert('❌ ".implode("\\n",$camposErr)."'); history.back();</script>";
        exit;
    }

/* --- UPDATE dinámico: si cambia variedad, se añade al SET --- */

    $sql = "
        UPDATE proyecciones_lavado
           SET Tuppers_Proyectados = ?,
               Brotes_Proyectados  = ?$extraSql,
               Estado_Flujo        = 'pendiente',
               Motivo_Correccion   = NULL,
               Campos_Rechazados   = NULL,
               ID_Verificador      = NULL,
               Fecha_Verificacion  = NULL
         WHERE ID_Proyeccion = ?
           AND ID_Creador    = ?";

    $stmt = $conn->prepare($sql);

    /* bind según haya o no nuevo ID_Variedad */
    if ($idVar > 0) {
        //        tup   bro   var   idProy creador
        $stmt->bind_param('iiii', $tuppers, $brotes, $idProy, $ID_Operador);
    } else {
        //        tup   bro   idProy creador
        $stmt->bind_param('iiii',  $tuppers, $brotes, $idProy, $ID_Operador);
    }

    $stmt->execute();

    echo "<script>
            alert('✅ Proyección corregida. Ahora está nuevamente pendiente de verificación.');
            window.location.href = 'corregir_proyecciones.php';
          </script>";
    exit;
}

/******************** 2. Traer proyecciones devueltas *******************/
$sql = "SELECT p.ID_Proyeccion,
               p.Motivo_Correccion,
               p.Campos_Rechazados,
               p.Tuppers_Proyectados,
               p.Brotes_Proyectados,
               p.Etapa,
               p.ID_Etapa,
               IF(p.Etapa='multiplicacion',v1.Codigo_Variedad,v2.Codigo_Variedad) AS Codigo_Variedad,
               IF(p.Etapa='multiplicacion',v1.Nombre_Variedad,v2.Nombre_Variedad) AS Nombre_Variedad,
               IF(p.Etapa='multiplicacion',m.Fecha_Siembra,e.Fecha_Siembra)       AS Fecha_Siembra,
               IF(p.Etapa='multiplicacion',v1.ID_Variedad,v2.ID_Variedad)         AS ID_Variedad
          FROM proyecciones_lavado p
     LEFT JOIN multiplicacion m ON p.Etapa='multiplicacion' AND p.ID_Etapa=m.ID_Multiplicacion
     LEFT JOIN variedades v1     ON v1.ID_Variedad = m.ID_Variedad
     LEFT JOIN enraizamiento e   ON p.Etapa='enraizamiento' AND p.ID_Etapa=e.ID_Enraizamiento
     LEFT JOIN variedades v2     ON v2.ID_Variedad = e.ID_Variedad
         WHERE p.Estado_Flujo='correccion'
           AND p.ID_Creador = ?
         ORDER BY Fecha_Siembra";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i',$ID_Operador);
$stmt->execute();
$pendientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Corrección de Proyecciones</title>
<link rel="stylesheet" href="../style.css?v=<?= time() ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    .readonly {
    background:#e9ecef;
    pointer-events:none;        /* el usuario no puede hacer click */
    input.readonly { cursor: not-allowed; }
}
</style>
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
    <h2>Corrección de Proyecciones de Lavado</h2>
  </div>
  <div class="barra-navegacion">
    <nav class="navbar bg-body-tertiary">
      <div class="container-fluid">
        <div class="Opciones-barra">
          <button onclick="location.href='dashboard_egp.php'">🏠 Volver al Inicio</button>
        </div>
      </div>
    </nav>
  </div>
</header>

<main class="container mt-4">
  <h4>Proyecciones devueltas para corrección</h4>
<?php if (empty($pendientes)): ?>
  <div class="alert alert-info">No tienes proyecciones por corregir.</div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-bordered align-middle table-sm">
      <thead class="table-light">
        <tr>
          <th>Variedad</th>
          <th>Etapa</th>
          <th>Fecha Siembra</th>
          <th class="text-end">Tuppers</th>
          <th class="text-end">Brotes</th>
          <th>Observaciones</th>
          <th>Acción</th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($pendientes as $p): ?>
        <tr>
          <td><?= htmlspecialchars($p['Codigo_Variedad'].' - '.$p['Nombre_Variedad']) ?></td>
          <td><?= ucfirst($p['Etapa']) ?></td>
          <td><?= date('d-m-Y',strtotime($p['Fecha_Siembra'])) ?></td>
          <td class="text-end"><?= $p['Tuppers_Proyectados'] ?></td>
          <td class="text-end"><?= $p['Brotes_Proyectados'] ?></td>
          <td><?= htmlspecialchars($p['Motivo_Correccion']) ?></td>
          <td>
            <button class="btn btn-sm btn-primary btn-correccion"
                    data-id="<?= $p['ID_Proyeccion'] ?>"
                    data-tup="<?= $p['Tuppers_Proyectados'] ?>"
                    data-brotes="<?= $p['Brotes_Proyectados'] ?>"
                    data-variedad="<?= $p['ID_Variedad'] ?>"
                    data-campos='<?= $p['Campos_Rechazados'] ?>'
                    data-motivo="<?= htmlspecialchars($p['Motivo_Correccion']) ?>">
              ✏ Corregir
            </button>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</main>

<!-- ==== MODAL Corrección ==== -->
<div class="modal fade" id="modalCorreccion" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" id="formCorreccion">
        <div class="modal-header">
          <h5 class="modal-title">Corregir proyección</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id_proyeccion">
          <input type="hidden" name="ID_Variedad" id="id_variedad">

          <div class="mb-3">
            <label class="form-label">Motivo original</label>
            <p class="form-control-plaintext" id="motivo_text"></p>
          </div>

          <div class="mb-3">
            <label class="form-label">Tuppers proyectados</label>
            <input type="number" name="tuppers" id="in_tuppers"  class="form-control" min="1">
          </div>

          <div class="mb-3">
            <label class="form-label">Brotes proyectados</label>
            <input type="number" name="brotes" id="in_brotes" class="form-control" min="1">
          </div>

          <div class="mb-3">
            <label class="form-label">Variedad </label>
            <input type="text" id="nombre_variedad" class="form-control" placeholder="Buscar variedad..." autocomplete="off" readonly>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Enviar corrección</button>
        </div>
      </form>
    </div>
  </div>
</div>

<footer class="text-center mt-5">
  &copy; <?= date('Y') ?> PLANTAS AGRODEX. Todos los derechos reservados.
</footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">

<script>
/* ===== abrir modal y rellenar ===== */
document.querySelectorAll('.btn-correccion').forEach(btn => {
  btn.addEventListener('click', () => {
    const f = document.getElementById('formCorreccion');
    f.id_proyeccion.value = btn.dataset.id;
    f.tuppers.value       = btn.dataset.tup;
    f.brotes.value        = btn.dataset.brotes;
    document.getElementById('motivo_text').textContent = btn.dataset.motivo;

    /* ---- campos rechazados (JSON) ---- */
    const rech = JSON.parse(btn.dataset.campos || '[]');

    /* helper: activa / bloquea y pinta */
    const setEditable = (input, campoSql) => {
      const editable = rech.includes(campoSql);
      input.readOnly = !editable;
      input.required =  editable;
      input.classList.toggle('readonly', !editable);
    };

    /* tuppers y brotes */
    setEditable(document.getElementById('in_tuppers'), 'Tuppers_Proyectados');
    setEditable(document.getElementById('in_brotes'),  'Brotes_Proyectados');

    /* variedad */
    const vIn = document.getElementById('nombre_variedad');
    const idV = document.getElementById('id_variedad');
    const editableVar = rech.includes('Variedad');

    vIn.readOnly = !editableVar;
    vIn.required =  editableVar;
    vIn.classList.toggle('readonly', !editableVar);

    if (editableVar) {
      vIn.value = '';
      idV.value = '';
    } else {
      vIn.value = 'Sin cambio';
      idV.value = btn.dataset.variedad;
    }

    new bootstrap.Modal(document.getElementById('modalCorreccion')).show();
  });
});

/* ===== autocompletado de variedad ===== */
$(function () {
  $('#nombre_variedad').autocomplete({
    source: 'corregir_proyecciones.php?action=buscar_variedad',
    minLength: 2,
    select: function (_ev, ui) {
      $('#id_variedad').val(ui.item.id);
    }
  });

  $('#formCorreccion').on('submit', function () {
    const vIn = document.getElementById('nombre_variedad');
    if (!vIn.readOnly && !$('#id_variedad').val()) {
      alert('Selecciona una variedad válida de la lista.');
      return false;
    }
  });
});
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
