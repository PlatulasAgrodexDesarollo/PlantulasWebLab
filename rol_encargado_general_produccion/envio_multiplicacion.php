<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Validar sesión y rol
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

// 2) Variables para el modal de sesión
$sessionLifetime = 60 * 3;
$warningOffset   = 60 * 1;
$nowTs           = time();
$mensaje = '';

// Procesar formulario
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["asignar_variedad"])) {
    $id_diseccion      = intval($_POST['id_diseccion']);
    $codigo_variedad   = $_POST['codigo_variedad'];
    $nombre_variedad   = $_POST['nombre_variedad'];
    $brotes_asignados  = intval($_POST['brotes_asignados']);
    $disponibles       = intval($_POST['brotes_disponibles']);
    $operador_asignado = intval($_POST['operador_asignado']);
    $observaciones_raw = $_POST['observaciones'] ?? '';
    $observaciones     = htmlspecialchars(strip_tags(trim($observaciones_raw)), ENT_QUOTES, 'UTF-8');

    if ($brotes_asignados < 1) {
        $mensaje = "❌ La cantidad de brotes debe ser mínimo 1.";
    } elseif ($brotes_asignados > $disponibles) {
        $mensaje = "❌ No puedes asignar más brotes de los disponibles: $disponibles.";
    } else {
$sql = "INSERT INTO asignaciones_multiplicacion 
          (ID_Diseccion, Codigo_Variedad, Nombre_Variedad, Brotes_Asignados,
           Fecha_Registro, Operador_Asignado, Operador_Que_Asigna, Estado, Observaciones)
        VALUES (?, ?, ?, ?, NOW(), ?, ?, 'Asignado', ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("issiiis",
    $id_diseccion,
    $codigo_variedad,
    $nombre_variedad,
    $brotes_asignados,
    $operador_asignado,
    $ID_Operador,
    $observaciones
);

        if ($stmt->execute()) {
            echo "<script>alert('✅ Asignación registrada correctamente.'); window.location.href='envio_multiplicacion.php';</script>";
            exit;
        } else {
            $mensaje = "❌ Error al guardar asignación: " . $stmt->error;
        }
    }
}

// Consulta de variedades con brotes
$min_brotes_multiplicacion = 80;

$sql = "
    SELECT 
        V.Codigo_Variedad,
        V.Nombre_Variedad,
        (SUM(DH.Brotes_Generados) - IFNULL(SUM(AM.Brotes_Asignados), 0)) AS Total_Brotes_Disponibles,
        MAX(DH.Fecha_Diseccion) AS Ultima_Fecha,
        MAX(DH.ID_Diseccion) AS ID_Diseccion,
        O.Nombre AS Nombre_Operador,
        O.Apellido_P AS ApellidoP_Operador,
        O.Apellido_M AS ApellidoM_Operador
    FROM diseccion_hojas_ecas DH
    JOIN siembra_ecas S ON DH.ID_Siembra = S.ID_Siembra
    JOIN variedades V ON S.ID_Variedad = V.ID_Variedad
    LEFT JOIN asignaciones_multiplicacion AM ON DH.ID_Diseccion = AM.ID_Diseccion
    LEFT JOIN operadores O ON DH.Operador_Responsable = O.ID_Operador
    GROUP BY V.ID_Variedad
    HAVING Total_Brotes_Disponibles >= ?
    ORDER BY Total_Brotes_Disponibles DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $min_brotes_multiplicacion);
$stmt->execute();
$variedades = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Consulta de operadores
$operadores = [];
$res_operadores = $conn->query("SELECT ID_Operador, CONCAT(Nombre, ' ', Apellido_P, ' ', Apellido_M) AS NombreCompleto FROM operadores WHERE Activo = 1 AND ID_Rol = 2");
if ($res_operadores) {
    $operadores = $res_operadores->fetch_all(MYSQLI_ASSOC);
}
?>


<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Envío a Multiplicación - ECAS</title>
  <link rel="stylesheet" href="../style.css?v=<?=time();?>">
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
      <h2>🌿 Envío de Variedades a Multiplicación</h2>
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
  </header>

  <main class="container mt-4">
    <?php if (!empty($mensaje)): ?>
      <div class="alert alert-warning"><?= $mensaje ?></div>
    <?php endif; ?>

    <?php if (count($variedades) > 0): ?>
      <div id="formulario-asignacion" style="display:none;" class="mb-4">
        <h4>Asignar variedad a operador</h4>
        <form method="POST" class="border p-3">
          <input type="hidden" name="id_diseccion" id="id_diseccion">
          <input type="hidden" name="codigo_variedad" id="codigo_variedad">
          <input type="hidden" name="nombre_variedad" id="nombre_variedad">
          <input type="hidden" name="brotes_disponibles" id="brotes_disponibles">

          <div class="mb-3">
            <label>Variedad Seleccionada:</label>
            <input type="text" id="variedad_mostrada" class="form-control" readonly>
          </div>

          <div class="mb-3">
            <label>Brotes a asignar:</label>
            <input type="number" name="brotes_asignados" id="brotes_asignados" class="form-control" required min="1">
          </div>

          <div class="mb-3">
            <label>Fecha de asignación:</label>
            <input type="text" name="fecha_asignacion" class="form-control" value="<?= date('Y-m-d') ?>" readonly>
          </div>

          <div class="mb-3">
            <label>Asignar a operador:</label>
            <select name="operador_asignado" class="form-select" required>
              <option value="">-- Seleccionar operador --</option>
              <?php foreach ($operadores as $op): ?>
                <option value="<?= $op['ID_Operador'] ?>"><?= htmlspecialchars($op['NombreCompleto']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label>Observaciones (opcional):</label>
            <textarea name="observaciones" class="form-control"></textarea>
          </div>

          <button type="submit" name="asignar_variedad" class="btn btn-success">Confirmar Asignación</button>
        </form>
      </div>

      <div class="alert alert-success">
        Variedades con más de <?= $min_brotes_multiplicacion ?> brotes disponibles para enviar a multiplicación:
      </div>

      <table class="table table-bordered">
        <thead>
          <tr>
            <th>Código Variedad</th>
            <th>Nombre Variedad</th>
            <th>Brotes Disponibles</th>
            <th>Fecha de Última Disección</th>
            <th>Responsable</th>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($variedades as $v): ?>
            <tr>
              <td><?= htmlspecialchars($v['Codigo_Variedad']) ?></td>
              <td><?= htmlspecialchars($v['Nombre_Variedad']) ?></td>
              <td><strong><?= $v['Total_Brotes_Disponibles'] ?></strong></td>
              <td><?= htmlspecialchars($v['Ultima_Fecha']) ?></td>
              <td><?= htmlspecialchars($v['Nombre_Operador'] . " " . $v['ApellidoP_Operador'] . " " . $v['ApellidoM_Operador']) ?></td>
              <td>
                <button class="btn btn-primary btn-sm"
                        onclick="mostrarFormulario('<?= $v['ID_Diseccion'] ?>', '<?= $v['Codigo_Variedad'] ?>', '<?= $v['Nombre_Variedad'] ?>', '<?= $v['Total_Brotes_Disponibles'] ?>')">
                  Asignar
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="alert alert-warning">
        No hay variedades con suficientes brotes disponibles para enviar a multiplicación.
      </div>
    <?php endif; ?>
  </main>

  <footer class="text-center mt-5">
    <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
  </footer>
</div>

<script>
function mostrarFormulario(id, cod, nom, disponibles) {
  document.getElementById('formulario-asignacion').style.display = 'block';
  document.getElementById('id_diseccion').value = id;
  document.getElementById('codigo_variedad').value = cod;
  document.getElementById('nombre_variedad').value = nom;
  document.getElementById('variedad_mostrada').value = cod + ' - ' + nom;
  document.getElementById('brotes_disponibles').value = disponibles;
  document.getElementById('brotes_asignados').value = '';
  document.getElementById('brotes_asignados').max = disponibles;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>

<!-- Modal de advertencia de sesión -->
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
    document.getElementById('keepalive-btn').addEventListener('click', keepSessionAlive);
  }

  function keepSessionAlive() {
    fetch('../keepalive.php', { credentials: 'same-origin' })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'OK') {
          document.getElementById('session-warning')?.remove();
          START_TS   = Date.now();
          modalShown = false;
          clearTimeout(warningTimer);
          clearTimeout(expireTimer);
          scheduleTimers();
        }
      })
      .catch(() => alert('Error al mantener viva la sesión'));
  }

  function scheduleTimers() {
    const elapsed = Date.now() - START_TS;
    const warnAfter = SESSION_LIFETIME - WARNING_OFFSET;
    const expireAfter = SESSION_LIFETIME;

    warningTimer = setTimeout(showModal, Math.max(warnAfter - elapsed, 0));
    expireTimer  = setTimeout(() => {
      if (!modalShown) {
        showModal();
      } else {
        window.location.href = '/plantulas/login.php?mensaje=' + encodeURIComponent('Sesión caducada por inactividad');
      }
    }, Math.max(expireAfter - elapsed, 0));
  }
  scheduleTimers();
})();
</script>
</body>
</html>
