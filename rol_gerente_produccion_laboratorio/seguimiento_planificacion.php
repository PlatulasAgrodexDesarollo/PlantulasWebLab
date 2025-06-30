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

// Cambiar estado si se solicitó
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_plan']) && isset($_POST['nuevo_estado'])) {
  $id = $_POST['id_plan'];
  $estado = $_POST['nuevo_estado'];

  // Obtener estado actual antes de actualizar
  $consulta_estado = mysqli_query($conn, "SELECT Estado FROM planificacion_produccion WHERE ID_Planificacion = $id");
  $estado_actual = mysqli_fetch_assoc($consulta_estado)['Estado'];

  // Actualizar el estado
  $stmt = $conn->prepare("UPDATE planificacion_produccion SET Estado = ? WHERE ID_Planificacion = ?");
  $stmt->bind_param("si", $estado, $id);
  $stmt->execute();

  // Insertar en la auditoría
  $auditoria = $conn->prepare("
    INSERT INTO auditoria_laboratorio (Tabla_Afectada, Campo_Afectado, Valor_Anterior, Valor_Nuevo, Accion, Fecha_Cambio, Operador_Responsable)
    VALUES ('Planificacion_Produccion', 'Estado', ?, ?, 'UPDATE', NOW(), ?)
  ");
  $auditoria->bind_param("ssi", $estado_actual, $estado, $_SESSION['ID_Operador']);
  $auditoria->execute();
}

// Obtener lista de planificaciones con nombres de responsables
$result = mysqli_query($conn, "
  SELECT p.*, 
    v.Especie,
    v.Nombre_Variedad AS Variedad,
    oe.Nombre AS Responsable_Ejecucion_Nombre,
    os.Nombre AS Responsable_Supervision_Nombre,
    om.Nombre AS Responsable_Medio_Nombre,
    oa.Nombre AS Responsable_Acomodo_Nombre
  FROM planificacion_produccion p
  JOIN variedades v ON p.ID_Variedad = v.ID_Variedad
  LEFT JOIN operadores oe ON p.Responsable_Ejecucion = oe.ID_Operador
  LEFT JOIN operadores os ON p.Responsable_Supervision = os.ID_Operador
  LEFT JOIN operadores om ON p.Responsable_MedioNutritivo = om.ID_Operador
  LEFT JOIN operadores oa ON p.Responsable_Acomodo = oa.ID_Operador
  ORDER BY p.Fecha_Planificacion DESC
");
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Seguimiento de Planificación</title>
  <link rel="stylesheet" href="../style.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>
  <div class="container mt-4">
    <h2 class="mb-4 text-center">📊 Seguimiento de Planificación</h2>

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

    <table class="table table-bordered table-hover table-striped">
      <thead class="table-dark">
        <tr>
          <th>Fecha</th>
          <th>Especie</th>
          <th>Variedad</th>
          <th>Cantidad</th>
          <th>Etapa</th>
          <th>Estado</th>
          <th>Supervisión</th>
          <th>Medio</th>
          <th>Acomodo</th>
          <th>Acción</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($row = mysqli_fetch_assoc($result)): ?>
          <tr>
            <td><?= $row['Fecha_Planificacion'] ?></td>
            <td><?= htmlspecialchars($row['Especie']) ?></td>
            <td><?= htmlspecialchars($row['Variedad']) ?></td>
            <td><?= $row['Cantidad_Proyectada'] ?></td>
            <td><?= $row['Etapa_Destino'] ?></td>
            <td><strong><?= $row['Estado'] ?></strong></td>
            <td><?= $row['Responsable_Supervision_Nombre'] ?? '—' ?></td>
            <td><?= $row['Responsable_Medio_Nombre'] ?? '—' ?></td>
            <td><?= $row['Responsable_Acomodo_Nombre'] ?? '—' ?></td>
            <td>
              <form method="POST" class="d-flex gap-1">
                <input type="hidden" name="id_plan" value="<?= $row['ID_Planificacion'] ?>">
                <select name="nuevo_estado" class="form-select form-select-sm">
                  <option <?= $row['Estado'] == 'Planificada' ? 'selected' : '' ?>>Planificada</option>
                  <option <?= $row['Estado'] == 'En proceso' ? 'selected' : '' ?>>En proceso</option>
                  <option <?= $row['Estado'] == 'Finalizada' ? 'selected' : '' ?>>Finalizada</option>
                  <option <?= $row['Estado'] == 'Cancelada' ? 'selected' : '' ?>>Cancelada</option>
                </select>
                <button type="submit" class="btn btn-sm btn-primary">Actualizar</button>
              </form>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>

    <footer class="mt-4 text-center">
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
