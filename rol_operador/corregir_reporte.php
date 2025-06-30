<?php
// 0) Mostrar errores (solo en desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) Validar sesión y rol
require_once __DIR__ . '/../session_manager.php';
require_once __DIR__ . '/../db.php';

// Autocompletado AJAX para buscar variedad
if (isset($_GET['action']) && $_GET['action'] === 'buscar_variedad') {
    $term = $_GET['term'] ?? '';
    $sql = "SELECT ID_Variedad, Codigo_Variedad, Nombre_Variedad, Especie 
            FROM variedades 
            WHERE Estado = 'Activa' AND (Codigo_Variedad LIKE ? OR Nombre_Variedad LIKE ?) LIMIT 10";
    $stmt = $conn->prepare($sql);
    $like = "%$term%";
    $stmt->bind_param("ss", $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $sugerencias = [];
    while ($row = $result->fetch_assoc()) {
        $sugerencias[] = [
            'id' => $row['ID_Variedad'],
            'especie' => $row['Especie'],
            'label' => $row['Codigo_Variedad'] . " - " . $row['Nombre_Variedad'],
            'value' => $row['Codigo_Variedad'] . " - " . $row['Nombre_Variedad']
        ];
    }
    echo json_encode($sugerencias);
    exit; // 🔥 Este exit es clave: para que no pase a las demás validaciones
}

if (!isset($_SESSION['ID_Operador'])) {
    header('Location: ../login.php?mensaje=Debe iniciar sesión');
    exit;
}
$ID_Operador = (int) $_SESSION['ID_Operador'];

if ((int) $_SESSION['Rol'] !== 2) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Solo Operador.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// Verificar que se reciban los parámetros tipo e id
if (!isset($_GET['tipo']) || !isset($_GET['id'])) {
    echo "Parámetros inválidos.";
    exit();
}

$tipo = $_GET['tipo'];
$id = $_GET['id'];

// Tipos permitidos
$allowedTypes = ['multiplicacion', 'enraizamiento'];
if (!in_array($tipo, $allowedTypes)) {
    echo "Tipo inválido.";
    exit();
}

// Procesar la actualización si se envía el formulario
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Recibimos los valores (los inputs que no fueron editables se envían vía hidden)
    $tasa = $_POST['Tasa_Multiplicacion'] ?? null;
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $tasa)) {
    echo "<script>alert('❌ La tasa debe tener hasta 2 decimales y usar punto como separador. Ejemplo: 3.25'); history.back();</script>";
    exit;
}

if ($tasa < 1 || $tasa > 50) {
    echo "<script>alert('❌ La tasa debe estar entre 1.00 y 50.00'); history.back();</script>";
    exit;
}
    $cantidad = $_POST['Cantidad_Dividida'] ?? null;
    $tuppersLlenos = $_POST['Tuppers_Llenos'] ?? null;
    $tuppersVacios = $_POST['Tuppers_Desocupados'] ?? null;
    $ID_Variedad = $_POST['ID_Variedad'] ?? $reporte['ID_Variedad']; // Valor enviado o el actual

// Validar existencia del ID_Variedad
$stmtVer = $conn->prepare("SELECT 1 FROM variedades WHERE ID_Variedad = ?");
$stmtVer->bind_param("i", $ID_Variedad);
$stmtVer->execute();
$stmtVer->store_result();
if ($stmtVer->num_rows === 0) {
    echo "<script>alert('❌ La variedad seleccionada no existe.'); history.back();</script>";
    exit();
}
$stmtVer->close();

    // Se asume que luego de la corrección se vuelve a poner el estado a "Pendiente"
    // y se limpian los campos de observaciones y los campos rechazados.
    if ($tipo === "multiplicacion") {
      $stmt = $conn->prepare("UPDATE multiplicacion 
    SET ID_Variedad = ?, Tasa_Multiplicacion = ?, Cantidad_Dividida = ?, Tuppers_Llenos = ?, Tuppers_Desocupados = ?, 
        Estado_Revision = 'Pendiente', Observaciones_Revision = NULL, Campos_Rechazados = NULL 
    WHERE ID_Multiplicacion = ?");
      $stmt->bind_param("iiiiii", $ID_Variedad, $tasa, $cantidad, $tuppersLlenos, $tuppersVacios, $id);
    } else { // enraizamiento
      $stmt = $conn->prepare("UPDATE enraizamiento 
    SET ID_Variedad = ?, Tasa_Multiplicacion = ?, Cantidad_Dividida = ?, Tuppers_Llenos = ?, Tuppers_Desocupados = ?, 
        Estado_Revision = 'Pendiente', Observaciones_Revision = NULL, Campos_Rechazados = NULL 
    WHERE ID_Enraizamiento = ?");
      $stmt->bind_param("iiiiii", $ID_Variedad, $tasa, $cantidad, $tuppersLlenos, $tuppersVacios, $id);
    }
    $stmt->execute();
    echo "<script>alert('Reporte corregido exitosamente.'); window.location.href='dashboard_cultivo.php';</script>";
    exit();
}

// Si es GET, se obtiene el reporte desde la base de datos
if ($tipo === "multiplicacion") {
    $stmt = $conn->prepare("SELECT M.*, V.Codigo_Variedad, V.Nombre_Variedad 
        FROM multiplicacion M 
        LEFT JOIN variedades V ON M.ID_Variedad = V.ID_Variedad 
        WHERE ID_Multiplicacion = ?");
    $stmt->bind_param("i", $id);
} else {
    $stmt = $conn->prepare("SELECT E.*, V.Codigo_Variedad, V.Nombre_Variedad 
        FROM enraizamiento E 
        LEFT JOIN variedades V ON E.ID_Variedad = V.ID_Variedad 
        WHERE ID_Enraizamiento = ?");
    $stmt->bind_param("i", $id);
}
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo "Reporte no encontrado.";
    exit();
}
$reporte = $result->fetch_assoc();

// Decodificar el campo de campos rechazados (se espera un JSON con un arreglo de nombres de campos)
$camposRechazados = [];
if (!empty($reporte['Campos_Rechazados'])) {
    $camposRechazados = json_decode($reporte['Campos_Rechazados'], true);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Corregir Reporte</title>
    <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
      /* Estilo para inputs readonly */
      .readonly {
          background-color: #e9ecef;
      }
    </style>
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
        <img src="../logoplantulas.png" alt="Logo" width="130" height="124">
      </a>
      <h2>Corregir Reporte - <?= ucfirst($tipo) ?></h2>
    </div>

    <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_cultivo.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
  </header>
  
  <main>
    <div class="container mt-4">
      <p>Se te ha retornado el reporte con las siguientes observaciones. Solo puedes corregir las áreas marcadas como incorrectas.</p>
      <form method="POST">
        <input type="hidden" name="tipo" value="<?= $tipo ?>">
        <input type="hidden" name="id" value="<?= $id ?>">

        <!-- Campo: Variedad -->
      <div class="mb-3">
        <label class="form-label">Buscar Variedad</label>
          <?php if (in_array('Variedad', $camposRechazados)): ?>
            <input type="text" id="nombre_variedad" class="form-control" placeholder="Buscar variedad..." value="<?= $reporte['Codigo_Variedad'] . ' - ' . $reporte['Nombre_Variedad'] ?>" required autocomplete="off">
            <input type="hidden" id="id_variedad" name="ID_Variedad" value="<?= $reporte['ID_Variedad'] ?>">
          <?php else: ?>
            <input type="text" class="form-control readonly" value="<?= $reporte['Codigo_Variedad'] . ' - ' . $reporte['Nombre_Variedad'] ?>" disabled>
            <input type="hidden" name="ID_Variedad" value="<?= $reporte['ID_Variedad'] ?>">
          <?php endif; ?>
      </div>

        <!-- Datos generales: no editables -->
        <div class="mb-3">
            <label class="form-label">Fecha de Siembra</label>
            <input type="text" class="form-control readonly" value="<?= $reporte['Fecha_Siembra'] ?>" disabled>
        </div>

        <!-- Campo: Tasa de Multiplicación -->
        <div class="mb-3">
            <label class="form-label">Tasa de Multiplicación</label>
            <?php if (in_array('Tasa_Multiplicacion', $camposRechazados)): ?>
                <input type="number" name="Tasa_Multiplicacion" class="form-control"
       value="<?= $reporte['Tasa_Multiplicacion'] ?>"
       min="1" max="50" step="0.01" inputmode="decimal" required>
            <?php else: ?>
                <input type="number" class="form-control readonly" value="<?= $reporte['Tasa_Multiplicacion'] ?>" disabled>
                <input type="hidden" name="Tasa_Multiplicacion" value="<?= $reporte['Tasa_Multiplicacion'] ?>">
            <?php endif; ?>
        </div>

        <!-- Campo: Cantidad Dividida -->
        <div class="mb-3">
            <label class="form-label">Cantidad Dividida</label>
            <?php if (in_array('Cantidad_Dividida', $camposRechazados)): ?>
                <input type="number" name="Cantidad_Dividida" class="form-control" value="<?= $reporte['Cantidad_Dividida'] ?>" required>
            <?php else: ?>
                <input type="number" class="form-control readonly" value="<?= $reporte['Cantidad_Dividida'] ?>" disabled>
                <input type="hidden" name="Cantidad_Dividida" value="<?= $reporte['Cantidad_Dividida'] ?>">
            <?php endif; ?>
        </div>

        <!-- Campo: Tuppers Llenos -->
        <div class="mb-3">
            <label class="form-label">Tuppers Llenos</label>
            <?php if (in_array('Tuppers_Llenos', $camposRechazados)): ?>
                <input type="number" name="Tuppers_Llenos" class="form-control" value="<?= $reporte['Tuppers_Llenos'] ?>" required>
            <?php else: ?>
                <input type="number" class="form-control readonly" value="<?= $reporte['Tuppers_Llenos'] ?>" disabled>
                <input type="hidden" name="Tuppers_Llenos" value="<?= $reporte['Tuppers_Llenos'] ?>">
            <?php endif; ?>
        </div>

        <!-- Campo: Tuppers Vacíos -->
        <div class="mb-3">
            <label class="form-label">Tuppers Vacíos</label>
            <?php if (in_array('Tuppers_Desocupados', $camposRechazados)): ?>
                <input type="number" name="Tuppers_Desocupados" class="form-control" value="<?= $reporte['Tuppers_Desocupados'] ?>" required>
            <?php else: ?>
                <input type="number" class="form-control readonly" value="<?= $reporte['Tuppers_Desocupados'] ?>" disabled>
                <input type="hidden" name="Tuppers_Desocupados" value="<?= $reporte['Tuppers_Desocupados'] ?>">
            <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-primary">Enviar Corrección</button>
      </form>
    </div>
  </main>
  <footer>
    <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
  </footer>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">

<script>
$(function () {
  $("#nombre_variedad").autocomplete({
    source: "corregir_reporte.php?action=buscar_variedad",
    minLength: 2,
    select: function (event, ui) {
      $("#id_variedad").val(ui.item.id);
    }
  });

$('form').on('submit', function () {
  // Solo validar si el campo es editable (existe y no está deshabilitado)
  const campoVariedadEditable = $('#nombre_variedad').is(':enabled');

  if (campoVariedadEditable && !$('#id_variedad').val()) {
    alert('❌ Por favor selecciona una variedad válida desde la lista sugerida.');
    $('#nombre_variedad').addClass('is-invalid').focus();
    return false;
  }

  $('#nombre_variedad').removeClass('is-invalid');
});
});
</script>

</body>
</html>
