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

$filtro_fecha = $_GET['fecha'] ?? date('Y-m-d');

$query = "
    SELECT 
        o.Nombre AS Operador,
        v.Nombre_Variedad AS Variedad,
        a.Rol,
        a.Fecha,
        SUM(a.Cantidad_Tuppers) AS Total_Tuppers
    FROM asignacion_lavado a
    JOIN operadores o ON a.ID_Operador = o.ID_Operador
    JOIN variedades v ON a.ID_Variedad = v.ID_Variedad
    WHERE a.Fecha = ?
    GROUP BY a.ID_Operador, a.ID_Variedad, a.Rol, a.Fecha
    ORDER BY o.Nombre ASC, a.Fecha DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $filtro_fecha);
$stmt->execute();
$resultado = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rendimiento del Personal</title>
    <link rel="stylesheet" href="../style.css?v=<?=time();?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
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
                <img src="../logoplantulas.png" alt="Logo" width="130" height="124" />
            </a>
            <h2>📈 Rendimiento del Personal en Lavado</h2>
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
        <!-- Filtro por fecha -->
        <div class="d-flex justify-content-center">
            <div style="max-width: 350px; width: 100%;">
                <h4 >📅 Filtrar por Fecha</h4>
                <form method="GET">
                    <div class="col-12">
                        <label for="fecha" class="form-label">Seleccionar Fecha:</label>
                        <input type="date" name="fecha" id="fecha" value="<?= $filtro_fecha ?>" class="form-control" required>
                    </div>
                    <div class="col-12 text-center">
                        <button type="submit" class="btn btn-primary w-50">Filtrar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Resultados -->
        <div class="card p-4 shadow-sm">
            <h4 class="mb-4">📋 Resultado de Lavado</h4>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-striped align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Fecha</th>
                            <th>Operador</th>
                            <th>Rol</th>
                            <th>Variedad</th>
                            <th>Total de Tuppers Lavados</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($resultado->num_rows > 0): ?>
                            <?php while ($fila = $resultado->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($fila['Fecha']) ?></td>
                                    <td><?= htmlspecialchars($fila['Operador']) ?></td>
                                    <td><?= htmlspecialchars($fila['Rol']) ?></td>
                                    <td><?= htmlspecialchars($fila['Variedad']) ?></td>
                                    <td><?= htmlspecialchars($fila['Total_Tuppers']) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center">No hay registros para esta fecha.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <footer class="text-center mt-5 mb-3">
        <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

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
