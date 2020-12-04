<?php

        $debugging = true;
        $agrupar_estudios_array = array(
            'Hemograma' => array('HTO', 'HGB', 'LEU','NSEG', 'CAY',  'LIN', 'PLLA'),
            'Medio interno' => array('KAS', 'NAS'),
            'Función renal' => array('URE', 'CRE'),
            'Excluir' => array('BAS', 'EOS', 'META', 'MI', 'MON', 'PRO', 'SB', 'SUMAL')
        );
        function pacientes_por_piso($piso) {	
            global $debugging;
            $pacientes_array = array();
            if ($debugging) {
                $pacientes_raw = json_decode(file_get_contents("pacientes_mock.json"), true);
            } else {
                $pacientes_raw = json_decode(file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=pacientes&piso=".$piso), true);
            }
            foreach ($pacientes_raw['pacientes'] as $paciente) {
                $pacientes_array[] = array('HC' => $paciente['pacientes']['hist_clinica'], "Nombre" => $paciente['pacientes']['apellido1'].", ".$paciente['pacientes']['nombre'],"Cama" => $paciente['pacientes']['cama'], "Ordenes" => array());
            }
            return $pacientes_array;
	}

	function ordenes_de_paciente($HC) {	
            global $debugging;
        $ordenes_array = array();
        if ($debugging) {
            $ordenes_raw = json_decode(file_get_contents("ordenes_mock.json"), true);
        } else {
            $ordenes_raw = json_decode(file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=ordenestot&HC=".$HC), true);
        }
		foreach ($ordenes_raw['ordenestot'] as $orden)	{
			$ordenes_array[] = array("n_solicitud" => $orden['ordenestot']['NRO_SOLICITUD'], "timestamp" => $orden['ordenestot']['RECEPCION']);
		}
		return $ordenes_array;
	}

	function procesar_estudio($orden) {
            global $debugging, $agrupar_estudios_array;
            $estudio_array = array();
            
            #Recopila los datos en bruto del laboratorio
            if ($debugging) {
                $estudio_raw = json_decode(file_get_contents("estudios_mock.json"), true);
                #print_r($estudio_raw);
            } else {
                $estudio_raw = json_decode(file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=estudiostot&orden=".$orden), true);
            }
            $estudio_array['orden'] = $orden;
            
            #Agrupa cada resultado del laboratorio según los grupos definidos en $agrupar_estudios_array (ej: Hemograma, hepatograma, etc)
            foreach ($estudio_raw['estudiostot'] as $estudio) {	
                $codigo = $estudio['estudiostot']['CODANALISI']; 
                if (in_array($codigo, $agrupar_estudios_array['Excluir'])) {
                    continue;
                }
                if ($estudio['estudiostot']['NOMANALISIS'] == " ") {
                    continue;
                }
                $categoria_encontrada = false;
                foreach ($agrupar_estudios_array as $grupo => $estudios) {
                    if (in_array($codigo, $estudios)) {
                        $estudio_array[$grupo][] = array(
                            'nombre_estudio' => $estudio['estudiostot']['NOMANALISIS'], 
                            'resultado' => $estudio['estudiostot']['RESULTADO'],
                            'unidades' => $estudio['estudiostot']['UNIDAD']
                            );
                        $categoria_encontrada = true;
                        break;
                    }
                }
                if (!$categoria_encontrada) { #Si no entra en ninguna categoría preestablecida, va a "otros"
                    $estudio_array['Otros'][$codigo] = array(
                    'nombre_estudio' => $estudio['estudiostot']['NOMANALISIS'], 
                    'resultado' => $estudio['estudiostot']['RESULTADO'],
                    'unidades' => $estudio['estudiostot']['UNIDAD']
                    );
                }
            }
            return $estudio_array;
	}

$array_final = array();
        
$pacientes_array = pacientes_por_piso(7);

foreach ($pacientes_array as $paciente) {
    $HC = $paciente['HC'];
    foreach (ordenes_de_paciente($HC) as $orden) {
        $array_final[$orden] = procesar_estudio($orden);
    }
}   
echo json_encode($array_final);
?>
