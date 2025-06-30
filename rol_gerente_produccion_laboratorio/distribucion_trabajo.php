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

// Procesar asignación de lavado
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['id_operador'], $_POST['id_preparacion'], $_POST['rol'], $_POST['cantidad'])
) {
    $id_operador    = intval($_POST['id_operador']);
    $id_preparacion = intval($_POST['id_preparacion']);
    $fecha          = date('Y-m-d');      // Fecha automática
    $rol            = $_POST['rol'];
    $cantidad       = intval($_POST['cantidad']);

    // Validamos mínimo 1
    if ($id_operador && $id_preparacion && $rol && $cantidad >= 1) {
        // Buscar datos de la preparación
        $stmt_info = $conn->prepare("
            SELECT pc.ID_Orden, pc.Tuppers_Buenos, l.ID_Variedad
            FROM preparacion_cajas pc
            INNER JOIN orden_tuppers_lavado otl ON pc.ID_Orden = otl.ID_Orden
            INNER JOIN lotes l ON otl.ID_Lote = l.ID_Lote
            WHERE pc.ID_Preparacion = ?
        ");
        $stmt_info->bind_param("i", $id_preparacion);
        $stmt_info->execute();
        $info = $stmt_info->get_result()->fetch_assoc();

        if ($info) {
            $id_orden       = $info['ID_Orden'];
            $tuppers_buenos = $info['Tuppers_Buenos'];
            $id_variedad    = $info['ID_Variedad'];

            // Validamos también que no supere los tuppers buenos
            if ($cantidad <= $tuppers_buenos) {
                // Insertar asignación
                $stmt_asignar = $conn->prepare("
                    INSERT INTO asignacion_lavado
                    (ID_Operador, ID_Variedad, ID_Preparacion, Fecha, Rol, Cantidad_Tuppers)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt_asignar->bind_param(
                    "iiissi",
                    $id_operador,
                    $id_variedad,
                    $id_preparacion,
                    $fecha,
                    $rol,
                    $cantidad
                );

                if ($stmt_asignar->execute()) {
                    // Actualizar cantidad de tuppers en la caja
                    $nuevo_total = $tuppers_buenos - $cantidad;
                    $stmt_update_caja = $conn->prepare("
                        UPDATE preparacion_cajas
                        SET Tuppers_Buenos = ?
                        WHERE ID_Preparacion = ?
                    ");
                    $stmt_update_caja->bind_param("ii", $nuevo_total, $id_preparacion);
                    $stmt_update_caja->execute();

                    // Si ya no quedan tuppers, actualizar el estado de la orden
                    if ($nuevo_total <= 0) {
                        $stmt_update_orden = $conn->prepare("
                            UPDATE orden_tuppers_lavado
                            SET Estado = 'En Lavado'
                            WHERE ID_Orden = ?
                        ");
                        $stmt_update_orden->bind_param("i", $id_orden);
                        $stmt_update_orden->execute();
                    }

                    echo "<script>
                            alert('✅ Asignación registrada correctamente.');
                            window.location.href='distribucion_trabajo.php';
                          </script>";
                    exit();
                } else {
                    echo "<script>alert('❌ Error al registrar la asignación.');</script>";
                }
            } else {
                // Mensaje si excede el máximo disponible
                echo "<script>
                        alert('❌ La cantidad debe ser al menos 1 y como máximo {$tuppers_buenos}.');
                      </script>";
            }
        }
    } else {
        echo "<script>alert('❌ Todos los campos son obligatorios y la cantidad debe ser ≥ 1.');</script>";
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
$cajas = $conn->query("
    SELECT 
        pc.ID_Preparacion,
        v.Codigo_Variedad,
        v.Nombre_Variedad,
        pc.Tuppers_Buenos,
        pc.Fecha_Registro AS Fecha_Ingreso,
        CASE
          WHEN l.ID_Etapa = 2 THEN 'Multiplicación'
          WHEN l.ID_Etapa = 3 THEN 'Enraizamiento'
          ELSE 'Otra'
        END AS Etapa_Origen,
        CONCAT(o.Nombre, ' ', o.Apellido_P, ' ', o.Apellido_M) AS Responsable
    FROM preparacion_cajas pc
    INNER JOIN orden_tuppers_lavado otl ON pc.ID_Orden = otl.ID_Orden
    INNER JOIN lotes l ON otl.ID_Lote = l.ID_Lote
    INNER JOIN variedades v ON l.ID_Variedad = v.ID_Variedad
    INNER JOIN operadores o ON l.ID_Operador = o.ID_Operador
    WHERE otl.Estado = 'Caja Preparada'
      AND pc.Tuppers_Buenos > 0
    ORDER BY pc.Fecha_Registro ASC
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
        <?php if ($cajas->num_rows > 0): ?>
          <div class="table-responsive">
            <table class="table table-bordered table-hover table-striped align-middle">
              <thead class="table-dark">
                <tr>
                  <th>ID Caja</th>
                  <th>Código Variedad</th>
                  <th>Nombre Variedad</th>
                  <th>Tuppers Buenos</th>
                  <th>Fecha Ingreso</th>
                  <th>Etapa Origen</th>
                  <th>Responsable</th>
                </tr>
              </thead>
              <tbody>
                <?php while ($caja = $cajas->fetch_assoc()): ?>
                  <tr>
                    <td data-label="ID Caja"><?= $caja['ID_Preparacion'] ?></td>
<td data-label="Código Variedad"><?= htmlspecialchars($caja['Codigo_Variedad']) ?></td>
<td data-label="Nombre Variedad"><?= htmlspecialchars($caja['Nombre_Variedad']) ?></td>
<td data-label="Tuppers Buenos"><?= $caja['Tuppers_Buenos'] ?></td>
<td data-label="Fecha Ingreso"><?= htmlspecialchars($caja['Fecha_Ingreso']) ?></td>
<td data-label="Etapa Origen"><?= htmlspecialchars($caja['Etapa_Origen']) ?></td>
<td data-label="Responsable"><?= htmlspecialchars($caja['Responsable']) ?></td>
                  </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="alert alert-warning text-center">
            <strong>🔔 No hay cajas preparadas disponibles actualmente.</strong>
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
            <label class="form-label">Caja (Preparación):</label>
            <select
  name="id_preparacion"
  id="caja-select"
  class="form-select"
  required
>
  <option value="">-- Seleccionar Caja --</option>
  <?php
    $cajas_select = $conn->query("
      SELECT pc.ID_Preparacion, v.Nombre_Variedad, pc.Tuppers_Buenos
      FROM preparacion_cajas pc
      INNER JOIN orden_tuppers_lavado otl ON pc.ID_Orden = otl.ID_Orden
      INNER JOIN lotes l ON otl.ID_Lote = l.ID_Lote
      INNER JOIN variedades v ON l.ID_Variedad = v.ID_Variedad
      WHERE otl.Estado = 'Caja Preparada' AND pc.Tuppers_Buenos > 0
    ");
    while ($caja = $cajas_select->fetch_assoc()):
  ?>
    <option
      value="<?= $caja['ID_Preparacion'] ?>"
      data-tuppers="<?= $caja['Tuppers_Buenos'] ?>"
    >
      <?= htmlspecialchars($caja['Nombre_Variedad']) ?> –
      <?= $caja['Tuppers_Buenos'] ?> tuppers
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
            <input type="number" name="cantidad" id="cantidad-input" class="form-control" min="1" max="" required>
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

  <script>
  // Ajusta el máximo en el campo "cantidad" según la caja seleccionada
  document.getElementById('caja-select').addEventListener('change', function() {
    const cantidadInput = document.getElementById('cantidad-input');
    const maxTuppers   = this.selectedOptions[0].dataset.tuppers || 0;
    cantidadInput.max  = maxTuppers;
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
