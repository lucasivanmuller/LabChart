<?php
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json; charset=UTF-8");
	
	require_once 'include/config.php'; // Variables de configuracion, como $duracion_del_cache y $modo_desarrollo,
	$login_requerido = !$modo_desarrollo; // Deshabilita el login en el modo desarrollo
	
	// Chequea credenciales
	session_start();
	if (empty($_SESSION["usuario"]) && $login_requerido) {
		session_write_close();
		die();
	}
	
	// Expira la sesión despues de 2 horas
	if (isset($_SESSION["timeout"])) {
		if (time() - $_SESSION["timeout"] > 60 * 60 * 2){   // Expira la sesión después de 2 horas.
			$_SESSION = array();
		}
	}


	$piso = $_GET['piso'];
	
	$log_usos_file = fopen('trafico.csv', 'a+'); // Este archivo lleva registro de cada vez que se utiliza el programa, fines estadísticos.
	$valores_csv = array($piso, time());
	fputcsv($log_usos_file, $valores_csv);
	fclose($log_usos_file);
	
	$cache_file = "cache/cache_piso_" . $piso . ".json";
	$file = file_get_contents($cache_file);
	echo $file;
	exit;

?>