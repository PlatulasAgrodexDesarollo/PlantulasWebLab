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

if ((int) $_SESSION['Rol'] !== 5) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Sólo Encargado General de Producción.</p>";
    exit;
}

// 2) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 60 * 3;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// Consulta general para obtener la información de ECAS
$sql = "
    SELECT 
        V.Codigo_Variedad,
        V.Nombre_Variedad,
        DH.Generacion,
        MIN(S.Fecha_Siembra) AS Fecha_Siembra,
        MIN(DV.Fecha_Division) AS Fecha_Division,
        MIN(DH.Fecha_Diseccion) AS Fecha_Diseccion,
        MIN(AM.Fecha_Asignacion) AS Fecha_Envio,
        SUM(DH.Brotes_Generados) AS Total_Brotes
    FROM diseccion_hojas_ecas DH
    JOIN siembra_ecas S ON DH.ID_Siembra = S.ID_Siembra
    JOIN variedades V ON S.ID_Variedad = V.ID_Variedad
    LEFT JOIN division_ecas DV ON DH.ID_Siembra = DV.ID_Siembra
    LEFT JOIN asignaciones_multiplicacion AM ON DH.ID_Diseccion = AM.ID_Diseccion
    GROUP BY V.ID_Variedad, DH.Generacion
    ORDER BY V.Codigo_Variedad ASC, DH.Generacion ASC
";
$result = $conn->query($sql);
$estadisticas = $result->fetch_all(MYSQLI_ASSOC);

// Función para calcular días entre fechas
function diasEntre($fecha1, $fecha2) {
    if (empty($fecha1) || empty($fecha2) || $fecha1 == '0000-00-00' || $fecha2 == '0000-00-00') {
        return null;
    }
    $f1 = new DateTime($fecha1);
    $f2 = new DateTime($fecha2);
    return $f1->diff($f2)->days;
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Estadísticas de ECAS</title>
    <link rel="stylesheet" href="../style.css?v=<?=time();?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS         = <?= $nowTs           * 1000 ?>;
  </script>
</head>
<body>

<header>
    <div class="encabezado">
      <a class="navbar-brand" href="#"><img src="../logoplantulas.png" alt="Logo" width="130" height="124"></a>
      <h2>📊 Estadísticas de ECAS</h2>
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
        <?php if (count($estadisticas) > 0): ?>
            <div class="row">
            <?php foreach ($estadisticas as $row): 
                $dias_siembra_division = diasEntre($row['Fecha_Siembra'], $row['Fecha_Division']);
                $dias_division_diseccion = diasEntre($row['Fecha_Division'], $row['Fecha_Diseccion']);
                $dias_diseccion_envio = diasEntre($row['Fecha_Diseccion'], $row['Fecha_Envio']);
                $dias_totales = diasEntre($row['Fecha_Siembra'], $row['Fecha_Envio']);
                $brotes_por_dia = ($dias_totales && $row['Total_Brotes']) ? round($row['Total_Brotes'] / $dias_totales, 2) : 0;
            ?>
            <div class="col-md-6 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h5 class="card-title">🌱 Variedad: <?= htmlspecialchars($row['Codigo_Variedad']) ?> - <?= htmlspecialchars($row['Nombre_Variedad']) ?> (Gen <?= htmlspecialchars($row['Generacion']) ?>)</h5>
                        <hr>
                        <p><strong>📅 Fechas:</strong></p>
                        <ul>
                            <li>Siembra: <?= $row['Fecha_Siembra'] ?? 'N/D' ?></li>
                            <li>División: <?= $row['Fecha_Division'] ?? 'N/D' ?></li>
                            <li>Disección: <?= $row['Fecha_Diseccion'] ?? 'N/D' ?></li>
                            <li>Envío: <?= $row['Fecha_Envio'] ?? 'N/D' ?></li>
                        </ul>
                        <p><strong>⏳ Tiempos:</strong></p>
                        <ul>
                            <li>Días Siembra → División: <?= $dias_siembra_division !== null ? $dias_siembra_division . ' días' : 'Sin datos' ?></li>
                            <li>Días División → Disección: <?= $dias_division_diseccion !== null ? $dias_division_diseccion . ' días' : 'Sin datos' ?></li>
                            <li>Días Disección → Envío: <?= $dias_diseccion_envio !== null ? $dias_diseccion_envio . ' días' : 'Sin datos' ?></li>
                            <li><strong>Total días en ECAS: <?= $dias_totales !== null ? $dias_totales . ' días' : 'Sin datos' ?></strong></li>
                        </ul>
                        <p><strong>🌟 Resultados:</strong></p>
                        <ul>
                            <li>Total Brotes Generados: <?= $row['Total_Brotes'] ?></li>
                            <li>Brotes Promedio por Día: <?= $brotes_por_dia ?></li>
                        </ul>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <div class="card p-4 mt-5">
                <h4 class="text-center mb-4">📈 Gráfica de Brotes Generados por Variedad</h4>
                <canvas id="graficaBrotes"></canvas>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                No hay registros de ECAS disponibles para mostrar.
            </div>
        <?php endif; ?>
    </main>

    <footer class="text-center mt-5">
        <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
    </footer>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/plantulas/session_manager.php';if (count($estadisticas) > 0): ?>
<script>
// Preparar datos para Chart.js
const labels = <?= json_encode(array_map(function($row) {
    return $row['Codigo_Variedad'] . " Gen " . $row['Generacion'];
}, $estadisticas)) ?>;

const datos = <?= json_encode(array_map(function($row) {
    return $row['Total_Brotes'];
}, $estadisticas)) ?>;

// Crear gráfica
const ctx = document.getElementById('graficaBrotes').getContext('2d');
const grafica = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [{
            label: 'Brotes Generados',
            data: datos,
            backgroundColor: 'rgba(75, 192, 192, 0.6)',
            borderColor: 'rgba(75, 192, 192, 1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return ' Brotes: ' + context.raw;
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                title: { display: true, text: 'Cantidad de Brotes' }
            },
            x: {
                title: { display: true, text: 'Variedad y Generación' }
            }
        }
    }
});
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/plantulas/session_manager.php';endif; ?>

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
