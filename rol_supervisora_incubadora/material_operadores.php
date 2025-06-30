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

if ((int) $_SESSION['Rol'] !== 4) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Supervisora de Incubadora.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// 3) Obtener todas las operadoras (Rol = 2)
$ops = $conn->query("
  SELECT 
    ID_Operador,
    CONCAT(Nombre, ' ', Apellido_P, ' ', Apellido_M) AS nombre
  FROM operadores
  WHERE `ID_Rol` = 2
  ORDER BY nombre
");

// 4) Procesar POST de revisión
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op     = intval($_POST['id_operador']);
    $enc    = $_SESSION['ID_Operador'];
    $e2     = intval($_POST['explantes_etapa2']);
    $e3     = intval($_POST['explantes_etapa3']);
    $otros  = trim($_POST['otros_articulos']);
    $ok     = intval($_POST['correcto']);
    $obs    = trim($_POST['observaciones']);

    $stmt = $conn->prepare("
      INSERT INTO asignacion_material
        (id_operador, fecha_revision, id_encargado,
         explantes_etapa2, explantes_etapa3,
         otros_articulos, correcto, observaciones)
      VALUES (?, NOW(), ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE
        fecha_revision    = NOW(),
        id_encargado      = VALUES(id_encargado),
        explantes_etapa2  = VALUES(explantes_etapa2),
        explantes_etapa3  = VALUES(explantes_etapa3),
        otros_articulos   = VALUES(otros_articulos),
        correcto          = VALUES(correcto),
        observaciones     = VALUES(observaciones)
    ");
    $stmt->bind_param(
        'iiiiisss',
        $op, $enc, $e2, $e3, $otros, $ok, $obs
    );
    if ($stmt->execute()) {
        $msg = '✅ Revisión guardada';
    } else {
        $msg = '❌ Error: ' . $stmt->error;
    }
}

// 5) Preparar SELECT para cargar última asignación de cada operadora
$asigStmt = $conn->prepare("
  SELECT explantes_etapa2,
         explantes_etapa3,
         otros_articulos,
         correcto,
         observaciones
    FROM asignacion_material
   WHERE id_operador = ?
   LIMIT 1
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Revisión de Material Operadoras</title>
  <link rel="stylesheet" href="../style.css?v=<?=time()?>"/>
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
    integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
    crossorigin="anonymous"
  />
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>
  <div class="contenedor-pagina">
    <header>
      <div class="encabezado d-flex align-items-center">
        <a class="navbar-brand me-3" href="dashboard_eism.php">
          <img src="../logoplantulas.png" width="130" height="124" alt="Logo">
        </a>
        <div>
          <h2>Revisión de Material para Operadoras</h2>
          <p class="mb-0">Marca si tienen suficientes explantes y otros artículos</p>
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

    <main class="container mt-4">
      <?php if ($msg): ?>
        <div class="alert alert-info"><?= $msg ?></div>
      <?php endif; ?>

      <?php while ($op = $ops->fetch_assoc()): ?>
        <?php
          // Cargar últimos valores
          $asigStmt->bind_param('i', $op['ID_Operador']);
          $asigStmt->execute();
          $prev = $asigStmt->get_result()->fetch_assoc() ?: [];
        ?>
        <form method="POST" class="row g-3 mb-4">
          <input type="hidden" name="id_operador" value="<?= $op['ID_Operador'] ?>">
          <div class="col-12">
            <h3><?= htmlspecialchars($op['nombre']) ?></h3>
          </div>

          <div class="col-md-3">
            <label for="e2_<?= $op['ID_Operador'] ?>" class="form-label">Explantes Etapa 2</label>
            <input type="number"
                   class="form-control"
                   id="e2_<?= $op['ID_Operador'] ?>"
                   name="explantes_etapa2"
                   value="<?= $prev['explantes_etapa2'] ?? 0 ?>"
                   min="0">
          </div>

          <div class="col-md-3">
            <label for="e3_<?= $op['ID_Operador'] ?>" class="form-label">Explantes Etapa 3</label>
            <input type="number"
                   class="form-control"
                   id="e3_<?= $op['ID_Operador'] ?>"
                   name="explantes_etapa3"
                   value="<?= $prev['explantes_etapa3'] ?? 0 ?>"
                   min="0">
          </div>

          <div class="col-md-6">
            <label for="otros_<?= $op['ID_Operador'] ?>" class="form-label">Otros Artículos</label>
            <textarea id="otros_<?= $op['ID_Operador'] ?>"
                      class="form-control"
                      name="otros_articulos"
                      placeholder="Ej: pinzas, espátulas..."
                      rows="1"><?= htmlspecialchars($prev['otros_articulos'] ?? '') ?></textarea>
          </div>

          <div class="col-md-3">
            <label for="ok_<?= $op['ID_Operador'] ?>" class="form-label">¿Correcto?</label>
            <select id="ok_<?= $op['ID_Operador'] ?>"
                    class="form-select"
                    name="correcto">
              <option value="1" <?= ($prev['correcto'] ?? 1) == 1 ? 'selected' : '' ?>>Sí</option>
              <option value="0" <?= ($prev['correcto'] ?? 1) == 0 ? 'selected' : '' ?>>No</option>
            </select>
          </div>

          <div class="col-md-9">
            <label for="obs_<?= $op['ID_Operador'] ?>" class="form-label">Observaciones</label>
            <textarea id="obs_<?= $op['ID_Operador'] ?>"
                      class="form-control"
                      name="observaciones"
                      rows="1"><?= htmlspecialchars($prev['observaciones'] ?? '') ?></textarea>
          </div>

          <div class="col-12">
            <button type="submit" class="btn btn-primary">Guardar Revisión</button>
          </div>
        </form>
      <?php endwhile; ?>
    </main>

    <footer class="text-center py-3">&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</footer>
  </div>

  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"
  ></script>

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
