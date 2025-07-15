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
$volver1 = !empty($_SESSION['origin']) && $_SESSION['origin'] === 1;

if ((int) $_SESSION['Rol'] !== 11) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Solo Director General.</p>";
    exit;
}

// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 600 * 40;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Director General</title>
  <link rel="stylesheet" href="../style.css?v=<?= time() ?>" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous" />
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS           = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>
  <div class="contenedor-pagina panel-admin">
    <header>
      <div class="encabezado">
        <img src="../logoplantulas.png"
             alt="Logo"
             width="130" height="124"
             style="cursor:<?= $volver1 ? 'pointer' : 'default' ?>"
             <?= $volver1 ? "onclick=\"location.href='../rol_administrador/volver_rol.php'\"" : '' ?>>
        <div>
          <h2>Director General</h2>
          <p>Visualización de producción global.</p>
        </div>
      </div>

      <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='../logout.php'">Cerrar Sesión</button>
            </div>
          </div>
        </nav>
      </div>
    </header>

<main class="container mt-4">
  
  <section class="dashboard-grid">
    <div class="card" data-card-id="resumen-director">
      <h2>📊 Proyección semanal</h2>
      <p>Visualiza todas las proyecciones verificadas por variedad.</p>
      <a href="proyeccion_semanal.php" onclick="rememberCard('resumen-director')">
        Ver resumen
      </a>
    </div>

    <div class="card" data-card-id="produccion">
      <h2>🌱 Produccion</h2>
      <p>Siembra diaria etapa 2 y 3.</p>
      <a href="produccion.php" onclick="rememberCard('produccion')">
        Ver resumen
      </a>
    </div>

    <div class="card" data-card-id="lavado-planta">
      <h2>🫧 Lavado de planta</h2>
      <p>Reporte de Lavado por etapa.</p>
      <a href="lavado.php" onclick="rememberCard('lavado-planta')">
        Ver resumen
      </a>
    </div>

    <div class="card" data-card-id="invetnario-incubadora">
      <h2>📦 Inventario</h2>
      <p>Planta de etapa 2 y 3.</p>
      <a href="inventario.php" onclick="rememberCard('invetnario-incubadora')">
        Ver resumen
      </a>
    </div>

    <div class="card" data-card-id="perdias-plantas">
      <h2>🪾 Mermas</h2>
      <p>Revisa las perdidas de los  diferentes puntos del sistema.</p>
      <a href="perdidas.php" onclick="rememberCard('perdias-plantas')">
        Ver resumen
      </a>
    </div>



  </section>
</main>

    <footer>
      <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
    </footer>
  </div>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

<script>
  function rememberCard(id) {
    sessionStorage.setItem('lastCard', id);
  }

  document.addEventListener('DOMContentLoaded', () => {
    const last = sessionStorage.getItem('lastCard');
    if (last) {
      const target = document.querySelector(`.dashboard-grid .card[data-card-id="${last}"]`);
      if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        target.classList.add('highlight');
      }
    }
  });
</script>

  <!-- Modal de advertencia de sesión -->
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
      document.getElementById('keepalive-btn').addEventListener('click', cerrarModalYReiniciar);
    }

    function cerrarModalYReiniciar() {
      const modal = document.getElementById('session-warning');
      if (modal) modal.remove();
      reiniciarTimers();
      fetch('../keepalive.php', { credentials: 'same-origin' }).catch(() => {});
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
