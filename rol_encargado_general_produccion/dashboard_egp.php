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

if ((int) $_SESSION['Rol'] !== 5) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Encargado General de Producción.</p>";
    exit;
}

$hayCorrecciones = false;
$stmt = $conn->prepare("
    SELECT COUNT(*) AS n
      FROM proyecciones_lavado
     WHERE Estado_Flujo = 'correccion'
       AND ID_Creador   = ?
");
$stmt->bind_param('i', $ID_Operador);
$stmt->execute();
$hayCorrecciones = ($stmt->get_result()->fetch_assoc()['n'] ?? 0) > 0;
$stmt->close();

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
  <title>Panel Encargado General de Producción</title>
  <link rel="stylesheet" href="../style.css?v=<?= time() ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .highlight {
      animation: highlight 2s ease-out;
    }
    @keyframes highlight {
      from { background-color: #fffae6; }
      to   { background-color: transparent; }
    }
  </style>
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>
  <div class="contenedor-pagina panel-admin">
    <!-- HEADER -->
    <header>
      <div class="encabezado">
<img src="../logoplantulas.png"
     alt="Logo"
     width="130" height="124"
     style="cursor:<?= $volver1 ? 'pointer' : 'default' ?>"
     <?= $volver1 ? "onclick=\"window.location.href='../rol_administrador/volver_rol.php'\"" : '' ?>>
        <div>
          <h2>Encargado General de Producción</h2>
          <p>Panel de gestión y supervisión</p>
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

    <!-- CONTENIDO PRINCIPAL -->
    <main>
      <h3 class="mt-5 mb-3">🌱 Producción - Etapa 1 (ECAS)</h3>
      <section class="dashboard-grid">
        <div class="card card-ecas" data-card-id="desinfeccion">
          <h2>🧼 Desinfección de Explantes</h2>
          <p>Preparación inicial de explantes para el cultivo.</p>
          <a href="desinfeccion_explantes.php"
             onclick="rememberCard('desinfeccion')">
            Ir a Desinfección
          </a>
        </div>
        <div class="card" data-card-id="historial-desinfeccion">
          <h2>📄 Historial de Desinfecciones</h2>
          <p>Consulta todas las desinfecciones registradas.</p>
          <a href="historial_desinfeccion_explantes.php"
             onclick="rememberCard('historial-desinfeccion')">
            Ver Historial
          </a>
        </div>
        <div class="card card-ecas" data-card-id="siembra-inicial">
          <h2>📋 Registro de Siembra Inicial</h2>
          <p>Captura la siembra inicial de explantes tras la desinfección.</p>
          <a href="registro_siembra_ecas.php"
             onclick="rememberCard('siembra-inicial')">
            Registrar Siembra
          </a>
        </div>
        <div class="card card-ecas" data-card-id="divisiones">
          <h2>✂️ Divisiones de Explantes</h2>
          <p>Registra las divisiones hechas en ECAS.</p>
          <a href="divisiones_ecas.php"
             onclick="rememberCard('divisiones')">
            Registrar División
          </a>
        </div>
        <div class="card card-ecas" data-card-id="evaluacion">
          <h2>🧪 Registro de contaminación</h2>
          <p>Registra los explantes que se encontraron perdidos</p>
          <a href="evaluacion_ecas.php"
             onclick="rememberCard('evaluacion')">
            Evaluar Desarrollo
          </a>
        </div>
        <div class="card card-ecas" data-card-id="diseccion">
          <h2>🌿 Disección de Brotes</h2>
          <p>Registra el número de hojas separadas por brote y su siguiente medio nutritivo.</p>
          <a href="diseccion_hojas_ecas.php"
             onclick="rememberCard('diseccion')">
            Registrar Disección
          </a>
        </div>
        <div class="card card-ecas" data-card-id="envio-multiplicacion">
          <h2>📤 Envío de explantes a Multiplicación</h2>
          <p>Finaliza el proceso ECAS enviando brotes listos a multiplicación.</p>
          <a href="envio_multiplicacion.php"
             onclick="rememberCard('envio-multiplicacion')">
            Registrar Envío
          </a>
        </div>
        <!--
        <div class="card card-ecas" data-card-id="estadisticas-ecas">
          <h2>📈 Estadísticas de ECAS</h2>
          <p>Consulta métricas clave de desarrollo por variedad, generación y éxito.</p>
          <a href="estadisticas_ecas.php"
             onclick="rememberCard('estadisticas-ecas')">
            Ver Estadísticas
          </a>
        </div>
  -->
      </section>

      <h3 class="mt-5 mb-3">🔧 Tareas Generales</h3>
      <section class="dashboard-grid">

      <div class="card" data-card-id="proyeccion-lavado">
        <h2>🧼 Proyección de Lavado</h2>
        <p>Planifica los tuppers y brotes que se reservarán para lavado.</p>
          <a href="proyeccion_lavado.php"
            onclick="rememberCard('proyeccion-lavado')">
            Crear Proyección 
          </a>
      </div>

<?php if ($hayCorrecciones): ?>
    <div class="card" data-card-id="corregir-proyecciones">
      <h2>🔄 Corrección de Proyecciones</h2>
      <p>Atiende las proyecciones devueltas para ajuste.</p>
      <a href="corregir_proyecciones.php"
         onclick="rememberCard('corregir-proyecciones')">
        Corregir Proyecciones
      </a>
    </div>
<?php endif; ?>

        <div class="card" data-card-id="preparacion-soluciones">
          <h2>🧪 Preparación de Soluciones Madre</h2>
          <p>Registra la preparación de soluciones madre.</p>
          <a href="preparacion_soluciones.php"
             onclick="rememberCard('preparacion-soluciones')">
            Ir a Preparación
          </a>
        </div>
        <div class="card" data-card-id="inventario-soluciones">
          <h2>📈 Inventario de Soluciones Madre</h2>
          <p>Consulta la cantidad que hay de cada solución madre.</p>
          <a href="inventario_soluciones_madre.php"
             onclick="rememberCard('inventario-soluciones')">
            Ver Inventario
          </a>
        </div>
      </section>
    </main>

    <!-- FOOTER -->
    <footer>
      &copy; <?= date("Y"); ?> PLANTAS AGRODEX. Todos los derechos reservados.
    </footer>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const cards = document.querySelectorAll('.dashboard-grid .card');
      // Al hacer clic en cualquier enlace de tarjeta
      cards.forEach(card => {
        const link = card.querySelector('a');
        link.addEventListener('click', () => {
          const id = card.dataset.cardId;
          sessionStorage.setItem('lastCard', id);
        });
      });

      // Al cargar la página, leer y, si existe, hacer scroll y resaltar
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

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
