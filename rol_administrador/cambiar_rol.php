<?php
require_once __DIR__.'/../session_manager.php';
require_once __DIR__.'/../db.php';

if (!isset($_SESSION['ID_Operador']) || (int)$_SESSION['Rol']!==1) {
    header('Location: ../login.php'); exit;
}

$target = (int)($_POST['target_role'] ?? 0);

// mismo arreglo usado en login.php
$rutas=[
  1=>'rol_administrador/panel_admin.php',
  2=>'rol_operador/dashboard_cultivo.php',
  3=>'rol_supervisor/panel_supervisor.php',
  4=>'rol_supervisora_incubadora/dashboard_supervisora.php',
  5=>'rol_encargado_general_produccion/dashboard_egp.php',
  6=>'rol_gerente_produccion_laboratorio/dashboard_gpl.php',
  7=>'rol_responsable_produccion_medios_cultivo/dashboard_rpmc.php',
  8=>'rol_responsable_rrs/dashboard_rrs.php',
];

if (!isset($rutas[$target]) || $target===1) {  // evitar valores inválidos
    header('Location: llave_maestra.php'); exit;
}

// Guardar rol original solo la primera vez
if (!isset($_SESSION['origin'])) {
    $_SESSION['origin']=$_SESSION['Rol'];
}
$_SESSION['Rol']=$target;
$_SESSION['Impersonando']=true;

// (opcional) registrar en BD quién impersona
/*
$stmt=$conn->prepare("INSERT INTO impersonaciones
 (ID_Operador, Rol_Destino, Fecha_Inicio) VALUES (?,?,NOW())");
$stmt->bind_param('ii', $_SESSION['ID_Operador'], $target);
$stmt->execute(); $stmt->close();
*/

header('Location: ../'.$rutas[$target]);
exit;
?>
