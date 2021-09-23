<?php
$usuario_correcto = "favaloro";
$password_correcta = "Fava17!";

$usuario = $_POST["usuario"];
$palabra_secreta = $_POST["password"];

if ($usuario === $usuario_correcto && $palabra_secreta === $password_correcta) {
    session_start();
    $_SESSION["usuario"] = $usuario;
    header("Location: /build/");
} else {
    echo "El usuario o la contraseña son incorrectos. Recuerde que los datos de login son los mismos del sistema de laboratorio.";
}
