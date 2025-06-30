<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Validar sesión y rol
require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['ID_Operador'])) {
    header('Location: ../login.php?mensaje=Debe iniciar sesión');
    exit;
}
$ID_Operador = (int) $_SESSION['ID_Operador'];

if ((int) $_SESSION['Rol'] !== 4) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Supervisora de Incubadora.</p>";
    exit;
}

// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();


// 3) Leer mensaje GET
$msg = $_GET['msg'] ?? '';

// Consulta de juegos disponibles
$resJuegos = $conn->query("
  SELECT COUNT(*) AS disponibles
    FROM juegos_materiales jm
   WHERE jm.estado_juego = 'Esterilizado'
     AND NOT EXISTS (
       SELECT 1 FROM asignacion_juego_operadora aj
        WHERE aj.id_juego = jm.id_juego
     )
");
$juegosDisponibles = $resJuegos->fetch_assoc()['disponibles'] ?? 0;

// 4) Procesar POST de asignación de juegos esterilizados
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['accion'] === 'asignar_juegos') {
    $conn->begin_transaction();
    try {
        $id_operador = (int)$_POST['id_operador'];
        $cantidad    = (int)$_POST['cantidad_juegos'];

        // Obtener juegos disponibles no asignados
        $stmt = $conn->prepare("
            SELECT id_juego
              FROM juegos_materiales
             WHERE estado_juego = 'Esterilizado'
               AND NOT EXISTS (
                 SELECT 1 FROM asignacion_juego_operadora aj
                 WHERE aj.id_juego = juegos_materiales.id_juego
               )
             ORDER BY fecha_esterilizacion ASC
             LIMIT ?
        ");
        $stmt->bind_param("i", $cantidad);
        $stmt->execute();
        $res = $stmt->get_result();

        $ids = [];
        while ($row = $res->fetch_assoc()) {
            $ids[] = $row['id_juego'];
        }
        $stmt->close();

        if (count($ids) < $cantidad) {
            throw new Exception("No hay suficientes juegos disponibles para asignar.");
        }
        $cantidad = (int) $_POST['cantidad_juegos'];
        if ($cantidad > $juegosDisponibles) {
    throw new Exception("La cantidad solicitada ($cantidad) excede los juegos disponibles ($juegosDisponibles).");
}

        // Insertar en la tabla de asignaciones
$insert = $conn->prepare("
    INSERT INTO asignacion_juego_operadora (id_juego, id_operador_asigna, id_operador_asignado, fecha_asignacion)
    VALUES (?, ?, ?, CURDATE())
");
foreach ($ids as $id_juego) {
    $insert->bind_param("iii", $id_juego, $ID_Operador, $id_operador);
    $insert->execute();
}
        $insert->close();

        $conn->commit();
        $msg = "✅ Se asignaron $cantidad juegos correctamente.";
    } catch (Exception $e) {
        $conn->rollback();
        $msg = '❌ Error: ' . $e->getMessage();
    }

    header('Location: suministro_material.php?msg=' . urlencode($msg));
    exit();
}

// — Construir mapa id_material → disponibles —
$qtyMap = [];
$resQty = $conn->query("
  SELECT 
    m.id_material,
    CASE 
      WHEN m.reutilizable = 1 
        THEN COALESCE(i.cantidad,0)-COALESCE(i.en_uso,0)
      ELSE COALESCE(i.cantidad,0)
    END AS disponibles
  FROM materiales m
  LEFT JOIN inventario_materiales i USING(id_material)
");
while ($row = $resQty->fetch_assoc()) {
    $qtyMap[$row['id_material']] = intval($row['disponibles']);
}


// 5) Cargar operadoras y materiales
$ops  = $conn->query("
    SELECT ID_Operador, CONCAT(Nombre,' ',Apellido_P,' ',Apellido_M) AS nombre
      FROM operadores
     WHERE ID_Rol = 2
     ORDER BY nombre
");

// 6) Traer últimas asignaciones
$asigs = $conn->query("
  SELECT
    ajo.fecha_asignacion,
    CONCAT(asg.Nombre, ' ', asg.Apellido_P, ' ', asg.Apellido_M) AS quien_asigna,
    CONCAT(rec.Nombre, ' ', rec.Apellido_P, ' ', rec.Apellido_M) AS quien_recibe,
    COUNT(*) AS juegos_asignados
  FROM asignacion_juego_operadora ajo
  JOIN operadores asg ON ajo.id_operador_asigna = asg.ID_Operador
  JOIN operadores rec ON ajo.id_operador_asignado = rec.ID_Operador
  WHERE DATE(ajo.fecha_asignacion) = CURDATE()
  GROUP BY ajo.id_operador_asigna, ajo.id_operador_asignado, ajo.fecha_asignacion
  ORDER BY ajo.fecha_asignacion DESC
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Asignación de Juegos Esterilizados</title>
  <link rel="stylesheet" href="../style.css?v=<?=time();?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .table-responsive > table {
      width: 100%;
      table-layout: auto;
    }
    .table th, .table td {
      white-space: normal;
      word-break: break-word;
    }
  </style>
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>
  <div class="contenedor-pagina">
    <header class="mb-4">
      <div class="encabezado d-flex align-items-center">
        <a class="navbar-brand me-3" href="#">
          <img src="../logoplantulas.png" width="130" height="124" alt="Logo Plantulas">
        </a>
        <div>
<h2>Suministro de Juegos Esterilizados</h2>
<p>Asigna juegos esterilizados a las operadoras.</p>
        </div>
      </div>
      <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_supervisora.php'">
                🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
    </header>

    <main class="container">
      <?php if ($msg): ?>
        <div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <div class="row g-4">
        <!-- Inventario Disponible -->
<div class="row g-4">

<!-- Formulario de Asignación con resumen incluido -->
<div class="col-12">
  <div class="card">
    <div class="card-header bg-primary text-white text-center">
      Asignar Material
    </div>
    <div class="card-body">

      <!-- Resumen con marco verde y fondo transparente -->
      <div class="border border-success rounded p-2 mb-4">
        <h6 class="mb-1 text-success fw-semibold">✅ Juegos esterilizados disponibles</h6>
        <p class="mb-0">
          Total disponibles: <strong><?= $juegosDisponibles ?></strong>
        </p>
      </div>

      <!-- Formulario -->
      <form method="POST">
        <input type="hidden" name="accion" value="asignar_juegos">

        <div class="mb-3">
          <label class="form-label">Operadora</label>
          <select name="id_operador" class="form-select" required>
            <option value="">Selecciona…</option>
            <?php while ($op = $ops->fetch_assoc()): ?>
              <option value="<?= $op['ID_Operador'] ?>">
                <?= htmlspecialchars($op['nombre']) ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Cantidad de juegos a asignar</label>
          <input type="number" name="cantidad_juegos" class="form-control" min="1" max="<?= $juegosDisponibles ?>" required>
        </div>

        <div class="text-end">
          <button class="btn btn-success">Asignar Juegos</button>
        </div>
      </form>

    </div>
  </div>
</div>

      <!-- Últimas Asignaciones -->
<div class="card mt-4">
  <div class="card-header bg-secondary text-white">Últimas Asignaciones</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table tabla-asignaciones table-striped table-hover table-sm mb-0">
<thead class="table-dark">
  <tr>
    <th>Fecha</th>
    <th>Quien Asigna</th>
    <th>Quien Recibe</th>
    <th>Juegos Asignados</th>
  </tr>
</thead>
<tbody>
  <?php while ($a = $asigs->fetch_assoc()): ?>
    <tr>
      <td><?= htmlspecialchars($a['fecha_asignacion']) ?></td>
      <td><?= htmlspecialchars($a['quien_asigna']) ?></td>
      <td><?= htmlspecialchars($a['quien_recibe']) ?></td>
      <td><?= htmlspecialchars($a['juegos_asignados']) ?></td>
    </tr>
  <?php endwhile; ?>
</tbody>
      </table>
    </div>
  </div>
</div>

    </main>

    <footer class="text-center py-3">&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</footer>
  </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const input = document.querySelector('input[name="cantidad_juegos"]');
  const max = parseInt(input.max);

  input.addEventListener('input', () => {
    let val = parseInt(input.value);
    if (isNaN(val)) return;
    if (val > max) input.value = max;
    if (val < 1) input.value = 1;
  });

  input.addEventListener('keypress', (e) => {
    const char = String.fromCharCode(e.which);
    if (!/[\d]/.test(char)) e.preventDefault();
  });
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
