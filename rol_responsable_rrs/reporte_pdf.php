<?php
ob_start();

require __DIR__ . '/../session_manager.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../libs/dompdf/autoload.inc.php';
use Dompdf\Dompdf;

// Validar sesión
if (!isset($_SESSION['ID_Operador']) || (int)$_SESSION['Rol'] !== 8) {
    http_response_code(403);
    exit('Acceso denegado');
}

// Recolectar filtros
$operador   = trim($_GET['operador']   ?? '');
$fechaDesde = $_GET['desde']          ?? '';
$fechaHasta = $_GET['hasta']          ?? '';

$conn->set_charset("utf8");

$opEsc = $conn->real_escape_string($operador);
$where = "WHERE 1=1";
if ($operador   !== '') $where .= " AND r.Operador_Original = '{$opEsc}'";
if ($fechaDesde !== '') $where .= " AND r.Fecha >= '{$fechaDesde}'";
if ($fechaHasta !== '') $where .= " AND r.Fecha <= '{$fechaHasta}'";

// Consulta
$sql = "
SELECT * FROM (
  -- Multiplicación
  SELECT
    'Multiplicación' AS Etapa,
    M.ID_Multiplicacion AS ID,
    CONCAT(O.Nombre,' ',O.Apellido_P,' ',O.Apellido_M) AS Operador_Original,
    CONCAT(Oc.Nombre,' ',Oc.Apellido_P,' ',Oc.Apellido_M) AS Operador_Consolida,
    CONCAT(V.Codigo_Variedad,' – ',V.Nombre_Variedad) AS Variedad,
    COALESCE(NULLIF(V.Color, ''), 'S/D') AS Color,
    M.Fecha_Siembra AS Fecha,
    M.Cantidad_Dividida AS Cantidad
  FROM multiplicacion M
  JOIN operadores O ON M.Operador_Responsable = O.ID_Operador
  JOIN consolidacion_log CL ON CL.ID_Multiplicacion = M.ID_Multiplicacion
  JOIN operadores Oc ON CL.ID_Operador = Oc.ID_Operador
  JOIN variedades V ON M.ID_Variedad = V.ID_Variedad
  WHERE M.Estado_Revision = 'Consolidado'

  UNION ALL

  -- Enraizamiento
  SELECT
    'Enraizamiento' AS Etapa,
    E.ID_Enraizamiento AS ID,
    CONCAT(O.Nombre,' ',O.Apellido_P,' ',O.Apellido_M) AS Operador_Original,
    CONCAT(Oc2.Nombre,' ',Oc2.Apellido_P,' ',Oc2.Apellido_M) AS Operador_Consolida,
    CONCAT(V2.Codigo_Variedad,' – ',V2.Nombre_Variedad) AS Variedad,
    COALESCE(NULLIF(V2.Color, ''), 'S/D') AS Color,
    E.Fecha_Siembra AS Fecha,
    E.Cantidad_Dividida AS Cantidad
  FROM enraizamiento E
  JOIN operadores O ON E.Operador_Responsable = O.ID_Operador
  JOIN consolidacion_log CL2 ON CL2.ID_Enraizamiento = E.ID_Enraizamiento
  JOIN operadores Oc2 ON CL2.ID_Operador = Oc2.ID_Operador
  JOIN variedades V2 ON E.ID_Variedad = V2.ID_Variedad
  WHERE E.Estado_Revision = 'Consolidado'
) AS r
{$where}
ORDER BY r.Fecha DESC
";
$result = $conn->query($sql);

// Generar HTML
$html  = '<h2 style="text-align:center;">Reportes Consolidados</h2>'
       . '<table border="1" cellpadding="5" cellspacing="0" width="100%">'
       . '<thead><tr style="background:#45814d;color:white;">'
       . '<th>Etapa</th><th>ID</th><th>Operador Original</th><th>Consolidado Por</th>'
       . '<th>Variedad</th><th>Color</th><th>Fecha de Siembra</th><th>Cantidad</th>'
       . '</tr></thead><tbody>';
while ($row = $result->fetch_assoc()) {
    $html .= '<tr>'
           . '<td>'.$row['Etapa'].'</td>'
           . '<td>'.$row['ID'].'</td>'
           . '<td>'.htmlspecialchars($row['Operador_Original']).'</td>'
           . '<td>'.htmlspecialchars($row['Operador_Consolida']).'</td>'
           . '<td>'.htmlspecialchars($row['Variedad']).'</td>'
           . '<td>'.htmlspecialchars($row['Color']).'</td>'
           . '<td>'.$row['Fecha'].'</td>'
           . '<td>'.$row['Cantidad'].'</td>'
           . '</tr>';
}
$html .= '</tbody></table>';

// Render PDF
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$pdf = $dompdf->output();

if (ob_get_length()) ob_end_clean();

header("Content-Type: application/pdf");
header("Content-Disposition: inline; filename=\"reporte.pdf\"");
header("Content-Length: " . strlen($pdf));
header("Accept-Ranges: none");

echo $pdf;
exit;


