<!DOCTYPE html PUBLIC “-//W3C//DTD XHTML 1.0 Strict//EN”
 “http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd”>
<html xmlns=”http://www.w3.org/1999/xhtml” xml:lang=”en” lang=”en”>
	<head>
		<title>LaboChart</title>
	</head>

	<body>

		<?php
                    $debugging = true;
                    $piso = 7;
                    $pacientes = array();
                    if ($debugging) {
                        $string = file_get_contents("pacientes_mock.json");
                    } else {
                        $string = file_get_contents("172.24.24.131:8007/html/internac.php?funcion=pacientes&piso=".$piso);
                    }
                    
                    $json_pacientes = json_decode($string, true);
                    foreach ($json_pacientes['pacientes'] as $paciente => $paciente_propiedades) {
                        $pacientes[] = $paciente_propiedades['pacientes']['hist_clinica'];
                    }
		?> 
	</body>
</html>