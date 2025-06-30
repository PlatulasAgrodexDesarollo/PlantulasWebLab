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

$msg = "";

// Guardar limpieza
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_operador = $_SESSION['ID_Operador'];
    $anaquel     = $_POST['anaquel'] ?? '';
    $repisas     = intval($_POST['repisas'] ?? 0);
    $fecha       = date('Y-m-d');
    $hora        = date('Y-m-d H:i:s');

    if ($anaquel && $repisas > 0) {
        // Insertar en limpieza_incubadora
        $stmt = $conn->prepare("INSERT INTO limpieza_incubadora (ID_Operador, Fecha, Hora_Registro, Anaquel, Repisas_Limpias) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isssi", $id_operador, $fecha, $hora, $anaquel, $repisas);
        $stmt->execute();
        $id_limpieza = $conn->insert_id;
        $stmt->close();

        // Insertar en registro_limpieza
        $area = "7. Incubador";
        $estado = "Realizada";
        $stmt2 = $conn->prepare("INSERT INTO registro_limpieza (ID_Operador, Fecha, Hora_Registro, Area, Estado_Limpieza, ID_LimpiezaIncubadora) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt2->bind_param("issssi", $id_operador, $fecha, $hora, $area, $estado, $id_limpieza);
        $stmt2->execute();
        $stmt2->close();

        $msg = "✅ Registro guardado correctamente.";
    } else {
        $msg = "❌ Debes completar todos los campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Limpieza de Incubador</title>
  <link rel="stylesheet" href="../style.css?v=<?= time(); ?>" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
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
        <a class="navbar-brand" href="#"><img src="../logoplantulas.png" width="130" height="124" /></a>
        <h2>🧽 Limpieza de Repisas del Incubador</h2>
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
        <div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header bg-success text-white">Registrar Limpieza</div>
        <div class="card-body">
          <form method="POST">
            <div class="mb-3">
              <label class="form-label">Anaquel</label>
              <select name="anaquel" class="form-select" required>
                <option value="">Selecciona…</option>
                <option value="Anaquel 1">Anaquel 1</option>
                <option value="Anaquel 2">Anaquel 2</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Cantidad de Repisas Limpias</label>
              <input type="number" name="repisas" class="form-control" min="1" required />
            </div>
            <div class="text-end">
              <button type="submit" class="btn btn-primary">Guardar Registro</button>
            </div>
          </form>
        </div>
      </div>
    </main>

    <footer class="text-center py-3">
      &copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.
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
