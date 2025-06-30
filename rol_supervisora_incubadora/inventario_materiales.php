<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Validar sesión y rol
require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';
setlocale(LC_TIME, 'es_MX.UTF-8');

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

// Lista fija de materiales permitidos
$lista_materiales = [
    'Pinza grande',
    'Pinza mediana',
    'Bisturí',
    'Bolsa de periódico',
    'Trapos'
];

// 2) Procesar formularios
$msg = '';

if (isset($_GET['registrados'])) {
    $msg = "✅ Se registraron {$_GET['registrados']} juegos nuevos.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['cantidad_juegos_nuevos'])) {
        $cantidad = max(1, intval($_POST['cantidad_juegos_nuevos']));
        $registrados = 0;

        $stmt = $conn->prepare("
            INSERT INTO juegos_materiales (fecha_registro, estado_juego, id_operador_registro)
            VALUES (CURDATE(), 'Pendiente', ?)
        ");
        for ($i = 0; $i < $cantidad; $i++) {
            $stmt->bind_param('i', $ID_Operador);
            $stmt->execute();
            if ($stmt->affected_rows > 0) $registrados++;
        }

        header("Location: inventario_materiales.php?registrados=$registrados");
        exit;
    }
}

// Consulta para mostrar los juegos registrados por la supervisora
$juegosPendientes = $conn->prepare("
SELECT id_juego, fecha_registro
FROM juegos_materiales
WHERE estado_juego = 'Pendiente' AND id_operador_registro = ?
ORDER BY id_juego DESC
");
$juegosPendientes->bind_param('i', $ID_Operador);
$juegosPendientes->execute();
$resultadosPendientes = $juegosPendientes->get_result();
$totalPendientes = $resultadosPendientes->num_rows;

$juegosEsterilizados = $conn->query("
SELECT DATE(fecha_esterilizacion) AS fecha, COUNT(*) AS total
FROM registro_esterilizacion_juego
WHERE YEARWEEK(fecha_esterilizacion, 1) = YEARWEEK(CURDATE(), 1)
GROUP BY fecha
ORDER BY fecha DESC
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Inventario de Materiales</title>
  <link rel="stylesheet" href="../style.css?v=<?=time()?>"/>
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet" crossorigin="anonymous"
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
          <h2>Inventario de Materiales</h2>
          <p class="mb-0">Registra nuevos Juegos de materiales.</p>
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
    <div class="alert alert-success"><?= $msg ?></div>
  <?php endif; ?>

<!-- FORMULARIO: REGISTRO DE NUEVOS JUEGOS -->
<div class="row justify-content-center g-0 mb-3">
  <div class="col-12 col-sm-12 col-md-8 col-lg-6">
    <div class="card p-4">
      <h4>Registrar juegos de materiales</h4>
      <form method="POST" class="row g-3">
        <div class="col-md-12">
          <label for="cantidad_juegos_nuevos" class="form-label">Cantidad de juegos preparados</label>
          <input type="number" name="cantidad_juegos_nuevos" id="cantidad_juegos_nuevos"
                 class="form-control" placeholder="Ej. 5" min="1" max="80" required>
        </div>
        <div class="col-12 d-grid">
          <button class="btn btn-primary">Registrar Juegos</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- RESUMEN: TOTAL DE JUEGOS PENDIENTES DE ESTERILIZACIÓN -->
<div class="text-center my-4">
  <div class="border rounded p-3 bg-body-secondary shadow-sm d-inline-block">
    <h5 class="mb-2">Juegos disponibles para esterilización</h5>
    <p class="fs-5 mb-0">
      📦 Total de juegos disponibles: <strong><?= $totalPendientes ?></strong>
    </p>
  </div>
</div>

<!-- TABLA: JUEGOS YA ESTERILIZADOS -->
<div class="text-center my-4">
  <?php
$hoy = new DateTime();
$diaSemana = (int) $hoy->format('N'); // 1 (lunes) a 7 (domingo)

$lunes = clone $hoy;
$lunes->modify('-' . ($diaSemana - 1) . ' days');

$viernes = clone $lunes;
$viernes->modify('+4 days');

$formatter = new IntlDateFormatter(
  'es_MX',
  IntlDateFormatter::LONG,
  IntlDateFormatter::NONE,
  'America/Mexico_City',
  IntlDateFormatter::GREGORIAN,
  'd MMMM'
);

$textoSemana = "Juegos de herramientas esterilizados en la semana del " . $formatter->format($lunes) . " al " . $formatter->format($viernes);
?>
<h5 class="mb-2"><?= $textoSemana ?></h5>
  <div class="table-responsive px-2">
    <table class="table table-bordered text-center align-middle">
      <thead class="table-light">
        <tr>
          <th>Día</th>
          <th>Total esterilizados</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($juegosEsterilizados->num_rows === 0): ?>
          <tr><td colspan="2">Aún no hay juegos esterilizados esta semana.</td></tr>
        <?php else: ?>
          <?php while ($row = $juegosEsterilizados->fetch_assoc()): ?>
            <tr>
              <?php
$fecha = new DateTime($row['fecha']);
$formatter = new IntlDateFormatter(
  'es_MX',
  IntlDateFormatter::FULL,
  IntlDateFormatter::NONE,
  'America/Mexico_City',
  IntlDateFormatter::GREGORIAN,
  "EEEE d/MM/yyyy"
);
?>
<td><?= ucfirst($formatter->format($fecha)) ?></td>
              <td><?= $row['total'] ?></td>
            </tr>
          <?php endwhile; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</main>


    <footer class="text-center py-3">
      &copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.
    </footer>
  </div>

  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
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
