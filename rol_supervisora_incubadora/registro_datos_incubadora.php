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

// 3) Procesar el formulario
$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    date_default_timezone_set('America/Mexico_City');
    $fecha     = date('Y-m-d H:i:s');
    $turno     = $_POST['turno'];
    $temp_inf  = $_POST['temperatura_inferior'];
    $temp_med  = $_POST['temperatura_media'];
    $temp_sup  = $_POST['temperatura_superior'];
    $temp_max  = $_POST['temperatura_max'];
    $temp_min  = $_POST['temperatura_min'];
    $hum_sup   = $_POST['humedad_superior'];
    $hum_med   = $_POST['humedad_media'];
    $hum_inf   = $_POST['humedad_inferior'];
    $hum_max   = $_POST['humedad_max'];
    $hum_min   = $_POST['humedad_min'];
    $operador  = $_SESSION['ID_Operador'];

    // Validaciones de coherencia
    $errores = [];

    if ($temp_max < max($temp_sup, $temp_med, $temp_inf)) {
        $errores[] = "⚠️ La temperatura máxima debe ser mayor o igual a las temperaturas registradas.";
    }

    if ($temp_min > min($temp_sup, $temp_med, $temp_inf)) {
        $errores[] = "⚠️ La temperatura mínima debe ser menor o igual a las temperaturas registradas.";
    }

    if ($temp_min > $temp_max) {
        $errores[] = "⚠️ La temperatura mínima no puede ser mayor que la temperatura máxima.";
    }

    if ($hum_max < max($hum_sup, $hum_inf)) {
        $errores[] = "⚠️ La humedad máxima debe ser mayor o igual a las humedades registradas.";
    }

    if ($hum_min > min($hum_sup, $hum_inf)) {
        $errores[] = "⚠️ La humedad mínima debe ser menor o igual a las humedades registradas.";
    }

    if ($hum_min > $hum_max) {
        $errores[] = "⚠️ La humedad mínima no puede ser mayor que la humedad máxima.";
    }

    // Verificar si ya existe el turno registrado hoy
    $verifica = $conn->prepare("
        SELECT 1
          FROM registro_parametros_incubadora
         WHERE DATE(fecha_hora_registro) = CURDATE()
           AND turno = ?
    ");
    $verifica->bind_param("s", $turno);
    $verifica->execute();
    $verifica->store_result();

    if ($verifica->num_rows > 0) {
        $mensaje = "⚠️ Ya existe un registro para el turno '$turno' hoy.";
    } elseif (!empty($errores)) {
        $mensaje = implode('<br>', $errores);
    } else {
        // Registrar con fecha y hora exacta del sistema
$stmt = $conn->prepare("
    INSERT INTO registro_parametros_incubadora
      (fecha_hora_registro, turno, id_operador,
       temperatura_superior, temperatura_media, temperatura_inferior,
       temperatura_max, temperatura_min,
       humedad_superior, humedad_media, humedad_inferior,
       humedad_max, humedad_min)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    'ssidddddddddd',
    $fecha,
    $turno,
    $operador,
    $temp_sup,
    $temp_med,
    $temp_inf,
    $temp_max,
    $temp_min,
    $hum_sup,
    $hum_med, 
    $hum_inf,
    $hum_max,
    $hum_min
);

        if ($stmt->execute()) {
            $mensaje = '✅ Registro guardado exitosamente';
        } else {
            $mensaje = '❌ Error al guardar: ' . $stmt->error;
        }
    }
}


// 4) Obtener solo los registros de hoy
$result = $conn->query("
SELECT r.fecha_hora_registro, r.turno,
       r.temperatura_inferior, r.temperatura_media, r.temperatura_superior,
       r.temperatura_max, r.temperatura_min,
       r.humedad_superior, r.humedad_inferior,
       r.humedad_max, r.humedad_media, r.humedad_min,
       CONCAT(o.Nombre, ' ', o.Apellido_P, ' ', o.Apellido_M) AS operador
    FROM registro_parametros_incubadora r
    INNER JOIN operadores o ON r.id_operador = o.ID_Operador
    WHERE DATE(r.fecha_hora_registro) = CURDATE()
    ORDER BY r.fecha_hora_registro DESC
");

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Registro de Parámetros de Incubadora</title>
  <link rel="stylesheet" href="../style.css?v=<?=time();?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous" />
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>
<div class="contenedor-pagina">
  <header>
    <div class="encabezado d-flex align-items-center">
      <a class="navbar-brand me-3" href="dashboard_eism.php">
        <img src="../logoplantulas.png" alt="Logo" width="130" height="124"
             class="d-inline-block align-text-center" />
      </a>
      <div>
        <h2>Registro de Parámetros de Incubadora</h2>
        <p>Registra temperatura y humedad – 3 turnos diarios.</p>
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
<?php if ($mensaje): ?>
  <div class="alert 
              <?= str_starts_with($mensaje, '✅') ? 'alert-success' : (str_starts_with($mensaje, '❌') ? 'alert-danger' : 'alert-warning') ?>">
    <?= $mensaje ?>
  </div>
<?php endif; ?>


      <div class="form-header mb-4">
        <h2 class="text-center mb-3">Registrar temperatura y humedad</h2>
      </div>

      <div class="main-content">
        <!-- Formulario -->
        <form method="POST" class="formulario">
          <div class="form-left">

            <div class="form-group">
              <label for="fecha" class="form-label">Fecha</label>
              <input type="date" id="fecha" name="fecha" class="form-control"
                     value="<?= date('Y-m-d') ?>" readonly>
            </div>

            <div class="form-group">
              <label for="turno" class="form-label">Turno</label>
              <select id="turno" name="turno" class="form-control" required>
                <option value="">Seleccionar...</option>
                <option value="Mañana">Mañana</option>
                <option value="Tarde">Tarde</option>
                <option value="Noche">Noche</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Temperatura (Repisa Inferior)</label>
              <div class="input-group">
                <input type="number"
                       name="temperatura_inferior"
                       class="form-control"
                       step="0.01" min="15" max="40"
                       pattern="\d{1,2}(\.\d{1,2})?"
                       placeholder="0.00"
                       required>
                <span class="input-group-text">°C</span>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Temperatura (Repisa Media)</label>
              <div class="input-group">
                <input type="number"
                       name="temperatura_media"
                       class="form-control"
                       step="0.01" min="15" max="40"
                       pattern="\d{1,2}(\.\d{1,2})?"
                       placeholder="0.00"
                       required>
                <span class="input-group-text">°C</span>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Temperatura (Repisa Superior)</label>
              <div class="input-group">
                <input type="number"
                       name="temperatura_superior"
                       class="form-control"
                       step="0.01" min="15" max="40"
                       pattern="\d{1,2}(\.\d{1,2})?"
                       placeholder="0.00"
                       required>
                <span class="input-group-text">°C</span>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Humedad Relativa (Repisa Superior)</label>
              <div class="input-group">
                <input type="number"
                       name="humedad_superior"
                       class="form-control"
                       step="0.01" min="20" max="70"
                       pattern="\d{1,2}(\.\d{1,2})?"
                       placeholder="0.00"
                       required>
                <span class="input-group-text">%</span>
              </div>
            </div>
<div class="form-group">
  <label class="form-label">Humedad Relativa (Repisa Media)</label>
  <div class="input-group">
    <input type="number"
           name="humedad_media"
           class="form-control"
           step="0.01" min="20" max="70"
           pattern="\d{1,2}(\.\d{1,2})?"
           placeholder="0.00"
           required>
    <span class="input-group-text">%</span>
  </div>
</div>
            <div class="form-group">
              <label class="form-label">Humedad Relativa (Repisa Inferior)</label>
              <div class="input-group">
                <input type="number"
                       name="humedad_inferior"
                       class="form-control"
                       step="0.01" min="20" max="70"
                       pattern="\d{1,2}(\.\d{1,2})?"
                       placeholder="0.00"
                       required>
                <span class="input-group-text">%</span>
              </div>
            </div>
<div class="form-group">
  <label class="form-label">Temperatura Máxima General</label>
  <div class="input-group">
    <input type="number" name="temperatura_max" class="form-control" step="0.01" min="15" max="50" required>
    <span class="input-group-text">°C</span>
  </div>
</div>

<div class="form-group">
  <label class="form-label">Temperatura Mínima General</label>
  <div class="input-group">
    <input type="number" name="temperatura_min" class="form-control" step="0.01" min="15" max="50" required>
    <span class="input-group-text">°C</span>
  </div>
</div>

<div class="form-group">
  <label class="form-label">Humedad Máxima General</label>
  <div class="input-group">
    <input type="number" name="humedad_max" class="form-control" step="0.01" min="20" max="100" required>
    <span class="input-group-text">%</span>
  </div>
</div>

<div class="form-group">
  <label class="form-label">Humedad Mínima General</label>
  <div class="input-group">
    <input type="number" name="humedad_min" class="form-control" step="0.01" min="10" max="100" required>
    <span class="input-group-text">%</span>
  </div>
</div>

            <div class="d-grid gap-2 mt-4">
              <button type="submit" class="btn-submit">Guardar</button>
            </div>
          </div>
        </form>

        <!-- Historial del día -->
        <div class="form-right">
          <h2>Historial de hoy</h2>
          <div class="table-responsive">
            <table class="table table-striped table-sm">
              <thead>
                <tr>
                  <th>Fecha y Hora</th>
                  <th>Turno</th>
                  <th>Inf. (°C)</th>
                  <th>Med. (°C)</th>
                  <th>Sup. (°C)</th>
                  <th>Hum. Sup. (%)</th>
                  <th>Hum. Med. (%)</th>
                  <th>Hum. Inf. (%)</th>
                  <th>Temp Max (°C)</th>
                  <th>Temp Min (°C)</th>
                  <th>Hum Max (%)</th>
                  <th>Hum Min (%)</th>
                  <th>Registrado por:</th>
                </tr>
              </thead>
              <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
<tr>
  <td data-label="Fecha y Hora"><?= htmlspecialchars($row['fecha_hora_registro']) ?></td>
  <td data-label="Turno"><?= htmlspecialchars($row['turno']) ?></td>
  <td data-label="Inf. (°C)"><?= htmlspecialchars($row['temperatura_inferior']) ?></td>
  <td data-label="Med. (°C)"><?= htmlspecialchars($row['temperatura_media']) ?></td>
  <td data-label="Sup. (°C)"><?= htmlspecialchars($row['temperatura_superior']) ?></td>
  <td data-label="Hum. Sup. (%)"><?= htmlspecialchars($row['humedad_superior']) ?></td>
  <td><?= htmlspecialchars($row['humedad_media']) ?></td>
  <td data-label="Hum. Inf. (%)"><?= htmlspecialchars($row['humedad_inferior']) ?></td>
  <td><?= htmlspecialchars($row['temperatura_max']) ?></td>
<td><?= htmlspecialchars($row['temperatura_min']) ?></td>
<td><?= htmlspecialchars($row['humedad_max']) ?></td>
<td><?= htmlspecialchars($row['humedad_min']) ?></td>
  <td data-label="Registrado por"><?= htmlspecialchars($row['operador']) ?></td>
</tr>
                <?php endwhile; ?>
              </tbody>
            </table>
      </div>
    </section>
  </main>

  <footer>
    <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
  </footer>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // Campos de temperatura
  const tempInf = document.querySelector('[name="temperatura_inferior"]');
  const tempMed = document.querySelector('[name="temperatura_media"]');
  const tempSup = document.querySelector('[name="temperatura_superior"]');
  const tempMax = document.querySelector('[name="temperatura_max"]');
  const tempMin = document.querySelector('[name="temperatura_min"]');

  // Campos de humedad
  const humInf = document.querySelector('[name="humedad_inferior"]');
  const humMed = document.querySelector('[name="humedad_media"]');
  const humSup = document.querySelector('[name="humedad_superior"]');
  const humMax = document.querySelector('[name="humedad_max"]');
  const humMin = document.querySelector('[name="humedad_min"]');

  const allInputs = [
    tempInf, tempMed, tempSup, tempMax, tempMin,
    humInf, humMed, humSup, humMax, humMin
  ];

  // Inicialmente desactivar campos de max/min
  tempMax.disabled = true;
  tempMin.disabled = true;
  humMax.disabled = true;
  humMin.disabled = true;

  // Verifica si todos los campos base están completos
  function estaCompleto(arr) {
    return arr.every(i => i.value !== '' && !isNaN(parseFloat(i.value)));
  }

  // Activar/desactivar campos máximos y mínimos según disponibilidad
  function actualizarEstadoCampos() {
    const tempBases = [tempInf, tempMed, tempSup];
    const humBases = [humInf, humMed, humSup];

    const tempsOk = estaCompleto(tempBases);
    const humsOk = estaCompleto(humBases);

    tempMax.disabled = tempMin.disabled = !tempsOk;
    humMax.disabled = humMin.disabled = !humsOk;

    if (!tempsOk) {
      tempMax.value = '';
      tempMin.value = '';
    }
    if (!humsOk) {
      humMax.value = '';
      humMin.value = '';
    }
  }

  // Corregir en vivo valores mayores a 100
  const camposLimite = [
    'temperatura_inferior', 'temperatura_media', 'temperatura_superior',
    'temperatura_max', 'temperatura_min',
    'humedad_inferior', 'humedad_media', 'humedad_superior',
    'humedad_max', 'humedad_min'
  ];

  camposLimite.forEach(nombre => {
    const campo = document.querySelector(`[name="${nombre}"]`);
    if (!campo) return;

    campo.addEventListener('input', () => {
      const valor = parseFloat(campo.value);
      if (!isNaN(valor)) {
        if (valor > 100) campo.value = 100;
        if (valor < 0) campo.value = 0;
      }
      actualizarEstadoCampos();
    });
  });

  // Corrección lógica al salir de campos max/min
  function safeGetValues(inputs) {
    const values = inputs.map(i => parseFloat(i.value));
    return values.every(v => !isNaN(v)) ? values : null;
  }

  tempMax.addEventListener('blur', () => {
    const temps = safeGetValues([tempInf, tempMed, tempSup]);
    const tmax = parseFloat(tempMax.value);
    if (!temps || isNaN(tmax)) return;

    const max = Math.max(...temps);
    if (tmax < max) tempMax.value = max;
  });

  tempMin.addEventListener('blur', () => {
    const temps = safeGetValues([tempInf, tempMed, tempSup]);
    const tmin = parseFloat(tempMin.value);
    const tmax = parseFloat(tempMax.value);
    if (!temps || isNaN(tmin) || isNaN(tmax)) return;

    const min = Math.min(...temps);
    if (tmin > min) tempMin.value = min;
    if (tmin > tmax) tempMin.value = tmax;
  });

  humMax.addEventListener('blur', () => {
    const hums = safeGetValues([humInf, humMed, humSup]);
    const hmax = parseFloat(humMax.value);
    if (!hums || isNaN(hmax)) return;

    const max = Math.max(...hums);
    if (hmax < max) humMax.value = max;
  });

  humMin.addEventListener('blur', () => {
    const hums = safeGetValues([humInf, humMed, humSup]);
    const hmin = parseFloat(humMin.value);
    const hmax = parseFloat(humMax.value);
    if (!hums || isNaN(hmin) || isNaN(hmax)) return;

    const min = Math.min(...hums);
    if (hmin > min) humMin.value = min;
    if (hmin > hmax) humMin.value = hmax;
  });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>

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
