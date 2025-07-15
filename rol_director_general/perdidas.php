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

// 1) Variables para el modal de sesión (3 min inactividad, aviso 1 min antes)
$sessionLifetime = 600 * 40;   // 180 s
$warningOffset   = 60 * 1;   // 60 s
$nowTs           = time();

// 2) Consulta de pérdidas de laboratorio
$consultaPerdidas = "
    SELECT 
        pl.ID_Perdida,
        pl.Fecha_Perdida,
        pl.Tipo_Entidad,
        pl.Cantidad_Perdida,
        pl.Tuppers_Perdidos,
        pl.Brotes_Perdidos,
        pl.Motivo,
        oe.Nombre AS Operador_Entidad,
        oc.Nombre AS Operador_Chequeo
    FROM 
        perdidas_laboratorio pl
    LEFT JOIN operadores oe ON pl.Operador_Entidad = oe.ID_Operador
    LEFT JOIN operadores oc ON pl.Operador_Chequeo = oc.ID_Operador
    ORDER BY 
        pl.Fecha_Perdida DESC
";

$resultadoPerdidas = $conn->query($consultaPerdidas);
$perdidas = [];

if ($resultadoPerdidas) {
    while ($fila = $resultadoPerdidas->fetch_assoc()) {
        $perdidas[] = $fila;
    }
} else {
    die("Error en la consulta: " . $conn->error);
}

// Calcular totales
$totalTuppersPerdidos = 0;
$totalBrotesPerdidos = 0;
$totalPerdidas = 0;

foreach ($perdidas as $perdida) {
    $totalTuppersPerdidos += (int)($perdida['Tuppers_Perdidos'] ?? 0);
    $totalBrotesPerdidos += (int)($perdida['Brotes_Perdidos'] ?? 0);
    $totalPerdidas += (int)($perdida['Cantidad_Perdida'] ?? 0);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Director General - Pérdidas de Laboratorio</title>
  <link rel="stylesheet" href="../style.css?v=<?= time() ?>" />
  
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous" />
  <!-- Librerías para generar PDF -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
  <script>
    const SESSION_LIFETIME = <?= $sessionLifetime * 1000 ?>;
    const WARNING_OFFSET   = <?= $warningOffset   * 1000 ?>;
    let START_TS           = <?= $nowTs           * 1000 ?>;
  </script>
  <style>
    .table-responsive {
        max-height: 600px;
        overflow-y: auto;
    }
    .table-totals {
        background-color: #f8f9fa;
        font-weight: bold;
    }
    .badge-danger {
        background-color: #dc3545;
    }
    .badge-warning {
        background-color: #ffc107;
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
          <h2>Pérdidas de Laboratorio</h2>
          <p>Registro de pérdidas en el laboratorio</p>
        </div>
      </div>

      <div class="barra-navegacion">
        <nav class="navbar bg-body-tertiary">
          <div class="container-fluid">
            <div class="Opciones-barra">
              <button onclick="location.href='dashboard_director.php'" class="btn btn-primary">
                <i class="bi bi-arrow-left"></i> Volver al Inicio
              </button>
            </div>
          </div>
        </nav>
      </div>
    </header>

<main class="container mt-4">

  <!-- Sección de Resúmenes -->
  <div class="row mb-4">
    <!-- Resumen General -->
    <div class="col-md-4 mb-3">
      <div class="card h-100">
        <div class="card-header bg-danger text-white">
          <i class="bi bi-exclamation-triangle"></i> Total Pérdidas
        </div>
        <div class="card-body">
          <div class="d-flex flex-column">
            <div class="mb-3">
              <h5 class="card-title">Total Registros</h5>
              <p class="display-6"><?= count($perdidas) ?></p>
            </div>
            <div>
              <h5 class="card-title">Total Tuppers Perdidos</h5>
              <p class="display-6"><?= number_format($totalTuppersPerdidos) ?></p>
            </div>
            <div>
              <h5 class="card-title">Total Brotes Perdidos</h5>
              <p class="display-6"><?= number_format($totalBrotesPerdidos) ?></p>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Resumen por Tipo de Entidad -->
    <div class="col-md-4 mb-3">
      <div class="card h-100">
        <div class="card-header bg-warning text-dark">
          <i class="bi bi-tags"></i> Pérdidas por Tipo
        </div>
        <div class="card-body">
          <ul class="list-group list-group-flush">
            <?php
            $perdidasPorTipo = [];
            foreach ($perdidas as $perdida) {
                $tipo = $perdida['Tipo_Entidad'];
                if (!isset($perdidasPorTipo[$tipo])) {
                    $perdidasPorTipo[$tipo] = 0;
                }
                $perdidasPorTipo[$tipo]++;
            }
            arsort($perdidasPorTipo);
            foreach ($perdidasPorTipo as $tipo => $cantidad): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              <?= htmlspecialchars($tipo) ?>
              <span class="badge bg-warning rounded-pill">
                <?= number_format($cantidad) ?>
              </span>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
    
    <!-- Resumen por Operador -->
    <div class="col-md-4 mb-3">
      <div class="card h-100">
        <div class="card-header bg-info text-white">
          <i class="bi bi-person"></i> Pérdidas por Operador
        </div>
        <div class="card-body">
          <ul class="list-group list-group-flush">
            <?php
            $perdidasPorOperador = [];
            foreach ($perdidas as $perdida) {
                $operador = $perdida['Operador_Entidad'] ?? 'Desconocido';
                if (!isset($perdidasPorOperador[$operador])) {
                    $perdidasPorOperador[$operador] = 0;
                }
                $perdidasPorOperador[$operador]++;
            }
            arsort($perdidasPorOperador);
            foreach ($perdidasPorOperador as $operador => $cantidad): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              <?= htmlspecialchars($operador) ?>
              <span class="badge bg-info rounded-pill">
                <?= number_format($cantidad) ?>
              </span>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Tabla de pérdidas -->
  <div class="card card-lista">
    <div class="card-header bg-light">
      <h4 class="mb-0">Detalle de Pérdidas Registradas</h4>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead class="sticky-top bg-light">
                <tr>
                    <th>ID</th>
                    <th>Fecha Pérdida</th>
                    <th>Tipo Entidad</th>
                    <th>Operador</th>
                    <th>Verificado por</th>
                    <th>Tuppers</th>
                    <th>Brotes</th>
                    <th>Motivo</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($perdidas)): ?>
                <tr>
                    <td colspan="8" class="text-center py-4">
                        <i class="bi bi-check-circle text-muted" style="font-size: 2rem;"></i>
                        <p class="mt-2">No se encontraron registros de pérdidas</p>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($perdidas as $perdida): ?>
                    <tr>
                        <td><?= htmlspecialchars($perdida['ID_Perdida']) ?></td>
                        <td><?= htmlspecialchars($perdida['Fecha_Perdida']) ?></td>
                        <td><?= htmlspecialchars($perdida['Tipo_Entidad']) ?></td>
                        <td><?= htmlspecialchars($perdida['Operador_Entidad'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($perdida['Operador_Chequeo'] ?? 'No verificado') ?></td>
                        <td>
                            <span class="badge badge-danger">
                                <?= isset($perdida['Tuppers_Perdidos']) ? number_format((int)$perdida['Tuppers_Perdidos']) : '0' ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-warning">
                                 <?= isset($perdida['Brotes_Perdidos']) ? number_format((int)$perdida['Brotes_Perdidos']) : '0' ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($perdida['Motivo']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <!-- Fila de totales -->
                    <tr class="table-totals">
                        <td colspan="5" class="text-end"><strong>TOTALES</strong></td>
                        <td><strong><?= number_format($totalTuppersPerdidos) ?></strong></td>
                        <td><strong><?= number_format($totalBrotesPerdidos) ?></strong></td>
                        <td></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
  </div>
</main>

    <footer>
      <p>&copy; 2025 PLANTAS AGRODEX. Todos los derechos reservados.</p>
    </footer>
  </div>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const limpiarBtn = document.getElementById('limpiar-busqueda');
    const inputBusqueda = document.getElementById('busqueda');

    if (limpiarBtn && inputBusqueda) {
      limpiarBtn.addEventListener('click', () => {
        inputBusqueda.value = '';
        window.location.href = window.location.pathname;
      });
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

  // Función para generar el PDF de pérdidas
    function generarPDFPerdidas() {
        try {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({
                orientation: 'portrait'
            });
            
            // Título del documento
            const title = "Reporte de Pérdidas de Laboratorio";
            const date = new Date().toLocaleDateString();
            const subtitle = `Generado el ${date}`;
            
            // Agregar título
            doc.setFontSize(18);
            doc.text(title, 105, 15, { align: 'center' });
            doc.setFontSize(12);
            doc.text(subtitle, 105, 22, { align: 'center' });
            
            // Resumen General
            doc.setFontSize(14);
            doc.text("Resumen General", 14, 35);
            
            const generalData = [
                ["Total Registros", "<?= count($perdidas) ?>"],
                ["Total Tuppers Perdidos", "<?= number_format($totalTuppersPerdidos) ?>"],
                ["Total Brotes Perdidos", "<?= number_format($totalBrotesPerdidos) ?>"]
            ];
            
            doc.autoTable({
                startY: 40,
                head: [['Concepto', 'Valor']],
                body: generalData,
                theme: 'grid',
                headStyles: { 
                    fillColor: [220, 53, 69],
                    textColor: [255, 255, 255]
                }
            });
            
            // Resumen por Tipo
            doc.setFontSize(14);
            doc.text("Pérdidas por Tipo", 14, doc.autoTable.previous.finalY + 15);
            
            const tiposData = [
                <?php
                $perdidasPorTipo = [];
                foreach ($perdidas as $perdida) {
                    $tipo = $perdida['Tipo_Entidad'];
                    if (!isset($perdidasPorTipo[$tipo])) {
                        $perdidasPorTipo[$tipo] = 0;
                    }
                    $perdidasPorTipo[$tipo]++;
                }
                arsort($perdidasPorTipo);
                foreach ($perdidasPorTipo as $tipo => $cantidad):
                    echo "['".htmlspecialchars($tipo)."', '".number_format($cantidad)."'],";
                endforeach;
                ?>
            ];
            
            doc.autoTable({
                startY: doc.autoTable.previous.finalY + 20,
                head: [['Tipo', 'Cantidad']],
                body: tiposData,
                theme: 'grid',
                headStyles: { 
                    fillColor: [255, 193, 7],
                    textColor: [0, 0, 0]
                }
            });
            
            // Detalle Completo
            doc.setFontSize(14);
            doc.text("Detalle de Pérdidas", 14, doc.autoTable.previous.finalY + 15);
            
            // Preparar datos de la tabla
            const tableData = [
                ['ID', 'Fecha', 'Tipo', 'Operador', 'Verificado por', 'Tuppers', 'Brotes', 'Motivo']
            ];
            
            <?php foreach ($perdidas as $perdida): ?>
                tableData.push([
                    '<?= htmlspecialchars($perdida['ID_Perdida']) ?>',
                    '<?= htmlspecialchars($perdida['Fecha_Perdida']) ?>',
                    '<?= htmlspecialchars($perdida['Tipo_Entidad']) ?>',
                    '<?= htmlspecialchars($perdida['Operador_Entidad'] ?? 'N/A') ?>',
                    '<?= htmlspecialchars($perdida['Operador_Chequeo'] ?? 'No verificado') ?>',
                    '<?= isset($perdida['Tuppers_Perdidos']) ? number_format((int)$perdida['Tuppers_Perdidos']) : '0' ?>',
                    '<?= isset($perdida['Brotes_Perdidos']) ? number_format((int)$perdida['Brotes_Perdidos']) : '0' ?>',
                    '<?= htmlspecialchars($perdida['Motivo']) ?>'
                ]);
            <?php endforeach; ?>
            
            // Crear tabla
            doc.autoTable({
                startY: doc.autoTable.previous.finalY + 20,
                head: [tableData[0]],
                body: tableData.slice(1),
                theme: 'grid',
                headStyles: { 
                    fillColor: [13, 110, 253],
                    textColor: [255, 255, 255]
                },
                margin: { horizontal: 14 },
                styles: { 
                    fontSize: 8,
                    cellPadding: 2
                },
                columnStyles: {
                    0: { cellWidth: 15 },
                    1: { cellWidth: 20 },
                    2: { cellWidth: 20 },
                    3: { cellWidth: 25 },
                    4: { cellWidth: 25 },
                    5: { cellWidth: 15 },
                    6: { cellWidth: 15 },
                    7: { cellWidth: 30 }
                },
                didDrawPage: function(data) {
                    // Agregar número de página
                    const pageCount = doc.internal.getNumberOfPages();
                    doc.setFontSize(10);
                    doc.text(`Página ${data.pageNumber} de ${pageCount}`, 105, 285, {
                        align: 'center'
                    });
                }
            });
            
            // Guardar el PDF
            doc.save(`Reporte_Perdidas_${date.replace(/\//g, '-')}.pdf`);
            
        } catch (error) {
            console.error('Error al generar PDF:', error);
            alert('Error al generar el PDF: ' + error.message);
        }
    }

    // Configurar el botón de PDF
    document.addEventListener('DOMContentLoaded', function() {
    // Crear contenedor
      const pdfContainer = document.createElement('div');
      pdfContainer.className = 'text-center my-4';
      
      // Crear botón
      const pdfBtn = document.createElement('button');
      pdfBtn.className = 'btn btn-danger btn-lg';
      pdfBtn.innerHTML = '<i class="bi bi-file-earmark-pdf"></i> Generar Reporte de Pérdidas';
      
      // Agregar evento
      pdfBtn.addEventListener('click', function() {
          const originalHTML = this.innerHTML;
          this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generando PDF...';
          this.disabled = true;
          
          setTimeout(() => {
              try {
                  generarPDFPerdidas();
              } catch (error) {
                  console.error(error);
                  alert('Error al generar PDF: ' + error.message);
              } finally {
                  this.innerHTML = originalHTML;
                  this.disabled = false;
              }
          }, 100);
      });
      
      // Agregar al contenedor
      pdfContainer.appendChild(pdfBtn);
      
      // Insertar antes de la tabla
      const tabla = document.querySelector('.table-responsive');
      if (tabla) {
        tabla.parentNode.insertBefore(pdfContainer, tabla);
      } else {
        document.body.appendChild(pdfContainer);
      }
  });
  </script>
</body>
</html>