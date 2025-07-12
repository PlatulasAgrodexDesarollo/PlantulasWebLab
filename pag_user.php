<?php
session_start();
require_once __DIR__ . '/conexiones/conexion_BD.php';
// Verificar sesión y rol
if (!isset($_SESSION['loggedin'])) {
    header("Location: ../login.php?error=no_autenticado");
    exit();
}

// Redirigir admins si acceden aquí por error
if ($_SESSION['rol'] !== 'usuario') {
    // Versión dinámica (recomendada si sigues el patrón pag_[rol].php)
    header("Location: pag_" . $_SESSION['rol'] . ".php");
    exit;
}

// Mostrar mensajes
$mensaje = '';
if (isset($_SESSION['success'])) {
    $mensaje = '<div class="success-message">' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
} elseif (isset($_SESSION['error'])) {
    $mensaje = '<div class="error-message">' . $_SESSION['error'] . '</div>';
    unset($_SESSION['error']);
}

// Obtener datos del usuario
$sql = "SELECT nombre_completo, correo_electronico, numero_servidor, usuario, genero FROM usuarios WHERE id = ?";
$stmt = $conexion->prepare($sql);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$resultado = $stmt->get_result();
$usuario = $resultado->fetch_assoc();

if (!$usuario) {
    header("Location: ../login.php?error=usuario_no_encontrado");
    exit();
}

// Asignar datos con seguridad
$nombre_completo = htmlspecialchars($usuario['nombre_completo'] ?? '');
$numero_servidor = htmlspecialchars($usuario['numero_servidor'] ?? '');
$correo_electronico = htmlspecialchars($usuario['correo_electronico'] ?? '');
$genero = htmlspecialchars($usuario['genero'] ?? '');

$mensaje_exito = '';
$mensaje_error = '';

if (isset($_SESSION['mensaje_exito'])) {
    $mensaje_exito = '<div class="mensaje-exito">' . $_SESSION['mensaje_exito'] . '</div>';
    unset($_SESSION['mensaje_exito']);
}

if (isset($_SESSION['mensaje_error'])) {
    $mensaje_error = '<div class="mensaje-error">' . $_SESSION['mensaje_error'] . '</div>';
    unset($_SESSION['mensaje_error']);
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <style>
    /* Estilos para los tooltips de formatos bloqueados */
    .tooltip-bloqueado {
        position: absolute;
        top: 100%;
        left: 0;
        width: 100%;
        background-color: #f8d7da;
        color: #721c24;
        padding: 10px;
        border-radius: 5px;
        margin-top: 5px;
        z-index: 100;
        display: none;
        border: 1px solid #f5c6cb;
    }
    
    .formato-option:hover .tooltip-bloqueado {
        display: block;
    }
    
    .tooltip-content {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .tooltip-icon {
        background: #721c24;
        color: white;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
    }
    
    .error-antiguedad {
        position: absolute;
        top: -30px;
        left: 0;
        background: #dc3545;
        color: white;
        padding: 5px 10px;
        border-radius: 4px;
        font-size: 12px;
        z-index: 10;
        white-space: nowrap;
        animation: shake 0.5s;
    }
    
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        20%, 60% { transform: translateX(-5px); }
        40%, 80% { transform: translateX(5px); }
    }
    
    .formato-bloqueado {
        position: relative;
    }
    
    .formato-bloqueado input[type="radio"] {
        cursor: not-allowed;
    }
</style>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bienvenido</title>
    <!-- Estilo al que esta conectado-->
    <link rel="stylesheet" href="assed/css/estilo2pag_user3.css">
    <script>
        function validarFormulario() {
            const formatoSeleccionado = document.querySelector('input[name="formato"]:checked');
            if (!formatoSeleccionado) {
                alert('Por favor seleccione un formato');
                return false;
            }
            return true;
        }
        
    </script>
</head>

<body>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const motivosInput = document.getElementById('motivos-solicitud');
            const formatosRadio = document.querySelectorAll('.formato-radio');

            // Objeto con los motivos correspondientes a cada formato
            const motivosPorFormato = {
                1: "PERMISO DE CUIDADO DE HIJOS/AS, ENFERMOS, PERSONAS ADULTAS MAYORES O DEPENDIENTES ECONÓMICOS (3HRS).",
                2: "PERMISO ESCOLAR DE LOS HIJOS/AS O DEPENDIENTES ECONÓMICOS (3HRS).",
                3: "PERMISOS PARA CONSULTAS MEDICAS (3HRS).",
                4: "PERMISO SIN GOCE DE SUELDO.",
                5: "LICENCIA CON GOCE DE SUELDO A PARTIR DE 1 AÑO DE ANTIGUEDAD (6 POR AÑOS, LOS CUALES SE TOMARÁN CADA DOS MESES) ADVERTENCIA (NO CONSECUTIVAS, NO ANTES NI DESPUÉS DE VACACIONES O DÍAS FESTIVOS, NO ACUMULABLES Y NO RETROACTIVOS).",
                6: "POR MATRIMONIO (MAS DE 1 AÑO 5 DIAS DE LICENCIA) (MENOS DE 1 AÑO 2 DIAS DE LICENCIA.)",
                7: "FALLECIMINETO DE UN FAMILIAR NOTA (LICENCIA CON GOCE DE SUELDO POR 2 DIAS, PRESENTAR ACTA DE DEFUNCION, SOLO APLICA LINEA DIRECTAS).",
                8: "NACIMIENTO O ADOPCION DE HIJOS/AS (LICENCIA DE PARTENIDAD POR 5 DIAS).",
                9: "EXAMEN PROFESIONAL (MÁS DE 1 AÑO DE ANTIGÜEDAD, LICENCIA CON GOCE ASUELDO POR 3 DIAS)",
                10: "ENFERMEDAD O ACCIDENTES GRAVES DE HIJOS/AS O ESPOSO/AS"

            };

            // Escuchar cambios en los radio buttons
            formatosRadio.forEach(radio => {
                radio.addEventListener('change', function () {
                    if (this.checked) {
                        const formatoSeleccionado = this.value;
                        motivosInput.value = motivosPorFormato[formatoSeleccionado] || '';
                    }
                });
            });

            // Permitir edición manual si el usuario lo desea
            motivosInput.addEventListener('focus', function () {
                this.select();
            });
        });
        
        
    </script>
    <!-- Todo lo que tiene la pagina de usuario -->
    <main>
        <img src="assed/imagen/logo3.jpg" alt="Logo Escolar" class="logo-superior">
        <div class="interfaz">
            <div class="container">
                <h1>¡Bienvenido, <?php echo htmlspecialchars($_SESSION['usuario']); ?>!</h1>
                <p>Correo electrónico: <?php echo $correo_electronico; ?></p>
            </div>

            <form id="form-solicitud" action="conexiones/procesar_solicitu.php" method="POST"
                enctype="multipart/form-data" onsubmit="return validarFormulario()">
                <div class="logo">
                    <!-- Espacio para logo -->
                </div>
                <!-- Datos del usario -->
                <div class="Datos_y_motivos" disabled>
                    <h3>Nombre del solicitante</h3>
                    <input type="text" name="nombre_completo" value="<?php echo $nombre_completo; ?>" readonly>
                    <h3>Género</h3>
                    <select name="genero" disabled>
                        <option value="">Seleccione...</option>
                        <option value="Hombre" <?php echo ($genero == 'Hombre') ? 'selected' : ''; ?>>Hombre</option>
                        <option value="Mujer" <?php echo ($genero == 'Mujer') ? 'selected' : ''; ?>>Mujer</option>
                    </select>
                    <h3>Número de servidor público</h3>
                    <input type="text" name="numero_servidor" value="<?php echo $numero_servidor; ?>" readonly>
                    <h3>Fecha de registro</h3>
                    <input type="date" name="fecha_registro" id="fecha_registro_auto" readonly required>
                </div>

                <!--TIPOS DE FORMATOS-->
                <div class="formato-seleccion">
                    <h3>Motivos de solicitud</h3>
                    <input type="text" name="Motivos" id="motivos-solicitud" disabled>
                    <h3>Seleccione un formato:</h3>
                    <div class="formatos-container">
                        <!-- SECCIÓN CORRESPONSABILIDAD FAMILIAR -->
                        <h4>CORRESPONSABILIDAD FAMILIAR</h4>
                        <div class="grupo-corresponsabilidad">
                            <!-- Opción 1 -->
                            <label class="formato-option">
                                <input type="radio" name="formato" value="1" class="formato-radio">
                                <span class="custom-radio"></span>
                                <span class="formato-text">A3</span>
                                <span class="tooltip">A3:Permiso de cuidado de hijos/as, enfermos, personas adultas mayores o
                                    dependientes económicos (3HRS).</span>
                            </label>
                            <!-- Opción 2 -->
                            <label class="formato-option">
                                <input type="radio" name="formato" value="2" class="formato-radio">
                                <span class="custom-radio"></span>
                                <span class="formato-text">A4</span>
                                <span class="tooltip">A4:Permiso escolar de los hijos/as o dependientes económicos
                                    (3HRS).</span>
                            </label>
                            <!-- Opción 3 -->
                            <label class="formato-option">
                                <input type="radio" name="formato" value="3" class="formato-radio">
                                <span class="custom-radio"></span>
                                <span class="formato-text">A5</span>
                                <span class="tooltip">A5: Permisos para consultas medicas (3HRS).</span>
                            </label>
                            <!-- Opción 4 -->
                            <label class="formato-option">
                                <input type="radio" name="formato" value="4" class="formato-radio">
                                <span class="custom-radio"></span>
                                <span class="formato-text">A6</span>
                                <span class="tooltip">A6: Permiso sin goce de sueldo.</span>
                            </label>
                            <!-- Opción 5 -->
                            <label class="formato-option">
                                <input type="radio" name="formato" value="5" class="formato-radio">
                                <span class="custom-radio"></span>
                                <span class="formato-text">A7</span>
                                <span class="tooltip">A7: Licencia con goce de sueldo a partir de 1 AÑO de ANTIGUEDAD (6
                                    por años, las cuales se tomarán cada dos meses
                                    ) ADVERTENCIA ("NO consecutivas, NO antes NI después de vacaciones o días
                                    festivos, NO acumulables y NO retroactivos.")</span>
                            </label>
                        </div>

                        <!-- SECCIÓN LICENCIA PERSONALES -->
                        <h4>LICENCIA PERSONALES</h4>
                        <div class="grupo-licencias">
                            <!-- Opción 6 -->
                            <label class="formato-option">
                                <input type="radio" name="formato" value="6" class="formato-radio">
                                <span class="custom-radio"></span>
                                <span class="formato-text">C1</span>
                                <span class="tooltip">C1: Por matrimonio ("Mas de 1 año 5 dias de licencia") ("Menos de
                                    1 año 2 dias de licencia.")</span>
                            </label>
                            <!-- Opción 7 -->
                            <label class="formato-option">
                                <input type="radio" name="formato" value="7" class="formato-radio">
                                <span class="custom-radio"></span>
                                <span class="formato-text">C2</span>
                                <span class="tooltip">C2: Fallecimineto de un familiar NOTA ("Licencia con goce de
                                    sueldo por 2 dias, Presentar acta de defuncion, Solo aplica linea directas")</span>
                            </label>
                            <!-- Opción 8 -->
                            <label class="formato-option">
                                <input type="radio" name="formato" value="8" class="formato-radio">
                                <span class="custom-radio"></span>
                                <span class="formato-text">C3</span>
                                <span class="tooltip">C3: Nacimiento o adopcion de Hijos/as ("Licencia de partenidad por 5
                                    dias")</span>
                            </label>
                            <!-- Opción 9 -->
                            <label class="formato-option">
                                <input type="radio" name="formato" value="9" class="formato-radio">
                                <span class="custom-radio"></span>
                                <span class="formato-text">C5</span>
                                <span class="tooltip">C5: Examen profecional NOTA ("Mas de 1 año de ANTIGUEDAD, licencia con goce a de sueldo por 3 dias")</span>
                            </label>
                            <!-- Opción 10 -->
                            <label class="formato-option">
                                <input type="radio" name="formato" value="10" class="formato-radio">
                                <span class="custom-radio"></span>
                                <span class="formato-text">C6</span>
                                <span class="tooltip">C6: Enfermedad o accidentes graves de Hijos/as o Esposo/as</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!--Fecha y hora  -->
                <div class="fechas">
                    <h3>Dias o fechas requeridas</h3>
                    <input type="date" name="dias_solicitados" required>

                    <h3>Horario de permisos</h3>
                    <input type="time" name="hora_salida" placeholder="Salida" id="hora_salida">
                    <input type="time" name="hora_regreso" placeholder="regreso">
                    <h3>Seleccione carrera(s):</h3>
                    <div class="carreras-container">
                        <div class="carreras-opciones">
                            <label><input type="checkbox" name="carreras[]" value="ISC"> ISC</label>
                            <label><input type="checkbox" name="carreras[]" value="ARQUITECTURA"> ARQUITECTURA</label>
                            <label><input type="checkbox" name="carreras[]" value="AGRONOMIA"> AGRONOMIA</label>
                            <label><input type="checkbox" name="carreras[]" value="ELECTRONICA"> ELECTRONICA</label>
                            <label><input type="checkbox" name="carreras[]" value="INDUSTRIA"> INDUSTRIA</label>
                            <label><input type="checkbox" name="carreras[]" value="TURISMO"> TURISMO</label>
                        </div>
                        <div class="carrera-personalizada">
                            <label>
                                <input type="checkbox" id="otra_carrera_check"> Administrativo
                            </label>
                            <input type="text" name="carrera_personalizada" id="carrera_personalizada" disabled>
                        </div>
                    </div>
                    <div class="botones">
                        <button type="submit" form="form-solicitud" class="boton enviar">Enviar</button>
                        <button type="reset" form="form-solicitud" class="boton eliminar">Limpiar</button>
                        <a href="conexiones/mis_solicitudes.php" class="boton ver">Ver mis solicitudes</a>
                        <a href="login.php" class="logout-btn">Cerrar sesión</a>
                    </div>
            </form>
        </div>
    </main>
    <script src="assed/js/solicitud.js"></script>
</body>

</html>