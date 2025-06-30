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

if ((int) $_SESSION['Rol'] !== 6) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Gerente de Producción de Laboratorio.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

$mensaje = "";

// Obtener lista de operadores para los select
$operadores = mysqli_query($conn, "SELECT ID_Operador, Nombre FROM operadores WHERE Activo = 1");

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $fecha_plan = $_POST['fecha_plan'];
  $especie = $_POST['especie'];
  $variedad = $_POST['variedad'];
  $cantidad = $_POST['cantidad'];
  $fecha_siembra = $_POST['fecha_siembra'];
  $etapa = $_POST['etapa'];
  $tasa = $_POST['tasa'];
  $dias = $_POST['dias'];
  $responsable_ejecucion = $_POST['responsable_ejecucion'];
  $responsable_supervision = $_POST['responsable_supervision'];
  $responsable_medio = $_POST['responsable_medio'];
  $responsable_acomodo = $_POST['responsable_acomodo'];
  $observaciones = $_POST['observaciones'];

  $stmt = $conn->prepare("INSERT INTO planificacion_produccion 
    (Fecha_Planificacion, Especie, Variedad, Cantidad_Proyectada, Fecha_Estimada_Siembra, Etapa_Destino,
    Tasa_Multiplicacion_Promedio, Dias_Entre_Siembra, Responsable_Ejecucion, Responsable_Supervision,
    Responsable_MedioNutritivo, Responsable_Acomodo, Observaciones, ID_Operador)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
  
  $stmt->bind_param("ssssssdiiiiiis",
    $fecha_plan, $especie, $variedad, $cantidad, $fecha_siembra, $etapa,
    $tasa, $dias, $responsable_ejecucion, $responsable_supervision,
    $responsable_medio, $responsable_acomodo, $observaciones, $_SESSION['ID_Operador']);

  if ($stmt->execute()) {
    $mensaje = "✅ Planificación registrada correctamente.";
  } else {
    $mensaje = "❌ Error al guardar la planificación.";
  }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Planificación de Producción</title>
  <link rel="stylesheet" href="../style.css" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous" />
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
        <a class="navbar-brand" href="#">
          <img src="../logoplantulas.png" alt="Logo" width="130" height="124" />
        </a>
        <div>
          <h2>📋 Planificación de Producción</h2>
        </div>
      </div>

      <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_gpl.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
    </header>

    <main class="container mt-4">
      <?php if (!empty($mensaje)) : ?>
        <p style="text-align:center; color:<?= strpos($mensaje, '✅') !== false ? 'green' : 'red' ?>;">
          <?= $mensaje ?>
        </p>
      <?php endif; ?>

      <form method="POST" class="row g-3">
        <div class="col-md-6">
          <label for="fecha_plan" class="form-label">Fecha de Planificación:</label>
          <input type="date" name="fecha_plan" class="form-control" required>
        </div>

        <div class="col-md-6">
          <label for="especie" class="form-label">Especie:</label>
          <input type="text" name="especie" class="form-control" required>
        </div>

        <div class="col-md-6">
          <label for="variedad" class="form-label">Variedad:</label>
          <input type="text" name="variedad" class="form-control" required>
        </div>

        <div class="col-md-6">
          <label for="cantidad" class="form-label">Cantidad Proyectada:</label>
          <input type="number" name="cantidad" class="form-control" required>
        </div>

        <div class="col-md-6">
          <label for="fecha_siembra" class="form-label">Fecha Estimada de Siembra:</label>
          <input type="date" name="fecha_siembra" class="form-control">
        </div>

        <div class="col-md-6">
          <label for="etapa" class="form-label">Etapa Destino:</label>
          <select name="etapa" class="form-select" required>
            <option value="Multiplicación">Multiplicación</option>
            <option value="Enraizamiento">Enraizamiento</option>
          </select>
        </div>

        <div class="col-md-6">
          <label for="tasa" class="form-label">Tasa de Multiplicación Promedio:</label>
          <input type="number" step="0.01" name="tasa" class="form-control">
        </div>

        <div class="col-md-6">
          <label for="dias" class="form-label">Días entre Resiembra:</label>
          <input type="number" name="dias" class="form-control" value="30">
        </div>

        <?php
        // Helper para crear selects de responsables
        function crearSelectOperador($name, $label, $operadores) {
          echo "<div class='col-md-6'>";
          echo "<label class='form-label'>$label:</label>";
          echo "<select name='$name' class='form-select'>";
          echo "<option value=''>-- Seleccionar --</option>";
          mysqli_data_seek($operadores, 0);
          while ($op = mysqli_fetch_assoc($operadores)) {
            echo "<option value='{$op['ID_Operador']}'>{$op['Nombre']}</option>";
          }
          echo "</select></div>";
        }

        crearSelectOperador('responsable_ejecucion', 'Responsable de Ejecución', $operadores);
        crearSelectOperador('responsable_supervision', 'Responsable de Supervisión', $operadores);
        crearSelectOperador('responsable_medio', 'Responsable de Medio Nutritivo', $operadores);
        crearSelectOperador('responsable_acomodo', 'Responsable de Acomodo de Planta', $operadores);
        ?>

        <div class="col-12">
          <label for="observaciones" class="form-label">Observaciones:</label>
          <textarea name="observaciones" class="form-control" rows="3"></textarea>
        </div>

        <div class="col-12">
          <button type="submit" class="btn btn-success">Guardar Planificación</button>
        </div>
      </form>
    </main>

    <footer class="mt-4">
      <p class="text-center">&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
    </footer>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

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
