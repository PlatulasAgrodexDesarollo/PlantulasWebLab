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
    header('Location: ../login.php?mensaje=Debe iniciar sesión');
    exit;
}
$ID_Operador = (int) $_SESSION['ID_Operador'];

if ((int) $_SESSION['Rol'] !== 4) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Supervisora de Incubadora.</p>";
    exit;
}

// Obtener áreas ya asignadas como "Pendiente"
$areas_asignadas = [];
$res = $conn->query("SELECT Area FROM registro_limpieza WHERE Estado_Limpieza = 'Pendiente' AND DATE(Fecha) = CURDATE()");
while ($fila = $res->fetch_assoc()) {
    $areas_asignadas[] = $fila['Area'];
}

// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// Autocompletado AJAX de operadores (solo operadores reales con ID_Rol = 2)
if (isset($_GET['action']) && $_GET['action'] === 'buscar_operador') {
  $term = $_GET['term'] ?? '';
  $sql = "SELECT ID_Operador, CONCAT(Nombre, ' ', Apellido_P, ' ', Apellido_M) AS NombreCompleto 
          FROM operadores 
          WHERE CONCAT(Nombre, ' ', Apellido_P, ' ', Apellido_M) LIKE ? 
          AND ID_Rol = 2 AND Activo = 1
          LIMIT 10";
  $stmt = $conn->prepare($sql);
  $like = "%$term%";
  $stmt->bind_param("s", $like);
  $stmt->execute();
  $result = $stmt->get_result();

  $res = [];
  while ($row = $result->fetch_assoc()) {
      $res[] = [
          'id' => $row['ID_Operador'],
          'label' => $row['NombreCompleto'],
          'value' => $row['NombreCompleto']
      ];
  }
  echo json_encode($res);
  exit;
}

// Procesar asignación
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $id_operador = $_POST['id_operador'];
  $fecha = date('Y-m-d H:i:s');
  $hora_registro = date('Y-m-d H:i:s');
  $areas = isset($_POST['area']) && is_array($_POST['area']) ? $_POST['area'] : [];
  $estado = 'Pendiente';

  // Validar que el operador existe, está activo y tiene el rol correcto
  $validar = $conn->prepare("SELECT COUNT(*) FROM operadores WHERE ID_Operador = ? AND Activo = 1 AND ID_Rol = 2");
  $validar->bind_param("i", $id_operador);
  $validar->execute();
  $validar->bind_result($existe);
  $validar->fetch();
  $validar->close();

  if ($existe == 0) {
      echo "<script>alert('El operador seleccionado no existe, no está activo o no es operador.');</script>";
  } else {
$inserts = 0;
foreach ($areas as $area) {
    // Validar si el área ya fue asignada
    $verificar = $conn->prepare("SELECT COUNT(*) FROM registro_limpieza WHERE Area = ? AND Estado_Limpieza = 'Pendiente' AND DATE(Fecha) = CURDATE()");
    $verificar->bind_param("s", $area);
    $verificar->execute();
    $verificar->bind_result($ya_asignada);
    $verificar->fetch();
    $verificar->close();

    if ($ya_asignada == 0) {
        $stmt = $conn->prepare("INSERT INTO registro_limpieza (ID_Operador, ID_Asignador, Fecha, Area, Estado_Limpieza) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $id_operador, $ID_Operador, $fecha, $area, $estado);
        if ($stmt->execute()) {
            $inserts++;
        }
    }
}

$total = count($areas);
$rechazadas = $total - $inserts;

if ($inserts > 0) {
    $msg = "Se asignaron $inserts área(s) correctamente.";
    if ($rechazadas > 0) {
        $msg .= " $rechazadas ya estaban asignadas hoy.";
    }
    echo "<script>alert('$msg'); window.location.href='rol_limpieza.php';</script>";
    exit;
} else {
    echo "<script>alert('❌ Todas las áreas seleccionadas ya estaban asignadas hoy. Intenta con otras.');</script>";
}
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Asignación de Limpieza</title>
  <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
  <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
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
          <img src="../logoplantulas.png" alt="Logo" width="130" height="124" class="d-inline-block align-text-center" />
        </a>
        <div>
          <h2>Asignación de Limpieza</h2>
          <p>Registro de operadores responsables de la limpieza y áreas asignadas.</p>
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
      <div class="section">
        <h2>🧼 Asignar limpieza de áreas</h2>
        <form method="POST">
          <label for="operador_asignado">👤 Operador Asignado:</label>
          <input type="text" id="operador_asignado" name="operador_asignado" required readonly placeholder="Buscar operador...">
          <input type="hidden" id="id_operador" name="id_operador" required>

          <label for="fecha_de_asignacion">📅 Fecha de Asignación:</label>
          <input type="date" id="fecha_de_asignacion" name="fecha_de_asignacion" class="form-control" required readonly value="<?= date('Y-m-d') ?>">

          <label>🧽 Áreas a limpiar:</label>
<div class="form-check">
<?php
$areas_disponibles = [
  "1. Área común",
  "2. Baños",
  "3. Zona de secado de tupper",
  "4. Zona de almacenamiento de tupper",
  "5. Zona de tupper vacío",
  "6. Zona de cajas vacías y osmocis",
  "7. Incubador",
  "8. Zona de zapatos",
  "9. Área de preparación de medios",
  "10. Área de reactivos",
  "11. Siembras etapa 2",
  "12. Siembras etapa 3"
];

foreach ($areas_disponibles as $index => $area) {
    $disabled = in_array($area, $areas_asignadas) ? 'disabled' : '';
    echo "<div class='form-check'>
            <input class='form-check-input' type='checkbox' name='area[]' id='area_$index' value='$area' $disabled>
            <label class='form-check-label' for='area_$index'>$area</label>
          </div>";
}
?>
</div>

          <button type="submit" class="mt-3">✅ Asignar Limpieza</button>
        </form>
      </div>

      <div class="section">
        <h3>📂 Ver historial de asignaciones</h3>
        <button onclick="window.location.href='historial_limpieza.php'">📋 Ir al historial</button>
      </div>
    </main>

    <footer>
      <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
    </footer>
  </div>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
  <script>
    // Seleccion de operador
$(function () {
  $("#operador_asignado").autocomplete({
    source: "rol_limpieza.php?action=buscar_operador",
    minLength: 0, // Mostrar desde el primer caracter
    select: function (event, ui) {
      $("#operador_asignado").val(ui.item.value);
      $("#id_operador").val(ui.item.id);
    }
  }).focus(function () {
    // Fuerza a mostrar la lista cuando el campo recibe foco
    $(this).autocomplete("search", "");
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
