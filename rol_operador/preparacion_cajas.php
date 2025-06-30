<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Validar sesión y rol
require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

// Definir la zona horaria a México (CDMX)
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_orden'])) {
    $id_orden = intval($_POST['id_orden']);
    $tuppers_buenos = intval($_POST['tuppers_buenos']);
    $tuppers_infectados = intval($_POST['tuppers_infectados']);
    $observaciones = trim($_POST['observaciones'] ?? '');

    // 🔒 Validaciones antes de insertar

    // 1️⃣ Obtener la cantidad asignada (Cantidad_Lavada) de la orden
    $stmt_cantidad = $conn->prepare("SELECT Cantidad_Lavada FROM orden_tuppers_lavado WHERE ID_Orden = ?");
    $stmt_cantidad->bind_param("i", $id_orden);
    $stmt_cantidad->execute();
    $res_cantidad = $stmt_cantidad->get_result();
    $datos_orden = $res_cantidad->fetch_assoc();

    if (!$datos_orden) {
        echo "<script>alert('❌ La orden no existe.'); window.history.back();</script>";
        exit;
    }

    $cantidad_asignada = (int)$datos_orden['Cantidad_Lavada'];

    // 2️⃣ Validar tuppers buenos no excedan la cantidad asignada
    if ($tuppers_buenos > $cantidad_asignada) {
        echo "<script>alert('❌ La cantidad de tuppers en buen estado no puede ser mayor que la cantidad asignada: {$cantidad_asignada}.'); window.history.back();</script>";
        exit;
    }

    // 3️⃣ Validar suma de tuppers buenos + infectados no exceda la cantidad asignada
    $total = $tuppers_buenos + $tuppers_infectados;
    if ($total > $cantidad_asignada) {
        echo "<script>alert('❌ La suma de tuppers en buen estado e infectados no puede superar la cantidad asignada: {$cantidad_asignada}.'); window.history.back();</script>";
        exit;
    }

    // 4️⃣ Sanitizar observaciones
    $observaciones = strip_tags($observaciones);
    $observaciones = htmlspecialchars($observaciones);

$fecha_registro = (new DateTime('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s');

    // 1. Insertar en preparacion_cajas
    $query_registro = "INSERT INTO preparacion_cajas 
                       (ID_Orden, ID_Operador, Tuppers_Buenos, Tuppers_Infectados, Observaciones, Fecha_Registro)
                       VALUES (?, ?, ?, ?, ?, ?)";
    $stmt_registro = $conn->prepare($query_registro);
    $stmt_registro->bind_param("iiiiss", $id_orden, $ID_Operador, $tuppers_buenos, $tuppers_infectados, $observaciones, $fecha_registro);

    if ($stmt_registro->execute()) {

        // 2. Actualizar estado de orden
        $update_estado = $conn->prepare("UPDATE orden_tuppers_lavado SET Estado = 'Caja Preparada' WHERE ID_Orden = ?");
        $update_estado->bind_param("i", $id_orden);
        $update_estado->execute();

        // 3. Obtener ID_Lote y ID_Etapa para actualizar el lote
        $consulta_lote = $conn->prepare("
            SELECT l.ID_Lote, l.ID_Etapa
            FROM orden_tuppers_lavado otl
            INNER JOIN lotes l ON otl.ID_Lote = l.ID_Lote
            WHERE otl.ID_Orden = ?
        ");
        $consulta_lote->bind_param("i", $id_orden);
        $consulta_lote->execute();
        $res_lote = $consulta_lote->get_result();
        $datos_lote = $res_lote->fetch_assoc();

        if ($datos_lote) {
            $id_lote = $datos_lote['ID_Lote'];
            $id_etapa = $datos_lote['ID_Etapa'];
            $total_saliente = $tuppers_buenos + $tuppers_infectados;

            // 4. Actualizar tabla de Multiplicacion o Enraizamiento
            if ($id_etapa == 2) {
                $update_lote = $conn->prepare("
                    UPDATE multiplicacion 
                    SET Tuppers_Llenos = Tuppers_Llenos - ? 
                    WHERE ID_Lote = ? AND Tuppers_Llenos >= ?
                ");
            } elseif ($id_etapa == 3) {
                $update_lote = $conn->prepare("
                    UPDATE enraizamiento 
                    SET Tuppers_Llenos = Tuppers_Llenos - ? 
                    WHERE ID_Lote = ? AND Tuppers_Llenos >= ?
                ");
            }
            if (isset($update_lote)) {
                $update_lote->bind_param("iii", $total_saliente, $id_lote, $total_saliente);
                $update_lote->execute();
            }

            // 5. Insertar movimiento en movimientos_lote
            $insert_mov = $conn->prepare("
                INSERT INTO movimientos_lote 
                (ID_Lote, Fecha_Movimiento, Tipo_Movimiento, Cantidad_Tuppers, ID_Operador, Observaciones)
                VALUES (?, NOW(), 'Salida a Lavado', ?, ?, 'Preparación de Cajas - Salida de Tuppers')
            ");
            $insert_mov->bind_param("iii", $id_lote, $total_saliente, $ID_Operador);
            $insert_mov->execute();
        }

        // 6. Insertar en estado_tuppers si hubo infectados
        if ($tuppers_infectados > 0) {
            $motivo = "Contaminación";
            $estado = "Infectado";
            $etapa = "Preparación de Cajas";
            $observaciones_desecho = "Se detectaron $tuppers_infectados tuppers contaminados durante la preparación.";

            $insert_estado = $conn->prepare("
                INSERT INTO estado_tuppers 
                (ID_Tupper, Fecha_Revision, Estado, Desechar, Motivo_Desecho, Etapa_Desecho, Observaciones, ID_Operador_Produccion)
                VALUES (NULL, NOW(), ?, 1, ?, ?, ?, ?)
            ");
            $insert_estado->bind_param("ssssi", $estado, $motivo, $etapa, $observaciones_desecho, $ID_Operador);
            $insert_estado->execute();
        }

        echo "<script>alert('✅ Preparación de caja registrada exitosamente.'); window.location.href='preparacion_cajas.php';</script>";
        exit();
    } else {
        echo "<script>alert('❌ Error al registrar la preparación de la caja.');</script>";
    }
}

// Consulta de órdenes asignadas
$query = "
    SELECT otl.ID_Orden, v.Nombre_Variedad, v.Especie, v.Codigo_Variedad, otl.Cantidad_Lavada, otl.Fecha_Lavado
    FROM responsables_cajas rc
    INNER JOIN orden_tuppers_lavado otl ON rc.ID_Orden = otl.ID_Orden
    INNER JOIN lotes l ON otl.ID_Lote = l.ID_Lote
    INNER JOIN variedades v ON l.ID_Variedad = v.ID_Variedad
    WHERE rc.ID_Operador = ? AND otl.Estado = 'Pendiente'
    ORDER BY otl.Fecha_Lavado ASC
";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $ID_Operador);
$stmt->execute();
$resultado = $stmt->get_result();
?>



<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Preparación de Cajas Negras</title>
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
            <h2>📦 Preparación de Cajas Negras</h2>
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
    <h3 class="mb-4 text-center">📦 Órdenes Asignadas para Preparación</h3>

    <?php if ($resultado->num_rows > 0): ?>
<div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
  <?php while ($orden = $resultado->fetch_assoc()): ?>
    <div class="col">
      <div class="card h-100 shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-2"><?= htmlspecialchars($orden['Nombre_Variedad']) ?></h5>
          <p class="mb-1"><strong>Fecha de Lavado:</strong> <?= htmlspecialchars($orden['Fecha_Lavado']) ?></p>
          <p class="mb-1"><strong>Código:</strong> <?= htmlspecialchars($orden['Codigo_Variedad']) ?></p>
          <p class="mb-1"><strong>Especie:</strong> <?= htmlspecialchars($orden['Especie']) ?></p>
          <p class="mb-2"><strong>Cantidad Asignada:</strong> <?= htmlspecialchars($orden['Cantidad_Lavada']) ?></p>
          <button class="btn btn-primary btn-sm w-100" onclick="prepararCaja(<?= $orden['ID_Orden'] ?>, '<?= htmlspecialchars($orden['Nombre_Variedad']) ?>', <?= $orden['Cantidad_Lavada'] ?>)">
            Preparar Caja
          </button>
        </div>
      </div>
    </div>
  <?php endwhile; ?>
</div>

        <div id="formularioPreparacion" style="display: none;" class="mt-5 section">
            <h4 class="text-center mb-4">📦 Preparar Caja para: <span id="variedadSeleccionada"></span></h4>
            <form method="POST" class="row g-3 align-items-end">
                <input type="hidden" name="id_orden" id="id_orden">

                <div class="col-md-6">
                    <label class="form-label">Cantidad de Tuppers en Buen Estado:</label>
                    <input type="number" name="tuppers_buenos" id="tuppers_buenos" class="form-control" required min="0">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Cantidad de Tuppers Infectados:</label>
                    <input type="number" name="tuppers_infectados" id="tuppers_infectados" class="form-control" required min="0">
                </div>

                <div class="col-md-12">
                    <label class="form-label">Observaciones (opcional):</label>
                    <textarea name="observaciones" class="form-control" rows="3" placeholder="Escribe si observaste algo relevante..."></textarea>
                </div>

                <div class="col-12 text-center">
                    <button type="submit" class="save-button">Registrar Preparación</button>
                </div>
            </form>
        </div>

    <?php else: ?>
        <div class="alert alert-warning text-center">
            <strong>🔔 No tienes órdenes asignadas para preparar cajas por ahora.</strong>
        </div>
    <?php endif; ?>
</main>


    <footer>
        <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
    </footer>
</div>

<script>
function prepararCaja(idOrden, variedad, cantidad) {
    document.getElementById('formularioPreparacion').style.display = 'block';
    document.getElementById('id_orden').value = idOrden;
    document.getElementById('variedadSeleccionada').innerText = variedad + " (" + cantidad + " tuppers)";
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
