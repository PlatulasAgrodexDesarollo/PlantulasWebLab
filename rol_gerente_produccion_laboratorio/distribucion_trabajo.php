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

if ((int) $_SESSION['Rol'] !== 6) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Gerente de Producción de Laboratorio.</p>";
    exit;
}

// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// Procesar asignación desde proyecciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_operador'], $_POST['id_proyeccion'], $_POST['rol'], $_POST['cantidad'])) {
    $id_operador   = (int) $_POST['id_operador'];
    $id_proyeccion = (int) $_POST['id_proyeccion'];
    $rol           = $_POST['rol'];
    $cantidad      = (int) $_POST['cantidad'];
    $fecha         = date('Y-m-d');

    // Traer info de la proyección
$stmt = $conn->prepare("
    SELECT ID_Etapa, Etapa, Tuppers_Proyectados, IFNULL(Tuppers_Asignados, 0) AS Tuppers_Asignados
    FROM proyecciones_lavado
    WHERE ID_Proyeccion = ?
");
    $stmt->bind_param('i', $id_proyeccion);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();

    if ($info) {
        $disponibles = $info['Tuppers_Proyectados'] - $info['Tuppers_Asignados'];
        if ($cantidad >= 1 && $cantidad <= $disponibles) {
            // Insertar asignación
$stmt_insert = $conn->prepare("
    INSERT INTO asignacion_lavado (ID_Operador, ID_Proyeccion, Fecha_Asignacion, Tuppers_Asignados, Rol)
    VALUES (?, ?, ?, ?, ?)
");
$stmt_insert->bind_param('iisis', $id_operador, $id_proyeccion, $fecha, $cantidad, $rol);
            if ($stmt_insert->execute()) {
$nuevo_asignado = $info['Tuppers_Asignados'] + $cantidad;

// Actualizar cantidad de tuppers asignados y estado
// Si ya se asignaron todos los tuppers, actualiza también el estado
if ($nuevo_asignado >= $info['Tuppers_Proyectados']) {
    $stmt_update = $conn->prepare("
        UPDATE proyecciones_lavado
        SET Tuppers_Asignados = ?, Estado_Flujo = 'asignado_lavado'
        WHERE ID_Proyeccion = ?
    ");
} else {
    $stmt_update = $conn->prepare("
        UPDATE proyecciones_lavado
        SET Tuppers_Asignados = ?
        WHERE ID_Proyeccion = ?
    ");
}
$stmt_update->bind_param('ii', $nuevo_asignado, $id_proyeccion);
$stmt_update->execute();

                echo "<script>
                        alert('✅ Asignación registrada correctamente.');
                        window.location.href='distribucion_trabajo.php';
                      </script>";
                exit;
            } else {
                echo "<script>alert('❌ Error al registrar la asignación.');</script>";
            }
        } else {
            echo "<script>alert('❌ La cantidad debe ser entre 1 y {$disponibles}.');</script>";
        }
    }
}

// Obtener operadores activos
$operadores = $conn->query("
    SELECT ID_Operador,
           CONCAT(Nombre, ' ', Apellido_P, ' ', Apellido_M) AS NombreCompleto
    FROM operadores
    WHERE Activo = 1 AND ID_Rol = 2
    ORDER BY Nombre ASC
");

// Obtener cajas disponibles para asignación
$proyecciones = $conn->query("
  SELECT 
    p.ID_Proyeccion,
    v.Codigo_Variedad,
    v.Nombre_Variedad,
    v.Color,
    p.Tuppers_Proyectados,
    p.Tuppers_Asignados,
    (p.Tuppers_Proyectados - IFNULL(p.Tuppers_Asignados, 0)) AS Tuppers_Disponibles,
    CASE 
      WHEN p.Etapa = 'multiplicacion' THEN 'Multiplicación'
      WHEN p.Etapa = 'enraizamiento' THEN 'Enraizamiento'
      ELSE p.Etapa
    END AS Etapa,
    DATE(IFNULL(p.Fecha_Verificacion, p.Fecha_Creacion)) AS Fecha
  FROM proyecciones_lavado p
  JOIN (
    SELECT ID_Variedad, ID_Multiplicacion AS ID, 'multiplicacion' AS Etapa FROM multiplicacion
    UNION ALL
    SELECT ID_Variedad, ID_Enraizamiento AS ID, 'enraizamiento' AS Etapa FROM enraizamiento
  ) AS etapas ON p.Etapa = etapas.Etapa AND p.ID_Etapa = etapas.ID
  JOIN variedades v ON etapas.ID_Variedad = v.ID_Variedad
WHERE p.Estado_Flujo = 'acomodados'
  AND (p.Tuppers_Proyectados - IFNULL(p.Tuppers_Asignados, 0)) > 0
  ORDER BY p.Fecha_Creacion ASC
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Distribución de Trabajo - Clasificación de Plantas</title>
  <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS          = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body class="d-flex flex-column min-vh-100">
  <div class="flex-grow-1">
    <header>
      <div class="encabezado d-flex flex-column flex-md-row align-items-center text-center text-md-start">
        <a class="navbar-brand me-3" href="#">
          <img src="../logoplantulas.png" alt="Logo" width="130" height="124">
        </a>
        <h2>Distribución de Trabajo - Lavado de Plantas</h2>
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
      <!-- Cajas disponibles -->
      <div class="section mb-5">
        <h3 class="text-center mb-4">📋 Cajas Disponibles para Clasificación</h3>
<?php if ($proyecciones->num_rows > 0): ?>
  <div class="table-responsive">
    <table class="table table-bordered table-hover table-striped align-middle">
      <thead class="table-dark">
        <tr>
          <th>ID Proyección</th>
          <th>Variedad</th>
          <th>Color</th>
          <th>Etapa</th>
          <th>Fecha</th>
          <th>Tuppers Disponibles</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($row = $proyecciones->fetch_assoc()): ?>
          <tr>
            <td><?= $row['ID_Proyeccion'] ?></td>
            <td><?= htmlspecialchars($row['Codigo_Variedad'].' – '.$row['Nombre_Variedad']) ?></td>
            <td><?= htmlspecialchars($row['Color']) ?></td>
            <td><?= $row['Etapa'] ?></td>
            <td><?= $row['Fecha'] ?></td>
            <td><?= $row['Tuppers_Disponibles'] ?></td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
<?php else: ?>
  <div class="alert alert-warning text-center">
    <strong>🔔 No hay tuppers disponibles para asignación actualmente.</strong>
  </div>
<?php endif; ?>
      </div>

      <!-- Formulario sin fecha -->
      <div class="section">
        <h3 class="text-center mb-4">🌱 Registrar Asignación de Trabajo</h3>
        <form method="POST" class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Operador:</label>
            <select name="id_operador" class="form-select" required>
              <option value="">-- Seleccionar Operador --</option>
              <?php foreach ($operadores as $op): ?>
                <option value="<?= $op['ID_Operador'] ?>">
                  <?= htmlspecialchars($op['NombreCompleto']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
<div class="col-md-6">
  <label class="form-label">Proyección:</label>
  <select name="id_proyeccion" id="proyeccion-select" class="form-select" required>
    <option value="">-- Seleccionar Proyección --</option>
    <?php
      $proyecciones_select = $conn->query("
        SELECT 
          p.ID_Proyeccion,
          v.Nombre_Variedad,
          p.Tuppers_Proyectados,
          p.Tuppers_Asignados
        FROM proyecciones_lavado p
        JOIN (
          SELECT ID_Variedad, ID_Multiplicacion AS ID, 'multiplicacion' AS Etapa FROM multiplicacion
          UNION ALL
          SELECT ID_Variedad, ID_Enraizamiento AS ID, 'enraizamiento' AS Etapa FROM enraizamiento
        ) AS etapas ON p.Etapa = etapas.Etapa AND p.ID_Etapa = etapas.ID
        JOIN variedades v ON etapas.ID_Variedad = v.ID_Variedad
WHERE p.Estado_Flujo = 'acomodados'
  AND (p.Tuppers_Proyectados - IFNULL(p.Tuppers_Asignados, 0)) > 0
      ");
      while ($p = $proyecciones_select->fetch_assoc()):
        $disponibles = $p['Tuppers_Proyectados'] - $p['Tuppers_Asignados'];
    ?>
      <option
        value="<?= $p['ID_Proyeccion'] ?>"
        data-disponibles="<?= $disponibles ?>"
      >
        <?= htmlspecialchars($p['Nombre_Variedad']) ?> – <?= $disponibles ?> tuppers disponibles
      </option>
    <?php endwhile; ?>
  </select>
</div>
          <div class="col-md-4">
            <label class="form-label">Rol:</label>
            <select name="rol" class="form-select" required>
              <option value="">-- Seleccionar Rol --</option>
              <option value="Supervisor">Supervisor</option>
              <option value="Clasificador">Clasificador</option>
            </select>
          </div>
<div class="col-md-4">
  <label class="form-label">Cantidad de Tuppers:</label>
  <input type="number" name="cantidad" id="cantidad-input" class="form-control" min="1" required>
</div>

<div class="col-md-4">
  <label class="form-label">Brotes (estimados):</label>
  <input type="text" id="brotes-output" class="form-control" value="0" readonly>
</div>
          <div class="col-12 d-flex justify-content-center">
  <button type="submit" class="btn btn-success">Registrar Asignación</button>
</div>
        </form>
      </div>
    </main>

    <footer class="text-center mt-4" style="background-color: #45814d; color: white;">
      <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
    </footer>
  </div>

      <!--Validaciones en vivo-->
<script>
const cantidadInput = document.getElementById('cantidad-input');
const brotesOutput = document.getElementById('brotes-output');
const proyeccionSelect = document.getElementById('proyeccion-select');

// Actualizar brotes en tiempo real
function actualizarBrotes() {
  const cantidad = parseInt(cantidadInput.value) || 0;
  brotesOutput.value = cantidad * 12;
}

// Establecer máximo dinámico cuando cambia la proyección
proyeccionSelect.addEventListener('change', function () {
  const maxTuppers = parseInt(this.selectedOptions[0].dataset.disponibles || 0);
  cantidadInput.max = maxTuppers;
  cantidadInput.value = '';
  brotesOutput.value = 0;
});

// Validar en vivo que no se pase del máximo
cantidadInput.addEventListener('input', function () {
  const max = parseInt(this.max);
  let val = parseInt(this.value);
  if (val > max) {
    this.value = max;
    val = max;
  }
  actualizarBrotes();
});
</script>

  <script>
document.getElementById('proyeccion-select').addEventListener('change', function () {
  const cantidadInput = document.getElementById('cantidad-input');
  const maxTuppers = this.selectedOptions[0].dataset.disponibles || 0;
  cantidadInput.max = maxTuppers;
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
