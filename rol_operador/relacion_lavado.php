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

if ((int) $_SESSION['Rol'] !== 2) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Solo Operador.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// Consultar asignaciones
$sql_asignacion = "SELECT AL.ID, AL.ID_Variedad, AL.Fecha, V.Nombre_Variedad, AL.Rol, AL.Cantidad_Tuppers, AL.Estado_Final
                   FROM asignacion_lavado AL
                   JOIN variedades V ON AL.ID_Variedad = V.ID_Variedad
                   WHERE AL.ID_Operador = ? AND AL.Fecha = CURDATE()";
$stmt_asignacion = $conn->prepare($sql_asignacion);
$stmt_asignacion->bind_param("i", $ID_Operador);
$stmt_asignacion->execute();
$result_asignacion = $stmt_asignacion->get_result();
$asignaciones = $result_asignacion->fetch_all(MYSQLI_ASSOC);

// Avances registrados
$avances_realizados = [];
$sql_check_avances = "SELECT ID_Asignacion, SUM(Tuppers_Lavados) AS Tuppers_Lavados FROM reporte_lavado_parcial WHERE ID_Operador = ? AND DATE(Fecha) = CURDATE() GROUP BY ID_Asignacion";
$stmt_check = $conn->prepare($sql_check_avances);
$stmt_check->bind_param("i", $ID_Operador);
$stmt_check->execute();
$res_check = $stmt_check->get_result();
while ($row = $res_check->fetch_assoc()) {
    $avances_realizados[$row['ID_Asignacion']] = $row['Tuppers_Lavados'];
}

// Finales registrados
$finales_realizados = [];
$sql_check_finales = "SELECT ID_Asignacion, SUM(Tuppers_Lavados_Final) AS Tuppers_Final FROM reporte_lavado_final WHERE ID_Operador = ? AND DATE(Fecha) = CURDATE() GROUP BY ID_Asignacion";
$stmt_finales = $conn->prepare($sql_check_finales);
$stmt_finales->bind_param("i", $ID_Operador);
$stmt_finales->execute();
$res_finales = $stmt_finales->get_result();
while ($row = $res_finales->fetch_assoc()) {
   $finales_realizados[$row['ID_Asignacion']] = $row['Tuppers_Final'];
}

// Guardar avance o cierre
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["accion"])) {
    $accion = $_POST["accion"];
    $id_variedad = $_POST["id_variedad"];
    $id_asignacion = $_POST["id_asignacion"];
    $fecha_registro = (new DateTime('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s');

    // Obtener tuppers asignados para esta asignación
    $stmt_asignados = $conn->prepare("SELECT Cantidad_Tuppers FROM asignacion_lavado WHERE ID = ?");
    $stmt_asignados->bind_param("i", $id_asignacion);
    $stmt_asignados->execute();
    $res_asignados = $stmt_asignados->get_result()->fetch_assoc();
    $asignados = (int)$res_asignados['Cantidad_Tuppers'];

    if ($accion == "avance") {
        $tuppers_lavados = (int)$_POST["tuppers_lavados"];
        $observaciones = $_POST["observaciones"] ?? null;

        // Verificar que no haya otro avance
$stmt_check_avance = $conn->prepare("SELECT COUNT(*) AS total FROM reporte_lavado_parcial WHERE ID_Operador = ? AND ID_Asignacion = ? AND DATE(Fecha) = CURDATE()");
$stmt_check_avance->bind_param("ii", $ID_Operador, $id_asignacion);
        $stmt_check_avance->execute();
        $res_avance = $stmt_check_avance->get_result()->fetch_assoc();

        if ($res_avance['total'] > 0) {
            echo "<script>alert('❌ Ya registraste el avance para hoy. No puedes registrar otro.'); window.location.href='relacion_lavado.php';</script>";
            exit();
        }

        // Validar cantidad
        if ($tuppers_lavados < 1 || $tuppers_lavados > $asignados) {
            echo "<script>alert('❌ La cantidad de tuppers lavados debe ser mayor a 0 y no puede exceder los tuppers asignados ($asignados).'); window.location.href='relacion_lavado.php';</script>";
            exit();
        }

        // Insertar avance
        $stmt = $conn->prepare("INSERT INTO reporte_lavado_parcial (ID_Operador, ID_Asignacion, ID_Variedad, Fecha, Tuppers_Lavados, Observaciones) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisis", $ID_Operador, $id_asignacion, $id_variedad, $fecha_registro, $tuppers_lavados, $observaciones);
        $stmt->execute();

        echo "<script>alert('✅ Avance registrado correctamente.'); window.location.href='relacion_lavado.php';</script>";
        exit();
    }

    if ($accion == "final") {
        $tuppers_finales = (int)$_POST["tuppers_finales"];
        $observaciones_finales = $_POST["observaciones_finales"] ?? null;

        // Verificar que no haya otro final
$stmt_check_final = $conn->prepare("SELECT COUNT(*) AS total FROM reporte_lavado_final WHERE ID_Operador = ? AND ID_Asignacion = ? AND DATE(Fecha) = CURDATE()");
$stmt_check_final->bind_param("ii", $ID_Operador, $id_asignacion);
        $stmt_check_final->execute();
        $res_final = $stmt_check_final->get_result()->fetch_assoc();

        if ($res_final['total'] > 0) {
            echo "<script>alert('❌ Ya registraste el reporte final para hoy. No puedes registrar otro.'); window.location.href='relacion_lavado.php';</script>";
            exit();
        }

        // Obtener avance registrado (si existe)
$stmt_check_avance = $conn->prepare("SELECT Tuppers_Lavados FROM reporte_lavado_parcial WHERE ID_Operador = ? AND ID_Asignacion = ? AND DATE(Fecha) = CURDATE()");
$stmt_check_avance->bind_param("ii", $ID_Operador, $id_asignacion);
        $stmt_check_avance->execute();
        $res_avance = $stmt_check_avance->get_result()->fetch_assoc();
        $avance = (int)($res_avance['Tuppers_Lavados'] ?? 0);

        $total_lavados = $avance + $tuppers_finales;

        if ($total_lavados > $asignados) {
            echo "<script>alert('❌ El total de tuppers lavados (avance + final) excede los $asignados tuppers asignados. Verifica los datos.'); window.location.href='relacion_lavado.php';</script>";
            exit();
        }

        // Insertar final
        $stmt = $conn->prepare("INSERT INTO reporte_lavado_final (ID_Operador, ID_Asignacion, ID_Variedad, Fecha, Tuppers_Lavados_Final, Observaciones) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiisis", $ID_Operador, $id_asignacion, $id_variedad, $fecha_registro, $tuppers_finales, $observaciones_finales);
        $stmt->execute();

        // Marcar asignación como completada
        $stmt_update = $conn->prepare("UPDATE asignacion_lavado SET Estado_Final = 'Completada' WHERE ID = ?");
        $stmt_update->bind_param("i", $id_asignacion);
        $stmt_update->execute();

        echo "<script>alert('✅ Lavado final registrado correctamente.'); window.location.href='relacion_lavado.php';</script>";
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Relación de Lavado</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
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
      <a class="navbar-brand"><img src="../logoplantulas.png" alt="Logo" width="130" height="124"></a>
      <h2>RELACIÓN PARA CLASIFICACIÓN</h2>
    </div>
    <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_cultivo.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
  </header>

  <main class="container mt-4">
  <?php if (!empty($asignaciones)): ?>
      <h3 class="mb-4">🧽 Mis Asignaciones de Hoy</h3>
      <div class="carrusel-desinfecciones">
        <?php foreach ($asignaciones as $asignacion): ?>
          <?php
          $avance = $avances_realizados[$asignacion['ID']] ?? 0;
          $final = $finales_realizados[$asignacion['ID']] ?? 0;
          $total_lavados = $avance + $final;
          $restante = max(0, $asignacion['Cantidad_Tuppers'] - $total_lavados);
          ?>

          <?php if ($restante > 0): // Mostrar solo si hay tuppers restantes ?>
            <div class="tarjeta-desinf" onclick="mostrarFormulario(<?= $asignacion['ID'] ?>)" id="card-<?= $asignacion['ID'] ?>">
              <strong><?= htmlspecialchars($asignacion['Nombre_Variedad']) ?></strong><br>
              Tuppers asignados: <?= $asignacion['Cantidad_Tuppers'] ?><br>
              Tuppers clasificados avance: <?= $avance ?><br>
              Tuppers restantes: <?= $restante ?><br>
              Rol: <?= $asignacion['Rol'] ?><br>
              Fecha: <?= $asignacion['Fecha'] ?>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <?php foreach ($asignaciones as $asignacion): ?>
        <?php
        $avance = $avances_realizados[$asignacion['ID']] ?? 0;
        $final = $finales_realizados[$asignacion['ID']] ?? 0;
        $total_lavados = $avance + $final;
        $restante = max(0, $asignacion['Cantidad_Tuppers'] - $total_lavados);
        ?>
        <?php if ($restante > 0): // Mostrar formulario solo si hay tuppers restantes ?>
          <form method="POST" id="formulario-<?= $asignacion['ID'] ?>" class="formulario-siembra mt-4" style="display:none;">
            <input type="hidden" name="id_variedad" value="<?= $asignacion['ID_Variedad'] ?>">
            <input type="hidden" name="id_asignacion" value="<?= $asignacion['ID'] ?>">

            <h4 class="text-center mb-3">🌱 <?= htmlspecialchars($asignacion['Nombre_Variedad']) ?></h4>

            <?php if (($avance ?? 0) == 0 && !$asignacion['Estado_Final']): ?>
              <input type="hidden" name="accion" value="avance">
              <div class="mb-2">
  <label class="form-label small">🧼 Tuppers clasificados hasta ahora:</label>
  <input type="number" name="tuppers_lavados" min="0" max="<?= $asignacion['Cantidad_Tuppers'] ?>"
         class="form-control form-control-sm" required>
</div>

<div class="mb-3">
  <label class="form-label small">📝 Observaciones:</label>
  <textarea name="observaciones" class="form-control form-control-sm" rows="3"></textarea>
</div>
            <?php elseif (!$asignacion['Estado_Final']): ?>
              <input type="hidden" name="accion" value="final">
              <div class="mb-2">
  <label class="form-label small">✅ Tuppers clasificados al final:</label>
  <input type="number" name="tuppers_finales" min="0" max="<?= $restante ?>"
         class="form-control form-control-sm" required>
</div>

<div class="mb-3">
  <label class="form-label small">📝 Observaciones Finales:</label>
  <textarea name="observaciones_finales" class="form-control form-control-sm" rows="3"></textarea>
</div>

            <?php else: ?>
              <div class="alert alert-success text-center">Esta asignación está completada ✅</div>
            <?php endif; ?>

            <?php if (!$asignacion['Estado_Final']): ?>
            <div class="text-center mt-3">
              <button type="submit" class="save-button">Guardar Registro</button>
            </div>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      <?php endforeach; ?>
  <?php else: ?>
    <div class="alert alert-warning text-center">
  ⚠️ No tienes asignaciones de lavado registradas para el día de hoy. Si crees que es un error, contacta al responsable de producción.
</div>
  <?php endif; ?>
</main>

  <footer >
    <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
  </footer>
</div>

<script>
function mostrarFormulario(id) {
  document.querySelectorAll('.formulario-siembra').forEach(f => f.style.display = 'none');
  document.getElementById('formulario-' + id).style.display = 'block';
  window.scrollTo({ top: document.getElementById('formulario-' + id).offsetTop - 100, behavior: 'smooth' });
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
