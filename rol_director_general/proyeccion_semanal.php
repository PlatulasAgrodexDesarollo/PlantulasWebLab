<?php
// 0) Mostrar errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Validar sesión y rol (Director General)
require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['ID_Operador'])) {
    header('Location: ../login.php?mensaje=Debe iniciar sesión');
    exit;
}
$ID_Operador = (int) $_SESSION['ID_Operador'];
if ((int) $_SESSION['Rol'] !== 11) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Solo Director General.</p>";
    exit;
}

// 2) Obtener semana seleccionada o actual
$semana = (int) ($_GET['semana'] ?? date('W'));
$anio   = (int) ($_GET['anio']   ?? date('o'));

$start = new DateTime();
$start->setISODate($anio, $semana);
$end = clone $start;
$end->modify('+4 days');
$fechaInicio = $start->format('Y-m-d 00:00:00');
$fechaFin    = $end->format('Y-m-d 23:59:59');

// 3) Consulta
$sql = "
SELECT v.Codigo_Variedad, v.Nombre_Variedad,
       SUM(p.Tuppers_Proyectados) AS Total_Tuppers,
       SUM(p.Brotes_Proyectados)  AS Total_Brotes
FROM   proyecciones_lavado p
JOIN   (
  SELECT ID_Variedad, ID_Multiplicacion AS ID, 'multiplicacion' AS Etapa FROM multiplicacion
  UNION ALL
  SELECT ID_Variedad, ID_Enraizamiento AS ID, 'enraizamiento' AS Etapa FROM enraizamiento
) AS etapas ON p.Etapa = etapas.Etapa AND p.ID_Etapa = etapas.ID
JOIN   variedades v ON v.ID_Variedad = etapas.ID_Variedad
WHERE p.Estado_Flujo IN ('lavado', 'enviado_tenancingo', 'pendiente_acomodo', 'acomodados')
  AND  IFNULL(p.Fecha_Verificacion, p.Fecha_Creacion) BETWEEN ? AND ?
GROUP  BY v.ID_Variedad
ORDER BY v.Codigo_Variedad";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $fechaInicio, $fechaFin);
$stmt->execute();
$resultado = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sessionLifetime = 60 * 3;
$warningOffset   = 60;
$nowTs = time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Proyección Semanal Verificada</title>
  <link rel="stylesheet" href="../style.css?v=<?=time()?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="contenedor-pagina">
  <header>
    <div class="encabezado">
      <a class="navbar-brand" href="dashboard_director.php">
        <img src="../logoplantulas.png" alt="Logo" width="130" height="124">
      </a>
      <div>
        <h2>Proyección de la semana <?= $start->format('d/m') ?> – <?= $end->format('d/m/Y') ?></h2>
        <p>Visualización exclusiva de datos verificados</p>
      </div>
    </div>
        <div class="barra-navegacion">
      <nav class="navbar bg-body-tertiary">
        <div class="container-fluid">
          <div class="Opciones-barra">
            <button onclick="location.href='dashboard_director.php'">🏠 Volver al Inicio</button>
          </div>
        </div>
      </nav>
    </div>
  </header>

 <main class="container-fluid mt-4 flex-grow-1">
  <section class="mb-4">
    <form method="get" class="row gy-2 gx-3 align-items-end">
      <div class="col-6 col-md-3">
        <label for="semana" class="form-label small">Semana ISO</label>
        <input type="number" id="semana" name="semana" class="form-control form-control-sm"
               min="1" max="53" value="<?= $semana ?>">
      </div>
      <div class="col-6 col-md-3">
        <label for="anio" class="form-label small">Año</label>
        <input type="number" id="anio" name="anio" class="form-control form-control-sm"
               value="<?= $anio ?>">
      </div>
      <div class="col-6 col-md-auto">
        <button type="submit" class="btn btn-success btn-sm w-100">Filtrar</button>
      </div>
      <div class="col-6 col-md-auto">
        <a class="btn btn-danger btn-sm w-100"
           href="reporte_pdf_proyeccion.php?semana=<?= $semana ?>&anio=<?= $anio ?>"
           target="_blank">
          📄 Exportar PDF
        </a>
      </div>
    </form>
  </section>

  <?php if (empty($resultado)): ?>
    <div class="alert alert-info">
      No hay proyecciones verificadas para la semana seleccionada.
    </div>
  <?php else: ?>
    <section>
      <div class="table-responsive">
        <table class="table table-striped table-bordered table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>Variedad</th>
              <th class="text-end">Tuppers</th>
              <th class="text-end">Brotes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($resultado as $fila): ?>
              <tr>
                <td data-label="Variedad"><?= htmlspecialchars($fila['Codigo_Variedad'].' – '.$fila['Nombre_Variedad']) ?></td>
                <td class="text-end" data-label="Tuppers"><?= $fila['Total_Tuppers'] ?></td>
                <td class="text-end" data-label="Brotes"><?= $fila['Total_Brotes'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>
</main>

  <footer class="text-center py-3 mt-4">&copy; <?= date('Y') ?> PLANTAS AGRODEX</footer>
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
