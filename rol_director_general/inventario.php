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

if ((int) $_SESSION['Rol'] !== 11) {
    echo "<p class=\"error\">⚠️ Acceso denegado. Solo Director General.</p>";
    exit;
}

// Manejo de la semana seleccionada
$semanaActual = date('W'); // Semana actual del año
$anioActual = date('Y');   // Año actual

// Obtener semana y año de la URL o usar la actual
$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : $semanaActual;
$anioSeleccionado = isset($_GET['anio']) ? (int)$_GET['anio'] : $anioActual;

// Validar y ajustar valores
if ($semanaSeleccionada < 1) {
    $semanaSeleccionada = 52;
    $anioSeleccionado--;
} elseif ($semanaSeleccionada > 52) {
    $semanaSeleccionada = 1;
    $anioSeleccionado++;
}

// Calcular fechas de inicio y fin de la semana seleccionada
$fechaInicioSemana = new DateTime();
$fechaInicioSemana->setISODate($anioSeleccionado, $semanaSeleccionada);
$fechaInicioSemana->setTime(0, 0, 0);

$fechaFinSemana = clone $fechaInicioSemana;
$fechaFinSemana->modify('+6 days');
$fechaFinSemana->setTime(23, 59, 59);

// 1) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 600 * 40;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// Nueva consulta SQL con detalles por variedad y filtro por semana
$consultaDetallada = "
    SELECT 
        'Multiplicación' AS Etapa,
        v.Nombre_Variedad AS Variedad,
        m.Fecha_Siembra,
        DATEDIFF(CURDATE(), m.Fecha_Siembra) AS Dias_Incubacion,
        CASE 
            WHEN DATEDIFF(CURDATE(), m.Fecha_Siembra) BETWEEN 0 AND 20 THEN '0-20 días'
            WHEN DATEDIFF(CURDATE(), m.Fecha_Siembra) BETWEEN 21 AND 40 THEN '20-40 días'
            ELSE 'Más de 40 días'
        END AS Rango_Dias,
        m.Tuppers_Llenos AS Cantidad_Tuppers
    FROM multiplicacion m
    JOIN variedades v ON m.ID_Variedad = v.ID_Variedad
    WHERE m.Extraido_Lavado = 0
    AND m.Fecha_Siembra BETWEEN ? AND ?

    UNION ALL

    SELECT 
        'Enraizamiento' AS Etapa,
        v.Nombre_Variedad AS Variedad,
        e.Fecha_Siembra,
        DATEDIFF(CURDATE(), e.Fecha_Siembra) AS Dias_Incubacion,
        CASE 
            WHEN DATEDIFF(CURDATE(), e.Fecha_Siembra) BETWEEN 0 AND 20 THEN '0-20 días'
            WHEN DATEDIFF(CURDATE(), e.Fecha_Siembra) BETWEEN 21 AND 40 THEN '20-40 días'
            ELSE 'Más de 40 días'
        END AS Rango_Dias,
        e.Tuppers_Llenos AS Cantidad_Tuppers
    FROM enraizamiento e
    JOIN variedades v ON e.ID_Variedad = v.ID_Variedad
    WHERE e.Extraido_Lavado = 0
    AND e.Fecha_Siembra BETWEEN ? AND ?

    ORDER BY Etapa, FIELD(Rango_Dias, '0-20 días', '20-40 días', 'Más de 40 días'), Variedad;
";

// Preparar y ejecutar la consulta con los parámetros de fecha
$stmt = $conn->prepare($consultaDetallada);
if (!$stmt) {
    die("Error al preparar la consulta: " . $conn->error);
}

$fechaInicioStr = $fechaInicioSemana->format('Y-m-d');
$fechaFinStr = $fechaFinSemana->format('Y-m-d');

$stmt->bind_param("ssss", $fechaInicioStr, $fechaFinStr, $fechaInicioStr, $fechaFinStr);
$stmt->execute();
$resultadoDetallado = $stmt->get_result();

$resumenDetallado = [];
$totalesPorEtapa = ['Multiplicación' => 0, 'Enraizamiento' => 0];
$totalesPorRango = [
    'Multiplicación' => ['0-20 días' => 0, '20-40 días' => 0, 'Más de 40 días' => 0],
    'Enraizamiento' => ['0-20 días' => 0, '20-40 días' => 0, 'Más de 40 días' => 0]
];
$totalGeneral = 0;

if ($resultadoDetallado) {
    while ($fila = $resultadoDetallado->fetch_assoc()) {
        $resumenDetallado[] = $fila;
        
        // Calcular totales
        $etapa = $fila['Etapa'];
        $rango = $fila['Rango_Dias'];
        $tuppers = (int)$fila['Cantidad_Tuppers'];
        
        $totalesPorEtapa[$etapa] += $tuppers;
        $totalesPorRango[$etapa][$rango] += $tuppers;
        $totalGeneral += $tuppers;
    }
} else {
    die("Error en la consulta: " . $conn->error);
}

$stmt->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Director General</title>
  <link rel="stylesheet" href="../style.css?v=<?= time() ?>" />
  
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous" />
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS           = <?= $nowTs           * 1000 ?>;
  </script>
  <!-- Librerías para generar PDF -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
  <style>
    .table-totals {
        background-color: #e9f7ef;
        font-weight: bold;
    }
    .table-grand-total {
        background-color: #d1ecf1;
        font-weight: bold;
        font-size: 1.1em;
    }
    .scrollable-card {
        max-height: 300px;
        overflow-y: auto;
    }
    .week-navigation {
        background-color: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
  </style>
</head>
<body>
  <div class="contenedor-pagina panel-admin">
    <header>
      <div class="encabezado">
        <img src="../logoplantulas.png"
             alt="Logo"
             width="130" height="124"
             style="cursor:<?= $volver1 ? 'pointer' : 'default' ?>"
             <?= $volver1 ? "onclick=\"location.href='../rol_administrador/volver_rol.php'\"" : '' ?>>
        <div>
          <h2>Inventario</h2>
          <p>Planta de etapa 2 y 3.</p>
        </div>
      </div>

      <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="location.href='dashboard_director.php'">🏠 Volver al Inicio</button>
            </div>
          </div>
        </nav>
      </div>
    </header>

<main class="container mt-4">

  <!-- Controles de navegación por semana -->
  <div class="week-navigation">
    <div class="row">
      <div class="col-md-8">
        <div class="d-flex align-items-center">
          <h4 class="mb-0 me-3">
            Semana <?= $semanaSeleccionada ?> del <?= $anioSeleccionado ?>
          </h4>
          <div class="btn-group">
            <a href="?semana=<?= $semanaSeleccionada - 1 ?>&anio=<?= $semanaSeleccionada == 1 ? $anioSeleccionado - 1 : $anioSeleccionado ?>"
               class="btn btn-outline-primary">
              <i class="bi bi-chevron-left"></i>
            </a>
            <a href="?semana=<?= $semanaActual ?>&anio=<?= $anioActual ?>"
               class="btn btn-outline-primary <?= ($semanaSeleccionada == $semanaActual && $anioSeleccionado == $anioActual) ? 'active' : '' ?>">
              Hoy
            </a>
            <a href="?semana=<?= $semanaSeleccionada + 1 ?>&anio=<?= $semanaSeleccionada == 52 ? $anioSeleccionado + 1 : $anioSeleccionado ?>"
               class="btn btn-outline-primary">
              <i class="bi bi-chevron-right"></i>
            </a>
          </div>
        </div>
        <p class="text-muted mt-2">
          Del <?= $fechaInicioSemana->format('d/m/Y') ?> al <?= $fechaFinSemana->format('d/m/Y') ?>
        </p>
      </div>
      <div class="col-md-4 text-end">
        <button id="boton-pdf-final" class="btn btn-danger">
          <i class="bi bi-file-earmark-pdf"></i> Generar PDF
        </button>
      </div>
    </div>
  </div>

  <!-- Sección de Resúmenes -->
  <div class="row mb-4">
    <!-- Resumen por Variedad -->
    <div class="col-md-6 mb-3">
      <div class="card h-100">
        <div class="card-header bg-success text-white">
          <i class="bi bi-tree"></i> Tuppers por Variedad
        </div>
        <div class="card-body scrollable-card">
          <ul class="list-group list-group-flush">
            <?php
            $totalesPorVariedad = [];
            foreach ($resumenDetallado as $fila) {
                $variedad = $fila['Variedad'];
                $tuppers = (int)$fila['Cantidad_Tuppers'];
                if (!isset($totalesPorVariedad[$variedad])) {
                    $totalesPorVariedad[$variedad] = 0;
                }
                $totalesPorVariedad[$variedad] += $tuppers;
            }
            arsort($totalesPorVariedad);
            foreach ($totalesPorVariedad as $variedad => $total): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              <?= htmlspecialchars($variedad) ?>
              <span class="badge bg-success rounded-pill">
                <?= number_format($total) ?>
              </span>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
    
    <!-- Resumen General -->
    <div class="col-md-6 mb-3">
      <div class="card h-100">
        <div class="card-header bg-info text-white">
          <i class="bi bi-bar-chart"></i> Resumen General
        </div>
        <div class="card-body">
          <div class="d-flex flex-column">
            <div class="mb-3">
              <h5 class="card-title">Total Tuppers</h5>
              <p class="display-6"><?= number_format($totalGeneral) ?></p>
            </div>
            <div>
              <h6>Multiplicación: <?= number_format($totalesPorEtapa['Multiplicación']) ?></h6>
              <h6>Enraizamiento: <?= number_format($totalesPorEtapa['Enraizamiento']) ?></h6>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabla detallada -->
  <div class="table-responsive">
    <table class="table table-bordered table-striped">
      <thead class="table-dark">
        <tr>
          <th>Etapa</th>
          <th>Variedad</th>
          <th>Fecha Siembra</th>
          <th>Días Incubación</th>
          <th>Rango Días</th>
          <th>Tuppers</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($resumenDetallado)): ?>
          <tr>
            <td colspan="6" class="text-center">No hay datos disponibles para esta semana</td>
          </tr>
        <?php else: 
          $currentEtapa = null;
          foreach ($resumenDetallado as $fila): 
            if ($currentEtapa !== $fila['Etapa']): 
              $currentEtapa = $fila['Etapa'];
        ?>
          <tr class="table-info">
            <td colspan="5"><strong><?= htmlspecialchars($currentEtapa) ?></strong></td>
            <td><strong><?= number_format($totalesPorEtapa[$currentEtapa]) ?></strong></td>
          </tr>
        <?php endif; ?>
          <tr>
            <td><?= htmlspecialchars($fila['Etapa']) ?></td>
            <td><?= htmlspecialchars($fila['Variedad']) ?></td>
            <td><?= htmlspecialchars($fila['Fecha_Siembra']) ?></td>
            <td><?= htmlspecialchars($fila['Dias_Incubacion']) ?></td>
            <td><?= htmlspecialchars($fila['Rango_Dias']) ?></td>
            <td><?= number_format($fila['Cantidad_Tuppers']) ?></td>
          </tr>
        <?php endforeach; ?>
        
        <!-- Totales por rango de días -->
        <tr class="table-totals">
          <td colspan="2"><strong>Totales por Rango</strong></td>
          <td colspan="3">0-20 días</td>
          <td>
            <?= number_format(
                $totalesPorRango['Multiplicación']['0-20 días'] + 
                $totalesPorRango['Enraizamiento']['0-20 días']
            ) ?>
          </td>
        </tr>
        <tr class="table-totals">
          <td colspan="2">&nbsp;</td>
          <td colspan="3">20-40 días</td>
          <td>
            <?= number_format(
                $totalesPorRango['Multiplicación']['20-40 días'] + 
                $totalesPorRango['Enraizamiento']['20-40 días']
            ) ?>
          </td>
        </tr>
        <tr class="table-totals">
          <td colspan="2">&nbsp;</td>
          <td colspan="3">Más de 40 días</td>
          <td>
            <?= number_format(
                $totalesPorRango['Multiplicación']['Más de 40 días'] + 
                $totalesPorRango['Enraizamiento']['Más de 40 días']
            ) ?>
          </td>
        </tr>
        
        <!-- Total general -->
        <tr class="table-grand-total">
          <td colspan="5" class="text-end"><strong>TOTAL GENERAL</strong></td>
          <td><strong><?= number_format($totalGeneral) ?></strong></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</main>

    <footer>
      <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
    </footer>
  </div>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

<script>
  function rememberCard(id) {
    sessionStorage.setItem('lastCard', id);
  }

  document.addEventListener('DOMContentLoaded', () => {
    const limpiarBtn = document.getElementById('limpiar-busqueda');
    const inputBusqueda = document.getElementById('busqueda');

    if (limpiarBtn && inputBusqueda) {
      limpiarBtn.addEventListener('click', () => {
        inputBusqueda.value = '';
        window.location.href = window.location.pathname;
      });
    }

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

  <!-- Modal de advertencia de sesión -->
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
      document.getElementById('keepalive-btn').addEventListener('click', cerrarModalYReiniciar);
    }

    function cerrarModalYReiniciar() {
      const modal = document.getElementById('session-warning');
      if (modal) modal.remove();
      reiniciarTimers();
      fetch('../keepalive.php', { credentials: 'same-origin' }).catch(() => {});
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

// Función para generar el PDF
    function generarPDFProduccion() {
      try {
        // Verificar que las librerías estén cargadas
        if (typeof window.jspdf === 'undefined') {
          throw new Error('La librería jsPDF no está cargada correctamente');
        }
        
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({
          orientation: 'landscape',
          unit: 'mm'
        });
        
        // Título del documento
        const title = "Reporte de Producción - Etapas 2 y 3";
        const subtitle = `Semana <?= $semanaSeleccionada ?> del <?= $anioSeleccionado ?> (<?= $fechaInicioSemana->format('d/m/Y') ?> al <?= $fechaFinSemana->format('d/m/Y') ?>)`;
        const date = new Date().toLocaleDateString();
        const footer = `Generado el ${date}`;
        
        // Agregar título
        doc.setFontSize(18);
        doc.text(title, 105, 15, { align: 'center' });
        doc.setFontSize(12);
        doc.text(subtitle, 105, 22, { align: 'center' });
        doc.text(footer, 105, 29, { align: 'center' });
        
        // Resumen General
        doc.setFontSize(14);
        doc.text("Resumen General", 14, 35);
        
        const generalData = [
          ["Total Tuppers", "<?= number_format($totalGeneral) ?>"],
          ["Multiplicación", "<?= number_format($totalesPorEtapa['Multiplicación']) ?>"],
          ["Enraizamiento", "<?= number_format($totalesPorEtapa['Enraizamiento']) ?>"]
        ];
        
        doc.autoTable({
          startY: 40,
          head: [['Concepto', 'Cantidad']],
          body: generalData,
          theme: 'grid',
          headStyles: { 
            fillColor: [41, 128, 185],
            textColor: [255, 255, 255]
          }
        });
        
        // Detalle Completo
        doc.setFontSize(14);
        doc.text("Detalle Completo", 14, doc.autoTable.previous.finalY + 15);
        
        // Preparar datos de la tabla
        const tableData = [
          ['Etapa', 'Variedad', 'Fecha Siembra', 'Días Incubación', 'Rango Días', 'Tuppers']
        ];
        
        <?php foreach ($resumenDetallado as $fila): ?>
          tableData.push([
            '<?= htmlspecialchars($fila['Etapa']) ?>',
            '<?= htmlspecialchars($fila['Variedad']) ?>',
            '<?= htmlspecialchars($fila['Fecha_Siembra']) ?>',
            '<?= htmlspecialchars($fila['Dias_Incubacion']) ?>',
            '<?= htmlspecialchars($fila['Rango_Dias']) ?>',
            '<?= number_format($fila['Cantidad_Tuppers']) ?>'
          ]);
        <?php endforeach; ?>
        
        // Crear tabla
        doc.autoTable({
          startY: doc.autoTable.previous.finalY + 20,
          head: [tableData[0]],
          body: tableData.slice(1),
          theme: 'grid',
          headStyles: { 
            fillColor: [41, 128, 185],
            textColor: [255, 255, 255]
          },
          margin: { horizontal: 14 },
          styles: { 
            fontSize: 8,
            cellPadding: 2
          },
          columnStyles: {
            0: { cellWidth: 20 },
            1: { cellWidth: 30 },
            2: { cellWidth: 20 },
            3: { cellWidth: 20 },
            4: { cellWidth: 20 },
            5: { cellWidth: 15 }
          },
          didDrawPage: function(data) {
            // Agregar número de página
            const pageCount = doc.internal.getNumberOfPages();
            doc.setFontSize(10);
            doc.text(`Página ${data.pageNumber} de ${pageCount}`, 200, 200, {
              align: 'right'
            });
          }
        });
        
        // Guardar el PDF
        doc.save(`Reporte_Produccion_Semana_<?= $semanaSeleccionada ?>_<?= $anioSeleccionado ?>.pdf`);
        
      } catch (error) {
        console.error('Error al generar PDF:', error);
        alert('Error al generar el PDF: ' + error.message);
      }
    }

    // Configurar el botón de PDF
    document.addEventListener('DOMContentLoaded', function() {
      const pdfBtn = document.getElementById('boton-pdf-final');
      if (pdfBtn) {
        pdfBtn.addEventListener('click', function() {
          // Cambiar apariencia durante la generación
          const originalHTML = this.innerHTML;
          this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generando...';
          this.disabled = true;
          
          // Pequeño retraso para permitir la actualización visual
          setTimeout(() => {
            try {
              generarPDFProduccion();
            } catch (error) {
              console.error('Error al generar PDF:', error);
              alert('Error al generar el PDF: ' + error.message);
            } finally {
              // Restaurar botón
              this.innerHTML = originalHTML;
              this.disabled = false;
            }
          }, 100);
        });
      }
    });
  </script>
</body>
</html>