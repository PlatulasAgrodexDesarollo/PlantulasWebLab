<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Validar sesión y rol
require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

date_default_timezone_set('America/Mexico_City');
$conn->query("SET time_zone = '-06:00'");

if (!isset($_SESSION['ID_Operador'])) {
    header('Location: /plantulas/login.php?mensaje=Debe iniciar sesión');
    exit;
}
$ID_Operador = (int) $_SESSION['ID_Operador'];

if ((int) $_SESSION['Rol'] !== 5) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Encargado General de Producción.</p>";
    exit;
}

// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

$mensaje = '';

// AJAX para autocompletar medios activos destinados a ECAS
if (isset($_GET['action']) && $_GET['action'] === 'buscar_medio') {
    $term = $_GET['term'] ?? '';
    $sql = "SELECT DISTINCT Codigo_Medio 
            FROM medios_nutritivos 
            WHERE Codigo_Medio LIKE ? 
              AND Estado = 'Activo'
            ORDER BY Codigo_Medio";
    $stmt = $conn->prepare($sql);
    $like = "%{$term}%";
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $result = $stmt->get_result();
    $res = [];
    while ($row = $result->fetch_assoc()) {
        $res[] = ['label' => $row['Codigo_Medio'], 'value' => $row['Codigo_Medio']];
    }
    echo json_encode($res);
    exit;
}

// Procesamiento del formulario
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $codigo_medio = trim($_POST['codigo_medio']);
    $fecha        = date('Y-m-d'); // Fecha automática
    $cantidad = (float) str_replace(',', '.', $_POST['cantidad_preparada']);
    $operador     = $ID_Operador;

    // Validar cantidad
    if ($cantidad < 1 || $cantidad > 100) {
        $mensaje = "❌ La cantidad debe estar entre 1 y 100 litros.";
    } else {
        // Validar que el medio nutritivo exista y esté activo para ECAS
        $chk = $conn->prepare("
            SELECT COUNT(*) AS total 
            FROM medios_nutritivos 
            WHERE Codigo_Medio = ? AND Estado = 'Activo'
        ");
        $chk->bind_param('s', $codigo_medio);
        $chk->execute();
        $res_chk = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($res_chk['total'] == 0) {
            $mensaje = "❌ El código «" . htmlspecialchars($codigo_medio) . "» no está registrado como medio nutritivo ACTIVO o No existe.";
        } else {
            // Insertar registro
            $sql = "INSERT INTO medios_nutritivos_madre 
                        (Codigo_Medio, Fecha_Preparacion, Cantidad_Preparada, Cantidad_Disponible, Estado, Operador_Responsable)
                    VALUES (?, ?, ?, ?, 'Disponible', ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssddi", $codigo_medio, $fecha, $cantidad, $cantidad, $operador);

            if ($stmt->execute()) {
                echo "<script>
                        alert('✅ Medio nutritivo registrado correctamente.');
                        window.location.href = 'preparacion_soluciones.php';
                      </script>";
                exit;
            } else {
                $mensaje = "❌ Error al registrar el medio: " . htmlspecialchars($stmt->error);
            }
            $stmt->close();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Preparación de Soluciones Madre</title>
  <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
  />
  <link
    rel="stylesheet"
    href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css"
  />
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>
  <div class="contenedor-borde">
    <header>
      <div class="encabezado d-flex align-items-center">
        <a class="navbar-brand" href="dashboard_egp.php">
          <img src="../logoplantulas.png" alt="Logo" width="130" height="124">
        </a>
        <div class="ms-3">
          <h2>Preparación de Soluciones Madre</h2>
          <p>Registro de la preparación de medios nutritivos madre.</p>
        </div>
      </div>

    <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_egp.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
    </header>

    <main class="container mt-4">
      <?php if ($mensaje): ?>
        <div class="alert alert-warning"><?= htmlspecialchars($mensaje) ?></div>
      <?php endif; ?>

      <div class="form-container">
        <h2>Soluciones Madre</h2>
        <form method="POST" action="preparacion_soluciones.php" class="row g-3">
<div class="row align-items-end g-3">
  <div class="col-md-4">
    <label for="codigo_medio" class="form-label">Código del Medio Nutritivo Madre</label>
    <input
      type="text"
      id="codigo_medio"
      name="codigo_medio"
      class="form-control"
      required
    >
  </div>

  <div class="col-md-4">
    <label for="fecha_preparacion" class="form-label">Fecha de Preparación</label>
    <input
      type="text"
      id="fecha_preparacion"
      name="fecha_preparacion"
      class="form-control"
      value="<?= date('Y-m-d') ?>"
      readonly
      style="background-color:#f8f9fa; cursor:not-allowed;"
    >
  </div>

  <div class="col-md-4">
    <label for="cantidad_preparada" class="form-label">Cantidad Preparada (L)</label>
    <input
  type="text"
  id="cantidad_preparada"
  name="cantidad_preparada"
  class="form-control"
  pattern="^(100(\.0{1,2})?|[1-9]?[0-9](\.\d{1,2})?)$"
  title="Solo se permite punto como separador decimal. Ej: 2.3, 11.11, 100.00"
  required
    >
  </div>
</div>


            <button type="submit" class="btn btn-primary">Registrar Preparación</button>
          </div>
        </form>
      </div>
    </main>

    <footer class="text-center mt-5">
      <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
    </footer>
  </div>

<script>
document.getElementById('cantidad_preparada').addEventListener('input', function () {
  // Reemplazar comas por puntos automáticamente
  this.value = this.value.replace(',', '.');

  // Eliminar cualquier carácter que no sea dígito o punto
  this.value = this.value.replace(/[^0-9.]/g, '');
});
</script>


  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
  <script>
    $(function () {
      $("#codigo_medio").autocomplete({
        source(request, response) {
          $.getJSON("preparacion_soluciones.php?action=buscar_medio", { term: request.term }, response);
        },
        minLength: 0,
        select(event, ui) {
          $("#codigo_medio").val(ui.item.value);
        }
      }).focus(function() {
        $(this).autocomplete("search", "");
      });
    });
  </script>
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
