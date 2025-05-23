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

    // 1. Insertar en preparacion_cajas
    $query_registro = "INSERT INTO preparacion_cajas 
                       (ID_Orden, ID_Operador, Tuppers_Buenos, Tuppers_Infectados, Observaciones, Fecha_Registro)
                       VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt_registro = $conn->prepare($query_registro);
    $stmt_registro->bind_param("iiiis", $id_orden, $ID_Operador, $tuppers_buenos, $tuppers_infectados, $observaciones);

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
        <table class="table table-bordered table-hover table-striped">
            <thead class="table-dark">
                <tr>
                    <th>Fecha de Lavado</th>
                    <th>Variedad</th>
                    <th>Código</th>
                    <th>Especie</th>
                    <th>Cantidad Asignada</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($orden = $resultado->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($orden['Fecha_Lavado']) ?></td>
                        <td><?= htmlspecialchars($orden['Nombre_Variedad']) ?></td>
                        <td><?= htmlspecialchars($orden['Codigo_Variedad']) ?></td>
                        <td><?= htmlspecialchars($orden['Especie']) ?></td>
                        <td><?= htmlspecialchars($orden['Cantidad_Lavada']) ?></td>
                        <td>
                            <button class="btn btn-primary btn-sm" onclick="prepararCaja(<?= $orden['ID_Orden'] ?>, '<?= htmlspecialchars($orden['Nombre_Variedad']) ?>', <?= $orden['Cantidad_Lavada'] ?>)">
                                Preparar Caja
                            </button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

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

 <!-- Modal de advertencia de sesión -->
 <script>
 (function(){
  // Estado y referencias a los temporizadores
  let modalShown = false,
      warningTimer,
      expireTimer;

  // Función para mostrar el modal de aviso
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
    document
      .getElementById('keepalive-btn')
      .addEventListener('click', keepSessionAlive);
  }

  // Función para llamar a keepalive.php y, si es OK, reiniciar los timers
  function keepSessionAlive() {
    fetch('../keepalive.php', { credentials: 'same-origin' })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'OK') {
          // Quitar el modal
          const modal = document.getElementById('session-warning');
          if (modal) modal.remove();

          // Reiniciar tiempo de inicio
          START_TS   = Date.now();
          modalShown = false;

          // Reprogramar los timers
          clearTimeout(warningTimer);
          clearTimeout(expireTimer);
          scheduleTimers();
        } else {
          alert('No se pudo extender la sesión');
        }
      })
      .catch(() => alert('Error al mantener viva la sesión'));
  }

  // Configura los timeouts para mostrar el aviso y para la expiración real
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

  // Inicia la lógica al cargar el script
  scheduleTimers();
})();
  </script>
</body>
</html>
