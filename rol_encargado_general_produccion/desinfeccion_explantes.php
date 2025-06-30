<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/Mexico_City');

// 1) Validar sesión y rol
require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['ID_Operador'])) {
    header('Location: ../login.php?mensaje=Debe iniciar sesión');
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

// Autocompletado AJAX para buscar variedades por Código o Nombre
if (isset($_GET['action']) && $_GET['action'] === 'buscar_variedad') {
    $term = $_GET['term'] ?? '';
    $sql = "SELECT ID_Variedad, Codigo_Variedad, Nombre_Variedad
            FROM variedades
            WHERE Codigo_Variedad LIKE ? OR Nombre_Variedad LIKE ?
            LIMIT 10";
    $stmt = $conn->prepare($sql);
    $like = "%{$term}%";
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $sugerencias = [];
    while ($row = $result->fetch_assoc()) {
        $sugerencias[] = [
            'id'    => $row['ID_Variedad'],
            'label' => $row['Codigo_Variedad'] . " - " . $row['Nombre_Variedad'],
            'value' => $row['Codigo_Variedad'] . " - " . $row['Nombre_Variedad']
        ];
    }
    echo json_encode($sugerencias);
    exit;
}

// Verificar si hay desinfección activa
$sql_activa = "SELECT * FROM desinfeccion_explantes
               WHERE Operador_Responsable = ? AND Estado_Desinfeccion = 'En proceso'
               ORDER BY FechaHr_Desinfeccion DESC
               LIMIT 1";
$stmt_activa = $conn->prepare($sql_activa);
$stmt_activa->bind_param("i", $ID_Operador);
$stmt_activa->execute();
$desinfeccion_activa = $stmt_activa->get_result()->fetch_assoc();

// Obtener información de la variedad activa
$info_variedad = null;
if ($desinfeccion_activa) {
    $id_variedad_activa = $desinfeccion_activa['ID_Variedad'];
    $sql_var = "SELECT Codigo_Variedad, Nombre_Variedad
                FROM variedades
                WHERE ID_Variedad = ?";
    $stmt_var = $conn->prepare($sql_var);
    $stmt_var->bind_param("i", $id_variedad_activa);
    $stmt_var->execute();
    $info_variedad = $stmt_var->get_result()->fetch_assoc();
}

// Iniciar desinfección
if (isset($_POST['iniciar'])) {
    $id_variedad         = (int) $_POST['id_variedad'];
    $explantes_iniciales = (int) $_POST['explantes_iniciales'];
    //$fecha_inicio        = $_POST['fecha_inicio'];
   $origen_explantes = strtoupper(htmlspecialchars(trim($_POST['origen_explantes'])));

    // Validar cantidad mínima y máxima de explantes aceptados
    if ($explantes_iniciales < 1 || $explantes_iniciales > 60) {
        $mensaje = "⚠️ La cantidad de explantes iniciales debe estar entre 1 y 60.";
    } else {
        // Verificar que la variedad exista
        $sql_check = "SELECT COUNT(*) AS existe FROM variedades WHERE ID_Variedad = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("i", $id_variedad);
        $stmt_check->execute();
        $resultado_check = $stmt_check->get_result()->fetch_assoc();
        $fecha_inicio = date('Y-m-d H:i:s');

        if ($resultado_check['existe'] == 0) {
            $mensaje = "❌ Error: La variedad seleccionada no existe.";
        } else {
          $sql_insert = "INSERT INTO desinfeccion_explantes
          (ID_Variedad, Explantes_Iniciales, FechaHr_Desinfeccion, Estado_Desinfeccion, Origen_Explantes, Operador_Responsable)
          VALUES (?, ?, ?, 'En proceso', ?, ?)";
        
        $stmt = $conn->prepare($sql_insert);
        $stmt->bind_param("iisss", $id_variedad, $explantes_iniciales, $fecha_inicio, $origen_explantes, $ID_Operador);

            if ($stmt->execute()) {
                header("Location: desinfeccion_explantes.php");
                exit;
            } else {
                $mensaje = "❌ Error al iniciar la desinfección.";
            }
        }
    }
}

// Finalizar desinfección
if (isset($_POST['finalizar'])) {
    $id            = (int) $_POST['id_desinfeccion'];
    $desinfectados = (int) $_POST['explantes_desinfectados'];
    //$fecha_fin     = $_POST['fecha_fin'];
    $estado_final  = $_POST['estado_final'];

    // Obtener los explantes iniciales desde la base de datos
    $sql_ini = "SELECT Explantes_Iniciales FROM desinfeccion_explantes
                WHERE ID_Desinfeccion = ? AND Operador_Responsable = ?";
    $stmt_ini = $conn->prepare($sql_ini);
    $stmt_ini->bind_param("ii", $id, $ID_Operador);
    $stmt_ini->execute();
    $res_ini = $stmt_ini->get_result();
    $row_ini = $res_ini->fetch_assoc();
    $fecha_fin = date('Y-m-d H:i:s'); 

    if ($row_ini) {
        $iniciales = (int) $row_ini['Explantes_Iniciales'];

        if ($desinfectados > $iniciales) {
            $mensaje = "⚠️ Los explantes desinfectados no pueden ser mayores que los iniciales.";
        } else {
            $sql_finalizar = "UPDATE desinfeccion_explantes
                              SET Explantes_Desinfectados = ?, HrFn_Desinfeccion = ?, Estado_Desinfeccion = ?
                              WHERE ID_Desinfeccion = ? AND Operador_Responsable = ?";
            $stmt = $conn->prepare($sql_finalizar);
            $stmt->bind_param("issii", $desinfectados, $fecha_fin, $estado_final, $id, $ID_Operador);

            if ($stmt->execute()) {
                header("Location: desinfeccion_explantes.php");
                exit;
            } else {
                $mensaje = "❌ Error al finalizar la desinfección.";
            }
        }
    } else {
        $mensaje = "❌ No se encontraron datos para validar los explantes iniciales.";
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Desinfección de Explantes</title>
  <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css" />

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
      <a class="navbar-brand"><img src="../logoplantulas.png" alt="Logo" width="130" height="124" /></a>
      <h2>Registro de Desinfección de explantes</h2>
      <div></div>
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

  <main>
    <?php if ($mensaje): ?>
      <div class="alert alert-info"><?= $mensaje ?></div>
    <?php endif; ?>

    <h3>🧪 Iniciar nueva desinfección de explantes</h3>
    <form method="POST" class="form-doble-columna">
      <div class="section">
        <label for="nombre_variedad">Variedad a trabajar:</label>
        <input type="text" id="nombre_variedad" name="nombre_variedad" required>
        <input type="hidden" id="id_variedad" name="id_variedad">

        <div class="mb-3">
        <label for="origen_explantes" class="form-label">Origen de los explantes</label>
        <input type="text" name="origen_explantes" id="origen_explantes" class="form-control" maxlength="100" required style="text-transform: uppercase;">
        </div>

        <label for="explantes_iniciales">Cantidad de Explantes Iniciales:</label>
        <input type="number" name="explantes_iniciales" required min="1" />

        <button type="submit" name="iniciar" class="btn-inicio">Iniciar Desinfección</button>
      </div>
    </form>

    <?php if ($desinfeccion_activa): ?>
      <h3>✅ Finalizar desinfección activa</h3>
      <form method="POST" class="form-doble-columna">
        <input type="hidden" name="id_desinfeccion" value="<?= $desinfeccion_activa['ID_Desinfeccion'] ?>">
        <div class="section">
          <p><strong>Variedad trabajada:</strong> <?= "{$info_variedad['Codigo_Variedad']} - {$info_variedad['Nombre_Variedad']}" ?> (ID: <?= $desinfeccion_activa['ID_Variedad'] ?>)</p>
          <p><strong>Origen de los Explantes:</strong> <?= htmlspecialchars($desinfeccion_activa['Origen_Explantes']) ?></p>
          <p><strong>Cantidad de Explantes Iniciales:</strong> <?= $desinfeccion_activa['Explantes_Iniciales'] ?></p>
          <p><strong>Fecha de inicio:</strong> <?= $desinfeccion_activa['FechaHr_Desinfeccion'] ?></p>

          <label for="explantes_desinfectados">Cantidad de Explantes Desinfectados:</label>
          <input type="number" name="explantes_desinfectados" required min="1" />

          <label for="estado_final">Estado final:</label>
          <select name="estado_final" required>
            <option value="">-- Selecciona --</option>
            <option value="Completado">✅ Completado</option>
            <option value="Fallido">❌ Fallido</option>
          </select>

          <button type="submit" name="finalizar" class="btn-final">Finalizar Desinfección</button>
        </div>
      </form>
    <?php endif; ?>
  </main>

  <footer>
    <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
  </footer>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script>
$(function() {
  $("#nombre_variedad").autocomplete({
    source: "desinfeccion_explantes.php?action=buscar_variedad",
    minLength: 2,
    select: function(event, ui) {
      $("#id_variedad").val(ui.item.id);
    }
  });
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
