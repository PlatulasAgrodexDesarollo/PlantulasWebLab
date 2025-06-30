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

if ((int) $_SESSION['Rol'] !== 6) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Gerente de Producción de Laboratorio.</p>";
    exit;
}
// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

$query = "
SELECT 
  p.ID_Planificacion,
  p.Fecha_Planificacion,
  v.Especie,
  v.Nombre_Variedad,
  p.Cantidad_Proyectada,
  
  COALESCE(m.Total_Multiplicacion, 0) +
  COALESCE(e.Total_Enraizamiento, 0) +
  COALESCE(d.Total_Division, 0) AS Total_Producido,

  ROUND((
    COALESCE(m.Total_Multiplicacion, 0) +
    COALESCE(e.Total_Enraizamiento, 0) +
    COALESCE(d.Total_Division, 0)
  ) / p.Cantidad_Proyectada * 100, 2) AS Porcentaje_Cumplido

FROM planificacion_Produccion p

JOIN variedades v ON p.ID_Variedad = v.ID_Variedad

-- Multiplicación
LEFT JOIN (
  SELECT ID_Variedad, SUM(Cantidad_Dividida) AS Total_Multiplicacion
  FROM multiplicacion
  GROUP BY ID_Variedad
) m ON m.ID_Variedad = p.ID_Variedad

-- Enraizamiento
LEFT JOIN (
  SELECT ID_Variedad, SUM(Cantidad_Dividida) AS Total_Enraizamiento
  FROM enraizamiento
  GROUP BY ID_Variedad
) e ON e.ID_Variedad = p.ID_Variedad

-- División ECAS + Siembra_ECAS
LEFT JOIN (
  SELECT se.ID_Variedad, SUM(de.Cantidad_Dividida) AS Total_Division
  FROM division_ecas de
  JOIN siembra_ecas se ON de.ID_Siembra = se.ID_Siembra
  GROUP BY se.ID_Variedad
) d ON d.ID_Variedad = p.ID_Variedad

ORDER BY p.Fecha_Planificacion DESC
";

$result = mysqli_query($conn, $query);
?>


<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Planificación de Producción</title>
  <link rel="stylesheet" href="../style.css" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
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
          <img src="../logoplantulas.png" alt="Logo" width="130" height="124" />
        </a>
        <div>
          <h2>📋 Planificación de Producción</h2>
        </div>
      </div>

      <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="window.location.href='dashboard_gpl.php'">
              🏠 Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
    </header>

    <main class="container mt-4">
      <?php if (!empty($mensaje)) : ?>
        <p style="text-align:center; color:<?= strpos($mensaje, '✅') !== false ? 'green' : 'red' ?>;">
          <?= $mensaje ?>
        </p>
      <?php endif; ?>

      <form method="POST" class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Fecha de Planificación:</label>
          <input type="date" name="fecha_plan" class="form-control" required>
        </div>

        <div class="col-md-6">
          <label class="form-label">Variedad:</label>
          <select name="id_variedad" class="form-select" required>
            <option value="">-- Seleccionar variedad --</option>
            <?php while ($v = mysqli_fetch_assoc($variedades)) : ?>
              <option value="<?= $v['ID_Variedad'] ?>">
                <?= $v['Variedad'] ?> (<?= $v['Especie'] ?>)
              </option>
            <?php endwhile; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Cantidad a Producir:</label>
          <input type="number" name="cantidad" class="form-control" required>
        </div>

        <div class="col-md-6">
          <label class="form-label">Fecha Estimada de Siembra:</label>
          <input type="date" name="fecha_siembra" class="form-control">
        </div>

        <div class="col-md-6">
          <label class="form-label">Etapa Destino:</label>
          <select name="etapa" class="form-select" required>
            <option value="Multiplicación">Multiplicación</option>
            <option value="Enraizamiento">Enraizamiento</option>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Tasa de Multiplicación Promedio:</label>
          <input type="number" step="0.01" name="tasa" class="form-control">
        </div>

        <div class="col-md-6">
          <label class="form-label">Días entre Resiembra:</label>
          <input type="number" name="dias" class="form-control" value="30">
        </div>

        <?php
        function crearSelect($name, $label, $operadores) {
          echo "<div class='col-md-6'>";
          echo "<label class='form-label'>$label:</label>";
          echo "<select name='$name' class='form-select'>";
          echo "<option value=''>-- Seleccionar --</option>";
          mysqli_data_seek($operadores, 0);
          while ($op = mysqli_fetch_assoc($operadores)) {
            echo "<option value='{$op['ID_Operador']}'>{$op['Nombre']}</option>";
          }
          echo "</select></div>";
        }

        crearSelect('responsable_ejecucion', 'Responsable de Ejecución', $operadores);
        crearSelect('responsable_supervision', 'Responsable de Supervisión', $operadores);
        crearSelect('responsable_medio', 'Responsable de Medio Nutritivo', $operadores);
        crearSelect('responsable_acomodo', 'Responsable de Acomodo de Planta', $operadores);
        ?>

        <div class="col-12">
          <label class="form-label">Observaciones:</label>
          <textarea name="observaciones" class="form-control" rows="3"></textarea>
        </div>

        <div class="col-12">
          <button type="submit" class="btn btn-success">Guardar Planificación</button>
        </div>
      </form>
    </main>

    <footer class="text-center mt-4 mb-3">
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
