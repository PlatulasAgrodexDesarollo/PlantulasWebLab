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

  // 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
  $sessionLifetime = 60 * 3;   // 180 s
  $warningOffset   = 60 * 1;   // 60 s
  $nowTs           = time();

  if ($_SERVER["REQUEST_METHOD"] === "POST") {
      $tipo = $_POST["tipo"];
      $id = intval($_POST["id"]);
      $accion = $_POST["accion"];
      $observacion = $_POST["observacion"] ?? null;
      $campos = isset($_POST["campos_rechazados"]) ? json_encode($_POST["campos_rechazados"]) : null;

      if ($tipo === "multiplicacion") {
          if ($accion === "verificar") {
              $stmt = $conn->prepare("UPDATE multiplicacion SET Estado_Revision = 'Verificado' WHERE ID_Multiplicacion = ?");
              $stmt->bind_param("i", $id);
              $stmt->execute();

              // Obtener datos del reporte
              $sql_datos = "SELECT ID_Variedad, Operador_Responsable, Fecha_Siembra, Tuppers_Llenos FROM multiplicacion WHERE ID_Multiplicacion = ?";
              $stmt_datos = $conn->prepare($sql_datos);
              $stmt_datos->bind_param("i", $id);
              $stmt_datos->execute();
              $datos = $stmt_datos->get_result()->fetch_assoc();

              if ($datos) {
                  $hoy = date('Y-m-d'); // Fecha actual de validación
                  $etapa = 2; // Multiplicación

                  // Comprobar si ya existe lote
                  $sql_check = "SELECT COUNT(*) AS existe FROM lotes WHERE Fecha = ? AND ID_Variedad = ? AND ID_Operador = ? AND ID_Etapa = ?";
                  $check = $conn->prepare($sql_check);
                  $check->bind_param("siii", $hoy, $datos['ID_Variedad'], $datos['Operador_Responsable'], $etapa);
                  $check->execute();
                  $existe = $check->get_result()->fetch_assoc();

                  if ($existe['existe'] == 0 && $datos['Tuppers_Llenos'] > 0) {
                      $insert = $conn->prepare("INSERT INTO lotes (Fecha, ID_Variedad, ID_Operador, ID_Etapa) VALUES (?, ?, ?, ?)");
                      $insert->bind_param("siii", $hoy, $datos['ID_Variedad'], $datos['Operador_Responsable'], $etapa);
                      $insert->execute();

                      $id_lote_creado = $conn->insert_id;

                      // Relacionar reporte con ID_Lote
                      $update_lote = $conn->prepare("UPDATE multiplicacion SET ID_Lote = ? WHERE ID_Multiplicacion = ?");
                      $update_lote->bind_param("ii", $id_lote_creado, $id);
                      $update_lote->execute();
                  }
              }
          } else {
              $stmt = $conn->prepare("UPDATE multiplicacion SET Estado_Revision = 'Rechazado', Observaciones_Revision = ?, Campos_Rechazados = ? WHERE ID_Multiplicacion = ?");
              $observacion = htmlspecialchars(strip_tags(trim($observacion)), ENT_QUOTES, 'UTF-8');
              $stmt->bind_param("ssi", $observacion, $campos, $id);
              $stmt->execute();
          }
      }

      if ($tipo === "enraizamiento") {
          if ($accion === "verificar") {
              $stmt = $conn->prepare("UPDATE enraizamiento SET Estado_Revision = 'Verificado' WHERE ID_Enraizamiento = ?");
              $stmt->bind_param("i", $id);
              $stmt->execute();

              // Obtener datos del reporte
              $sql_datos = "SELECT ID_Variedad, Operador_Responsable, Fecha_Siembra, Tuppers_Llenos FROM enraizamiento WHERE ID_Enraizamiento = ?";
              $stmt_datos = $conn->prepare($sql_datos);
              $stmt_datos->bind_param("i", $id);
              $stmt_datos->execute();
              $datos = $stmt_datos->get_result()->fetch_assoc();

              if ($datos) {
                  $hoy = date('Y-m-d'); // Fecha actual de validación
                  $etapa = 3; // Enraizamiento

                  // Comprobar si ya existe lote
                  $sql_check = "SELECT COUNT(*) AS existe FROM lotes WHERE Fecha = ? AND ID_Variedad = ? AND ID_Operador = ? AND ID_Etapa = ?";
                  $check = $conn->prepare($sql_check);
                  $check->bind_param("siii", $hoy, $datos['ID_Variedad'], $datos['Operador_Responsable'], $etapa);
                  $check->execute();
                  $existe = $check->get_result()->fetch_assoc();

                  if ($existe['existe'] == 0 && $datos['Tuppers_Llenos'] > 0) {
                      $insert = $conn->prepare("INSERT INTO lotes (Fecha, ID_Variedad, ID_Operador, ID_Etapa) VALUES (?, ?, ?, ?)");
                      $insert->bind_param("siii", $hoy, $datos['ID_Variedad'], $datos['Operador_Responsable'], $etapa);
                      $insert->execute();

                      $id_lote_creado = $conn->insert_id;

                      // Relacionar reporte con ID_Lote
                      $update_lote = $conn->prepare("UPDATE enraizamiento SET ID_Lote = ? WHERE ID_Enraizamiento = ?");
                      $update_lote->bind_param("ii", $id_lote_creado, $id);
                      $update_lote->execute();
                  }
              }
          } else {
              $stmt = $conn->prepare("UPDATE enraizamiento SET Estado_Revision = 'Rechazado', Observaciones_Revision = ?, Campos_Rechazados = ? WHERE ID_Enraizamiento = ?");
              $stmt->bind_param("ssi", $observacion, $campos, $id);
              $stmt->execute();
          }
      }

      echo "<script>window.location.href='reportes_produccion.php';</script>";
      exit();
  }

  // CONSULTAS para mostrar reportes pendientes
  $sql_multiplicacion = "SELECT M.ID_Multiplicacion, V.Codigo_Variedad, V.Nombre_Variedad, M.Fecha_Siembra, M.Tasa_Multiplicacion,
            M.Cantidad_Dividida, M.Tuppers_Llenos, M.Tuppers_Desocupados, M.Estado_Revision,
            O.Nombre AS Nombre_Operador
      FROM multiplicacion M
      LEFT JOIN variedades V ON M.ID_Variedad = V.ID_Variedad
      LEFT JOIN operadores O ON M.Operador_Responsable = O.ID_Operador
      WHERE M.Estado_Revision = 'Pendiente'";

  $sql_enraizamiento = "SELECT E.ID_Enraizamiento, V.Codigo_Variedad, V.Nombre_Variedad, E.Fecha_Siembra, E.Tasa_Multiplicacion,
            E.Cantidad_Dividida, E.Tuppers_Llenos, E.Tuppers_Desocupados, E.Estado_Revision,
            O.Nombre AS Nombre_Operador
      FROM enraizamiento E
      LEFT JOIN variedades V ON E.ID_Variedad = V.ID_Variedad
      LEFT JOIN operadores O ON E.Operador_Responsable = O.ID_Operador
      WHERE E.Estado_Revision = 'Pendiente'";

  $result_multiplicacion = $conn->query($sql_multiplicacion);
  $result_enraizamiento = $conn->query($sql_enraizamiento);
  ?>

  <!DOCTYPE html>
  <html lang="es">
  <head>
    <meta charset="utf-8" />
    <title>Verificación de Reportes de Siembra</title>
    <link rel="stylesheet" href="../style.css?v=<?= time(); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous" />
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
        <h2>Verificación de Reportes de Siembra</h2>
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
      <div class="form-container">
        <div class="form-center">
          <h2>Reportes Pendientes de Verificación</h2>
          <div class="table-responsive">
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>Operador</th>
                <th>Variedad Trabajada</th>
                <th>Cantidad de Brotes Totales</th>
                <th>Fecha de Siembra</th>
                <th>Tasa de Multiplicación</th>
                <th>Tuppers Llenos</th>
                <th>Tuppers Vacíos</th>
                <th>Estado del Reporte</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($row = $result_multiplicacion->fetch_assoc()): ?>
  <tr>
    <td data-label="Operador"><?= $row['Nombre_Operador'] ?></td>
    <td data-label="Variedad"><?= $row['Codigo_Variedad'] . " - " . $row['Nombre_Variedad'] ?></td>
    <td data-label="Cantidad"><?= $row['Cantidad_Dividida'] ?></td>
    <td data-label="Fecha"><?= $row['Fecha_Siembra'] ?></td>
    <td data-label="Tasa"><?= $row['Tasa_Multiplicacion'] ?></td>
    <td data-label="Tuppers llenos"><?= $row['Tuppers_Llenos'] ?></td>
    <td data-label="Tuppers vacíos"><?= $row['Tuppers_Desocupados'] ?></td>
    <td data-label="Estado"><?= $row['Estado_Revision'] ?></td>
    <td data-label="Acción">
      <div class="botones-contenedor">
        <form method="POST" class="form-boton">
          <input type="hidden" name="tipo" value="multiplicacion">
          <input type="hidden" name="id" value="<?= $row['ID_Multiplicacion'] ?>">
          <input type="hidden" name="accion" value="verificar">
          <button type="submit" class="save-button verificar">✔ Verificar</button>
        </form>
        <button type="button" class="save-button incorrecto" 
                data-tipo="multiplicacion" 
                data-id="<?= $row['ID_Multiplicacion'] ?>" 
                onclick="mostrarRechazoModal(this)">✖ Incorrecto</button>
      </div>
    </td>
  </tr>
<?php endwhile; ?>

<?php while ($row = $result_enraizamiento->fetch_assoc()): ?>
  <tr>
    <td data-label="Operador"><?= $row['Nombre_Operador'] ?></td>
    <td data-label="Variedad"><?= $row['Codigo_Variedad'] . " - " . $row['Nombre_Variedad'] ?></td>
    <td data-label="Cantidad"><?= $row['Cantidad_Dividida'] ?></td>
    <td data-label="Fecha"><?= $row['Fecha_Siembra'] ?></td>
    <td data-label="Tasa"><?= $row['Tasa_Multiplicacion'] ?></td>
    <td data-label="Tuppers llenos"><?= $row['Tuppers_Llenos'] ?></td>
    <td data-label="Tuppers vacíos"><?= $row['Tuppers_Desocupados'] ?></td>
    <td data-label="Estado"><?= $row['Estado_Revision'] ?></td>
    <td data-label="Acción">
      <div class="botones-contenedor">
        <form method="POST" class="form-boton">
          <input type="hidden" name="tipo" value="enraizamiento">
          <input type="hidden" name="id" value="<?= $row['ID_Enraizamiento'] ?>">
          <input type="hidden" name="accion" value="verificar">
          <button type="submit" class="save-button verificar">✔ Verificar</button>
        </form>
        <button type="button" class="save-button incorrecto" 
                data-tipo="enraizamiento" 
                data-id="<?= $row['ID_Enraizamiento'] ?>" 
                onclick="mostrarRechazoModal(this)">✖ Incorrecto</button>
      </div>
    </td>
  </tr>
<?php endwhile; ?>

            </tbody>
          </table>
          </div>
        </div>
      </div>
    </main>

    <footer>
      <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
    </footer>
  </div>

  <!-- Modal para rechazo -->
  <div class="modal fade" id="rechazoModal" tabindex="-1" aria-labelledby="rechazoModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="POST" id="rechazoForm" onsubmit="return confirmarRechazo(this);">
          <div class="modal-header">
            <h5 class="modal-title" id="rechazoModalLabel">Rechazo de Reporte</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="tipo" id="rechazoTipo" value="">
            <input type="hidden" name="id" id="rechazoId" value="">
            <input type="hidden" name="accion" value="rechazar">

  <div class="mb-3">
    <label class="form-label">¿Qué es lo que se encuentra incorrecto?</label>
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="campos_rechazados[]" value="Variedad" id="rechazo_variedad">
      <label class="form-check-label" for="rechazo_variedad">Variedad Trabajada</label>
    </div>
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="campos_rechazados[]" value="Tasa_Multiplicacion" id="rechazo_tasa">
      <label class="form-check-label" for="rechazo_tasa">Tasa de multiplicación</label>
    </div>
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="campos_rechazados[]" value="Cantidad_Dividida" id="rechazo_cantidad">
      <label class="form-check-label" for="rechazo_cantidad">Cantidad de brotes totales</label>
    </div>
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="campos_rechazados[]" value="Tuppers_Llenos" id="rechazo_llenos">
      <label class="form-check-label" for="rechazo_llenos">Cantidad de Tuppers llenos</label>
    </div>
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="campos_rechazados[]" value="Tuppers_Desocupados" id="rechazo_vacios">
      <label class="form-check-label" for="rechazo_vacios">Cantidad de Tuppers vacíos</label>
    </div>
  </div>

            <div class="mb-3">
              <label for="observacion" class="form-label">Describa el motivo del rechazo</label>
              <textarea name="observacion" id="observacion" class="form-control" placeholder="Motivo del rechazo" required></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary">Enviar rechazo</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS Bundle (incluye Popper) -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
  
  <script>
 function mostrarRechazoModal(btn) {
  // Establecer los valores ocultos según el botón clickeado
  document.getElementById('rechazoTipo').value = btn.getAttribute('data-tipo');
  document.getElementById('rechazoId').value = btn.getAttribute('data-id');

  // Limpiar campos anteriores
  document.getElementById('observacion').value = "";
  document.querySelectorAll("input[name='campos_rechazados[]']").forEach(cb => cb.checked = false);

  // Mostrar el modal usando la API de Bootstrap
  var modalEl = document.getElementById('rechazoModal');
  var modal = new bootstrap.Modal(modalEl);
  modal.show();
}


  function confirmarRechazo(form) {
    const motivo = form.querySelector("textarea[name='observacion']").value.trim();
    const checkboxes = form.querySelectorAll("input[name='campos_rechazados[]']:checked");
    
    if (!motivo) {
      alert("Debes ingresar una observación antes de rechazar.");
      return false;
    }
    
  if (checkboxes.length === 0) {
    alert("Debes seleccionar al menos un campo que está incorrecto.");
    return false;
  }
    
    return true;
  }
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
