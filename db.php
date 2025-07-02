<?php
$servername = "localhost"; // Host real que te dio el panel
$username = "root";              // Usuario de tu cuenta
$password = "";                 // Tu contraseña
$database = "if0_38801101_produccion_laboratorio"; // Nombre de la base

// Crear conexión
$conn = new mysqli($servername, $username, $password, $database);

// Verificar conexión
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// ✅ Esta línea evita errores con acentos y ñ
$conn->set_charset("utf8mb4");
?>
