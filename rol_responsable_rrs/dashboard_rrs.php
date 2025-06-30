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

if ((int) $_SESSION['Rol'] !== 8) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Solo Responsable de Registros y Reportes de Siembra.</p>";
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
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel Responsable de Registro y Siembra</title>
    <link rel="stylesheet" href="../style.css?v=<?= time(); ?>" />
    <link
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      rel="stylesheet"
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
        <div class="encabezado">
<img src="../logoplantulas.png"
     alt="Logo"
     width="130" height="124"
     style="cursor:<?= $volver1 ? 'pointer' : 'default' ?>"
     <?= $volver1 ? "onclick=\"location.href='../rol_administrador/volver_rol.php'\"" : '' ?>>
          <div>
            <h2>Responsable de Registro y Siembra</h2>
          </div>
        </div>

        <div class="barra-navegacion">
          <nav class="navbar bg-body-tertiary">
            <div class="container-fluid">
              <div class="Opciones-barra">
                <button onclick="window.location.href='../logout.php'">
                  Cerrar Sesión
                </button>
              </div>
            </div>
          </nav>
        </div>
      </header>

      <!-- Contenido principal -->
      <main>
        <section class="dashboard-grid">
          <div class="card card-ecas" id="card-desinfeccion">
            <h2>🌱 Consolidar Registros de Trabajo</h2>
            <p>Registra y consolida los datos diarios de siembra.</p>
            <a
              href="consolidar_trabajo.php"
              onclick="guardarScroll('card-desinfeccion')"
              >Ir a Registros</a
            >
          </div>

          <div class="card" id="card-historial-desinfeccion">
            <h2>📋 Historial de Reportes</h2>
            <p>Accede al historial completo de reportes de siembra.</p>
            <a
              href="historial_reportes.php"
              onclick="guardarScroll('card-historial-desinfeccion')"
              >Ver Historial</a
            >
          </div>

          <div class="card card-ecas" id="card-siembra-inicial">
            <h2>📄 Exportar Reportes</h2>
            <p>Exporta los reportes en formato PDF o Excel.</p>
            <a href="exportar_reportes.php" onclick="guardarScroll('card-siembra-inicial')"
              >Generar Reporte</a
            >
          </div>

        </section>
      </main>

      <footer>
        <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
      </footer>
    </div>

    <!-- Scripts Bootstrap y Scroll -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
      function guardarScroll(cardId) {
        localStorage.setItem("ultima_tarjeta_click", cardId);
      }
      document.addEventListener("DOMContentLoaded", function () {
        const cardId = localStorage.getItem("ultima_tarjeta_click");
        if (cardId) {
          const card = document.getElementById(cardId);
          if (card) card.scrollIntoView({ behavior: "smooth", block: "center" });
          localStorage.removeItem("ultima_tarjeta_click");
        }
      });
    </script>

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
