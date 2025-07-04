<?php
// 1) Seguridad y conexión
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

date_default_timezone_set('America/Mexico_City');

/* ─── Parámetros del modal de expiración ───────── */
$sessionLifetime = 60*3;  // 3 min
$warningOffset   = 60;    // 1 min antes
$nowTs           = time();

if (!isset($_SESSION['ID_Operador'])) {
    header('Location: ../login.php');
    exit;
}
$ID_Operador = (int) $_SESSION['ID_Operador'];

if ((int) $_SESSION['Rol'] !== 4) {
    echo "<p class='error'>Acceso denegado. Solo Supervisora de Incubadora.</p>";
    exit;
}

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $etapa            = $_POST['etapa'];
    $id_origen        = (int) $_POST['id_origen'];
    $tuppers_perdidos = (int) $_POST['tuppers_perdidos'];
    $brotes_perdidos  = (int) $_POST['brotes_perdidos'];
    $motivo           = trim($_POST['motivo']);
    $fecha            = date('Y-m-d');

   if (!in_array($etapa, ['multiplicacion', 'enraizamiento'])) {
    exit;
}
$tabla = $etapa;

    $consulta = $conn->prepare("SELECT Tuppers_Disponibles, Brotes_Disponibles, Operador_Responsable FROM $tabla WHERE ID_{$etapa} = ?");
    $consulta->bind_param('i', $id_origen);
    $consulta->execute();
    $res = $consulta->get_result();

    if ($res->num_rows === 0) {
        $mensaje = '❌ Registro no encontrado';
    } else {
        $row = $res->fetch_assoc();
        $nuevosT = max(0, $row['Tuppers_Disponibles'] - $tuppers_perdidos);
        $nuevosB = max(0, $row['Brotes_Disponibles'] - $brotes_perdidos);

        $stmt = $conn->prepare("INSERT INTO perdidas_laboratorio
            (ID_Entidad, Tipo_Entidad, Fecha_Perdida, Tuppers_Perdidos, Brotes_Perdidos, Motivo, Operador_Entidad, Operador_Chequeo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('issiissi',
            $id_origen,
            $etapa,
            $fecha,
            $tuppers_perdidos,
            $brotes_perdidos,
            $motivo,
            $row['Operador_Responsable'],
            $ID_Operador
        );

        if ($stmt->execute()) {
            $id_perdida = $stmt->insert_id;
            $update = $conn->prepare("UPDATE $tabla SET Tuppers_Disponibles = ?, Brotes_Disponibles = ?, ID_Perdida = ? WHERE ID_{$etapa} = ?");
            $update->bind_param('iiii', $nuevosT, $nuevosB, $id_perdida, $id_origen);
            $update->execute();
            $mensaje = '✅ Pérdida registrada correctamente';
        } else {
            $mensaje = '❌ Error al registrar pérdida: ' . $stmt->error;
        }
    }
}
if (isset($_GET['etapa']) && isset($_GET['termino'])) {
    $etapa   = $_GET['etapa'];
    $termino = $_GET['termino'];

    $tabla = ($etapa === 'multiplicacion') ? 'multiplicacion' : 'enraizamiento';
    $idCampo = ($etapa === 'multiplicacion') ? 'ID_Multiplicacion' : 'ID_Enraizamiento';

    $sql = "
SELECT t.$idCampo AS ID, t.Fecha_Siembra, v.Nombre_Variedad, v.Codigo_Variedad, 
       t.Tuppers_Disponibles, t.Brotes_Disponibles, mn.Codigo_Medio,
       CONCAT(o.Nombre, ' ', o.Apellido_P, ' ', o.Apellido_M) AS Operador
FROM $tabla t
INNER JOIN variedades v ON t.ID_Variedad = v.ID_Variedad
LEFT JOIN operadores o ON t.Operador_Responsable = o.ID_Operador
LEFT JOIN medios_nutritivos mn ON t.ID_MedioNutritivo = mn.ID_MedioNutritivo
WHERE v.Nombre_Variedad LIKE CONCAT('%', ?, '%')
   OR v.Codigo_Variedad LIKE CONCAT('%', ?, '%')
ORDER BY t.Fecha_Siembra DESC
LIMIT 5
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $termino, $termino);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo "<div class='alert alert-warning'>No se encontraron coincidencias.</div>";
    } else {
        echo "<div class='list-group'>";
        while ($row = $result->fetch_assoc()) {
echo "
<button type='button' class='list-group-item list-group-item-action'
        onclick=\"seleccionarRegistro({$row['ID']}, '{$row['Nombre_Variedad']}', '{$row['Codigo_Variedad']}', '{$row['Fecha_Siembra']}', '{$row['Operador']}', '{$row['Codigo_Medio']}'
, {$row['Tuppers_Disponibles']}, {$row['Brotes_Disponibles']})\">
    <strong>{$row['Codigo_Variedad']} – {$row['Nombre_Variedad']}</strong><br>
    Siembra: {$row['Fecha_Siembra']}<br>
    Operador: {$row['Operador']}
</button>";
        }
        echo "</div>";
    }
    exit; // Detiene ejecución para que no continúe con el HTML completo
}

$hoy = date('Y-m-d');
$historial = $conn->query("SELECT p.Fecha_Perdida, p.Tipo_Entidad, p.Tuppers_Perdidos, p.Brotes_Perdidos, p.Motivo FROM perdidas_laboratorio p WHERE p.Fecha_Perdida = '$hoy' ORDER BY p.ID_Perdida DESC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Registro de Pérdidas</title>
  <link rel="stylesheet" href="../style.css?v=<?=time();?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    const SESSION_LIFETIME = <?= $sessionLifetime*1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset*1000 ?>;
    let   START_TS         = <?= $nowTs*1000 ?>;
  </script>
</head>
<body>
<div class="contenedor-pagina">
  <header class="encabezado d-flex align-items-center">
    <a class="navbar-brand me-3" href="dashboard_supervisora.php">
      <img src="../logoplantulas.png" alt="Logo" width="130" height="124">
    </a>
    <div>
      <h2>Registro de Pérdidas</h2>
      <p>Multiplicación y Enraizamiento</p>
    </div>
  </header>
    <div class="barra-navegacion">
      <nav class="navbar bg-body-tertiary">
        <div class="container-fluid">
          <div class="Opciones-barra">
            <button onclick="window.location.href='dashboard_supervisora.php'">🏠 Volver al Inicio</button>
          </div>
        </div>
      </nav>
    </div>
  <main class="container my-4">
    <?php if ($mensaje): ?>
      <div class="alert <?= str_starts_with($mensaje, '✅') ? 'alert-success' : 'alert-danger' ?>"> <?= $mensaje ?> </div>
    <?php endif; ?>

    <form method="POST" class="row g-3 mb-5">
      <div class="col-md-6">
        <label for="etapa" class="form-label">Etapa</label>
        <select name="etapa" id="etapa" class="form-select" required>
          <option value="">Seleccionar...</option>
          <option value="multiplicacion">Multiplicación</option>
          <option value="enraizamiento">Enraizamiento</option>
        </select>
      </div>
      <div class="col-md-6">
        <label for="busqueda_variedad" class="form-label">Buscar Variedad</label>
        <input type="text" id="busqueda_variedad" class="form-control" placeholder="Ej. Forza..." autocomplete="off">
        <input type="hidden" name="id_origen" id="id_origen">
      </div>

      <div id="info_registro" class="col-12"></div>

      <div class="col-md-6">
        <label for="tuppers_perdidos" class="form-label">Tuppers Perdidos</label>
        <input type="number" name="tuppers_perdidos" class="form-control" min="1" required>
      </div>
      <div class="col-md-6">
        <label for="brotes_perdidos" class="form-label">Brotes Perdidos</label>
        <input type="number" name="brotes_perdidos" class="form-control" min="1" required>
      </div>
      <div class="col-12">
        <label for="motivo" class="form-label">Motivo</label>
        <input type="text" name="motivo" id="motivo" class="form-control" maxlength="100" required>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-primary">Guardar Pérdida</button>
      </div>
    </form>

<h4>Historial de pérdidas de hoy</h4>
<div class="table-responsive">
  <table class="table table-striped table-bordered table-sm align-middle">
    <thead class="table-light">
      <tr>
        <th scope="col">Fecha</th>
        <th scope="col">Etapa</th>
        <th scope="col">Tuppers</th>
        <th scope="col">Brotes</th>
        <th scope="col">Motivo</th>
      </tr>
    </thead>
    <tbody>
      <?php while ($r = $historial->fetch_assoc()): ?>
      <tr>
        <td data-label="Fecha"><?= htmlspecialchars($r['Fecha_Perdida']) ?></td>
        <td data-label="Etapa"><?= htmlspecialchars(ucfirst($r['Tipo_Entidad'])) ?></td>
        <td data-label="Tuppers"><?= (int)$r['Tuppers_Perdidos'] ?></td>
        <td data-label="Brotes"><?= (int)$r['Brotes_Perdidos'] ?></td>
        <td data-label="Motivo"><?= htmlspecialchars($r['Motivo']) ?></td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>
  </main>
  <footer class="text-center py-3">
    <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
  </footer>
</div>
<script>
function seleccionarRegistro(id, nombre, codigo, fecha, operador, medio, tuppers, brotes) {
  $('#id_origen').val(id);
  $('#info_registro').html(`
    <div class="alert alert-info mt-3">
      <strong>Seleccionado:</strong><br>
      <b>Variedad:</b> ${codigo} – ${nombre}<br>
      <b>Medio Nutritivo:</b> ${medio}<br>
      <b>Brotes Disponibles:</b> ${brotes}<br>
      <b>Tuppers Disponibles:</b> ${tuppers}<br>
      <b>Operador Responsable:</b> ${operador}<br>
      <b>Fecha de Siembra:</b> ${fecha}
    </div>
  `);
}

$(document).ready(function() {
  $('#busqueda_variedad').on('input', function() {
    const etapa = $('#etapa').val();
    const termino = $(this).val();
    if (etapa && termino.length >= 2) {
      $.get(window.location.href, { etapa, termino }, function(data) {
        $('#info_registro').html(data);
      });
    }
  });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Modal de expiración de sesión (el mismo que en tus otras páginas) -->
<script>
(function(){
  let modalShown=false,warningTimer,expireTimer;
  function showModal(){
    modalShown=true;
    document.body.insertAdjacentHTML('beforeend',`
      <div id="session-warning" class="modal-overlay">
        <div class="modal-box">
          <p>Tu sesión va a expirar pronto. ¿Deseas mantenerla activa?</p>
          <button id="keepalive-btn" class="btn-keepalive">Seguir activo</button>
        </div>
      </div>`);
    document.getElementById('keepalive-btn').addEventListener('click',cerrarModalYReiniciar);
  }
  function cerrarModalYReiniciar(){
    document.getElementById('session-warning')?.remove();
    reiniciarTimers();
    fetch('../keepalive.php',{credentials:'same-origin'}).catch(()=>{});
  }
  function reiniciarTimers(){
    START_TS=Date.now(); modalShown=false;
    clearTimeout(warningTimer); clearTimeout(expireTimer); scheduleTimers();
  }
  function scheduleTimers(){
    const warnAfter=SESSION_LIFETIME-WARNING_OFFSET;
    warningTimer=setTimeout(showModal,warnAfter);
    expireTimer=setTimeout(()=>window.location.href=
      '../login.php?mensaje='+encodeURIComponent('Sesión caducada por inactividad'),
      SESSION_LIFETIME);
  }
  ['click','keydown'].forEach(evt=>document.addEventListener(evt,()=>{
    reiniciarTimers(); fetch('../keepalive.php',{credentials:'same-origin'}).catch(()=>{});
  }));
  scheduleTimers();
})();
</script>
</body>
</html>
