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

if ((int) $_SESSION['Rol'] !== 9) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Encargado de Incubadora.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// 3) Procesar el formulario
$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fecha            = $_POST['fecha'];
    $turno            = $_POST['turno'];
    $temp_inf         = $_POST['temperatura_inferior'];
    $temp_med         = $_POST['temperatura_media'];
    $temp_sup         = $_POST['temperatura_superior'];
    $hum_sup          = $_POST['humedad_superior'];
    $hum_inf          = $_POST['humedad_inferior'];
    $operador         = $_SESSION['ID_Operador'];

    $stmt = $conn->prepare("
        INSERT INTO registro_parametros_incubadora
          (fecha, turno, id_operador,
           temperatura_superior, temperatura_media, temperatura_inferior,
           humedad_superior, humedad_inferior)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'ssiddidd',
        $fecha,
        $turno,
        $operador,
        $temp_sup,
        $temp_med,
        $temp_inf,
        $hum_sup,
        $hum_inf
    );
    if ($stmt->execute()) {
        $mensaje = '✅ Registro guardado exitosamente';
    } else {
        $mensaje = '❌ Error al guardar: ' . $stmt->error;
    }
}

// 4) Obtener solo los registros de hoy
$result = $conn->query("
    SELECT fecha, turno,
           temperatura_inferior,
           temperatura_media,
           temperatura_superior,
           humedad_superior,
           humedad_inferior
      FROM registro_parametros_incubadora
     WHERE fecha = CURDATE()
     ORDER BY fecha_hora_registro DESC
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Registro de Parámetros de Incubadora</title>
  <link rel="stylesheet" href="../style.css?v=<?=time();?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous" />
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
        <img src="../logoplantulas.png" alt="Logo" width="130" height="124"
             class="d-inline-block align-text-center" />
      </a>
      <div>
        <h2>Registro de Parámetros de Incubadora</h2>
        <p>Registra temperatura y humedad – 3 turnos diarios.</p>
      </div>
    </div>

    <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_eism.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
  </header>

  <main>
    <section class="form-container">
      <?php if ($mensaje): ?>
      <div class="alerta"><?= $mensaje ?></div>
      <?php endif; ?>

      <div class="form-header mb-4">
        <h2 class="text-center mb-3">Registrar temperatura y humedad</h2>
      </div>

      <div class="main-content">
        <!-- Formulario -->
        <form method="POST" class="formulario">
          <div class="form-left">

            <div class="form-group">
              <label for="fecha" class="form-label">Fecha</label>
              <input type="date" id="fecha" name="fecha" class="form-control"
                     value="<?= date('Y-m-d') ?>" readonly>
            </div>

            <div class="form-group">
              <label for="turno" class="form-label">Turno</label>
              <select id="turno" name="turno" class="form-control" required>
                <option value="">Seleccionar...</option>
                <option value="Mañana">Mañana</option>
                <option value="Tarde">Tarde</option>
                <option value="Noche">Noche</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Temperatura (Repisa Inferior)</label>
              <div class="input-group">
                <input type="number"
                       name="temperatura_inferior"
                       class="form-control"
                       step="0.01" max="99.99"
                       pattern="\d{1,2}(\.\d{1,2})?"
                       placeholder="0.00"
                       required>
                <span class="input-group-text">°C</span>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Temperatura (Repisa Media)</label>
              <div class="input-group">
                <input type="number"
                       name="temperatura_media"
                       class="form-control"
                       step="0.01" max="99.99"
                       pattern="\d{1,2}(\.\d{1,2})?"
                       placeholder="0.00"
                       required>
                <span class="input-group-text">°C</span>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Temperatura (Repisa Superior)</label>
              <div class="input-group">
                <input type="number"
                       name="temperatura_superior"
                       class="form-control"
                       step="0.01" max="99.99"
                       pattern="\d{1,2}(\.\d{1,2})?"
                       placeholder="0.00"
                       required>
                <span class="input-group-text">°C</span>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Humedad Relativa (Repisa Superior)</label>
              <div class="input-group">
                <input type="number"
                       name="humedad_superior"
                       class="form-control"
                       step="0.01" max="99.99"
                       pattern="\d{1,2}(\.\d{1,2})?"
                       placeholder="0.00"
                       required>
                <span class="input-group-text">%</span>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Humedad Relativa (Repisa Inferior)</label>
              <div class="input-group">
                <input type="number"
                       name="humedad_inferior"
                       class="form-control"
                       step="0.01" max="99.99"
                       pattern="\d{1,2}(\.\d{1,2})?"
                       placeholder="0.00"
                       required>
                <span class="input-group-text">%</span>
              </div>
            </div>

            <div class="d-grid gap-2 mt-4">
              <button type="submit" class="btn-submit">Guardar</button>
            </div>
          </div>
        </form>

        <!-- Historial del día -->
        <div class="form-right">
          <h2>Historial de hoy</h2>
          <div class="table-responsive">
            <table class="table table-striped table-sm">
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Turno</th>
                  <th>Inf. (°C)</th>
                  <th>Med. (°C)</th>
                  <th>Sup. (°C)</th>
                  <th>Hum. Sup. (%)</th>
                  <th>Hum. Inf. (%)</th>
                </tr>
              </thead>
              <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                  <td><?= htmlspecialchars($row['fecha']) ?></td>
                  <td><?= htmlspecialchars($row['turno']) ?></td>
                  <td><?= htmlspecialchars($row['temperatura_inferior']) ?></td>
                  <td><?= htmlspecialchars($row['temperatura_media']) ?></td>
                  <td><?= htmlspecialchars($row['temperatura_superior']) ?></td>
                  <td><?= htmlspecialchars($row['humedad_superior']) ?></td>
                  <td><?= htmlspecialchars($row['humedad_inferior']) ?></td>
                </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
      </div>
    </section>
  </main>

  <footer>
    <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
  </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>

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
