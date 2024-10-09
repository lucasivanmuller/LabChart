<?php
$usuario_correcto = "favaloro";
$hash_correcto = "$2y$10$7QTtpShPCfES2XOmFU33fe37vGXhm.8k6q2VRXs/bl.RvacivcgNC";
session_start();
$usuario = $_POST["usuario"];
$palabra_secreta = $_POST["password"];

$autenticado = password_verify($palabra_secreta, $hash_correcto);

if ($autenticado  && $usuario == $usuario_correcto) {
    $_SESSION["usuario"] = $usuario;
    session_write_close();
    header("Location: build/");
    die();
} else {
    echo "El usuario o la password son incorrectos. Recuerde que los datos de login son los mismos del sistema de laboratorio.";
}
?>