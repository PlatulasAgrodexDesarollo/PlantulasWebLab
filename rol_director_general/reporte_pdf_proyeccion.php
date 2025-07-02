<?php
ob_start();
require __DIR__ . '/../session_manager.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../libs/dompdf/autoload.inc.php';
use Dompdf\Dompdf;

// Validar sesión y rol del Director General (ID Rol 11)
if (!isset($_SESSION['ID_Operador']) || (int)$_SESSION['Rol'] !== 11) {
    http_response_code(403);
    exit('Acceso denegado');
}

date_default_timezone_set('America/Mexico_City');
$conn->set_charset("utf8");

// Obtener semana y año
$semana = (int) ($_GET['semana'] ?? date('W'));
$anio   = (int) ($_GET['anio']   ?? date('o'));

// Fechas de inicio y fin (lunes a viernes)
$start = new DateTime();
$start->setISODate($anio, $semana);
$end = clone $start;
$end->modify('+4 days');

$fechaInicio = $start->format('Y-m-d 00:00:00');
$fechaFin    = $end->format('Y-m-d 23:59:59');

// Consulta de proyecciones verificadas
$sql = "
SELECT v.Codigo_Variedad, v.Nombre_Variedad,
       SUM(p.Tuppers_Proyectados) AS Total_Tuppers,
       SUM(p.Brotes_Proyectados)  AS Total_Brotes
FROM   proyecciones_lavado p
JOIN (
  SELECT ID_Variedad, ID_Multiplicacion AS ID, 'multiplicacion' AS Etapa FROM multiplicacion
  UNION ALL
  SELECT ID_Variedad, ID_Enraizamiento AS ID, 'enraizamiento' AS Etapa FROM enraizamiento
) AS etapas ON p.Etapa = etapas.Etapa AND p.ID_Etapa = etapas.ID
JOIN variedades v ON v.ID_Variedad = etapas.ID_Variedad
WHERE p.Estado_Flujo IN ('lavado', 'enviado_tenancingo', 'pendiente_acomodo', 'acomodados')
  AND  IFNULL(p.Fecha_Verificacion, p.Fecha_Creacion) BETWEEN ? AND ?
GROUP BY v.ID_Variedad
ORDER BY v.Codigo_Variedad
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $fechaInicio, $fechaFin);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

// Crear HTML del PDF
$html = '
  <h2 style="text-align:center;">Proyección Verificada - Semana '.$semana.'</h2>
  <p style="text-align:center;">Del '.$start->format('d/m/Y').' al '.$end->format('d/m/Y').'</p>
  <table border="1" cellpadding="5" cellspacing="0" width="100%">
    <thead>
      <tr style="background:#45814d; color:white;">
        <th>Variedad</th>
        <th>Tuppers</th>
        <th>Brotes</th>
      </tr>
    </thead>
    <tbody>';

while ($row = $result->fetch_assoc()) {
    $html .= '<tr>'
           . '<td>'.htmlspecialchars($row['Codigo_Variedad'].' – '.$row['Nombre_Variedad']).'</td>'
           . '<td align="right">'.$row['Total_Tuppers'].'</td>'
           . '<td align="right">'.$row['Total_Brotes'].'</td>'
           . '</tr>';
}

$html .= '</tbody></table>';

// Renderizar PDF
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$pdf = $dompdf->output();

if (ob_get_length()) ob_end_clean();

header("Content-Type: application/pdf");
header("Content-Disposition: inline; filename=\"proyeccion_semanal.pdf\"");
header("Content-Length: " . strlen($pdf));
header("Accept-Ranges: none");

echo $pdf;
exit;
