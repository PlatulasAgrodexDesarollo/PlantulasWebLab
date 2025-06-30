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

if ((int) $_SESSION['Rol'] !== 5) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Encargado General de Producción.</p>";
    exit;
}

// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();


// 1. Asignar una orden de lavado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_orden'], $_POST['id_operador'], $_POST['rol'])) {
    $id_orden = intval($_POST['id_orden']);
    $id_operador = intval($_POST['id_operador']);
    $rol = $_POST['rol'];

    // Obtener datos de la orden
    $stmt = $conn->prepare("
        SELECT ol.ID_Lote, l.ID_Variedad, ol.Fecha_Lavado, ol.Cantidad_Lavada
        FROM orden_tuppers_lavado ol
        INNER JOIN lotes l ON ol.ID_Lote = l.ID_Lote
        WHERE ol.ID_Orden = ?
    ");
    $stmt->bind_param("i", $id_orden);
    $stmt->execute();
    $res = $stmt->get_result();
    $orden = $res->fetch_assoc();

    if ($orden) {
        $id_lote = $orden['ID_Lote'];
        $id_variedad = $orden['ID_Variedad'];
        $fecha = $orden['Fecha_Lavado'];
        $cantidad = $orden['Cantidad_Lavada'];

        // Insertar en asignacion_lavado
        $insert = $conn->prepare("
            INSERT INTO asignacion_lavado (ID_Operador, ID_Variedad, Fecha, Rol, Cantidad_Tuppers)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insert->bind_param("iissi", $id_operador, $id_variedad, $fecha, $rol, $cantidad);

        if ($insert->execute()) {
            // Actualizar estado de la orden a "Asignado"
            $update = $conn->prepare("UPDATE orden_tuppers_lavado SET Estado = 'Asignado' WHERE ID_Orden = ?");
            $update->bind_param("i", $id_orden);
            $update->execute();

            // Registrar en movimientos_lote como "Asignación de Lavado"
            $movimiento = $conn->prepare("
                INSERT INTO movimientos_lote (ID_Lote, Fecha_Movimiento, Tipo_Movimiento, Cantidad_Tuppers, ID_Operador, Observaciones)
                VALUES (?, NOW(), 'Asignación de Lavado', ?, ?, 'Operador asignado para realizar lavado')
            ");
            $movimiento->bind_param("iii", $id_lote, $cantidad, $id_operador);
            $movimiento->execute();

            echo "<script>alert('✅ Asignación de lavado registrada correctamente.'); window.location.href='lavado_plantas.php';</script>";
            exit();
        } else {
            echo "<script>alert('❌ Error al registrar la asignación.');</script>";
        }
    } else {
        echo "<script>alert('❌ Error: Orden no encontrada.');</script>";
    }
}

// 2. Obtener operadores activos que NO sean administradores
$operadores = $conn->query("
    SELECT ID_Operador, CONCAT(Nombre, ' ', Apellido_P, ' ', Apellido_M) AS NombreCompleto 
    FROM operadores 
    WHERE Activo = 1 AND ID_Rol = 2
    ORDER BY Nombre ASC
");

// 3. Obtener órdenes pendientes
$ordenes = $conn->query("
    SELECT ol.ID_Orden, v.Nombre_Variedad, v.Especie, ol.Fecha_Lavado, ol.Cantidad_Lavada
    FROM orden_tuppers_lavado ol
    INNER JOIN lotes l ON ol.ID_Lote = l.ID_Lote
    INNER JOIN variedades v ON l.ID_Variedad = v.ID_Variedad
    WHERE ol.Estado = 'Pendiente'
    ORDER BY ol.Fecha_Creacion ASC
");
?>


<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Asignación Lavado de Plantas</title>
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
        <a class="navbar-brand" href="#">
          <img src="../logoplantulas.png" alt="Logo" width="130" height="124">
        </a>
        <div>
          <h2>Asignación de Lavado de Plantas</h2>
          <p>Registra los tuppers a lavar por operador.</p>
        </div>
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

    <main>
      <div class="section">
        <h2>🌿 Registrar Asignación de Lavado</h2>
        <form method="POST" class="form-doble-columna">
          <div class="row g-3">
            <div class="col-md-6">
              <label for="id_operador">Operador:</label>
              <select name="id_operador" id="id_operador" class="form-select" required>
                <option value="">-- Seleccionar Operador --</option>
                <?php while ($op = $operadores->fetch_assoc()): ?>
                  <option value="<?= $op['ID_Operador'] ?>"><?= $op['NombreCompleto'] ?></option>
                <?php endwhile; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label for="id_orden">Orden de Lavado (Pendiente):</label>
              <select name="id_orden" id="id_orden" class="form-select" required>
                <option value="">-- Seleccionar Orden --</option>
                <?php while ($orden = $ordenes->fetch_assoc()): ?>
                  <option value="<?= $orden['ID_Orden'] ?>">
                    <?= $orden['Nombre_Variedad'] ?> (<?= $orden['Especie'] ?>) - <?= $orden['Fecha_Lavado'] ?> - <?= $orden['Cantidad_Lavada'] ?> tuppers
                  </option>
                <?php endwhile; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label for="rol">Rol:</label>
              <select name="rol" id="rol" class="form-select" required>
                <option value="">-- Seleccionar Rol --</option>
                <option value="Supervisor">Supervisor</option>
                <option value="Lavador">Lavador</option>
              </select>
            </div>

            <div class="col-md-12 d-flex justify-content-center">
              <button type="submit" class="save-button">Registrar Asignación</button>
            </div>
          </div>
        </form>
      </div>
    </main>

    <footer>
      <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
    </footer>
  </div>

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
