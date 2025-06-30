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
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Inventario de incubadora</title>
  <link rel="stylesheet" href="../style.css?v=<?=time();?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous" />
</head>
<script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
<body>
<div class="contenedor-pagina">
  <header>
    <div class="encabezado">
      <a class="navbar-brand" href="#">
        <img src="logoplantulas.png"alt="Logo" width="130" height="124" class="d-inline-block align-text-center" />
      </a>
      <div>
        <h2>Inventario de incubadora</h2>
        <p>Vista del inventario de la incubadora.</p>
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

  <main>
    <section class="form-container">
      <div class="form-header mb-4">
        <h2 class="text-center mb-3">Distribucion de trabajo para Lavado </h2>
      </div>
  <!-- Historial de parámetros  -->
  <div class="form-container">
    <div class="search-section mb-4">
      <select class="form-select">
        <option>Buscar por ID o planta</option>
      </select>
    </div>

    <div class="material-section mb-4">
      <button class="btn-inicio">Buscar </button>
    </div>

    <table class="table">
      <thead>
        <tr>
          <th>ID Planta</th>
          <th>Variedad</th>
          <th>Etapa de la variedad</th>
          <th>Tiempo en incubadora</th>
        </tr>
      </thead>
      <tbody>
        <tr>
            
            <td>ID Planta:WJQ8</td>
            <td>Variedad:Forza</td>
            <td>Etapa 2</td>
            <td>2 meses y 3 dias</td>
           
          </tr>
          <tr>
            <td>ID Planta:WJQ8</td>
            <td>Variedad:Forza</td>
            <td>Etapa 3</td>
            <td>2 meses y 3 dias</td>
            
          </tr>

          <tr>
            <td>ID Planta:WJQ8</td>
            <td>Variedad:Forza</td>
            <td>Etapa 1</td>
            <td>2 meses y 3 dias</td>
            
          </tr>

          <tr>
            <td>ID Planta:WJQ8</td>
            <td>Variedad:Forza</td>
            <td>Etapa 2</td>
            <td>2 meses y 3 dias</td>
            
          </tr>
      </tbody>
    </table>

    <div class="d-grid gap-2 mt-4">
        <button type="submit" class="btn-submit">
          Guardar
        </button>
      </div>
</section>
</main>
<footer>
    <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
  </footer>
</div>

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
  