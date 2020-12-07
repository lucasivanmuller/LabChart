<?php

        $debugging = true; # Cambia la fuente de datos. Si false: consulta en la DB del hospital. Si true: usa los datos de carpeta mock_data
        if ($debugging) {
            $fecha_actual = "07/12/2020";
        }
        else {
            $fecha_actual = date('d/m/Y');
        }
        $agrupar_estudios_array = array(
            'orden' => array(),
            'Hemograma' => array('HTO', 'HGB', "HB", "CHCM", "HCM", "VCM", "RDW", 'LEU','NSEG', 'CAY',  'LIN', 'PLLA', "RPLA", ),
            'Medio interno' => array('NAS', 'KAS', "MGS", 'CAS', "CL", "FOS", "GLU"),
            'Funcion renal' => array('URE', 'CRE'),
            'Hepatograma' => array('TGO', 'TGP', 'ALP', 'BIT', 'BID', 'BILI', 'GGT'),
            'Coagulograma' => array('QUICKA', 'QUICKR', "APTT"),
            'Gasometria' => array("PHT", "PO2T", "PCO2T", "CO3H", "EB", "SO2"),
            'Dosajes' => array("FK"),
            'Excluir' => array('BAS', 'EOS', 'META', 'MI', 'MON', 'PRO', 'SB', 'SUMAL', "SR", "TM", "TEMP", "CTO2", "ERC", "QUICKT", "FIO2", "A/A"),
            'Otros' => array()
        );

        function pacientes_por_piso($piso) {	
            global $debugging;
            $pacientes_array = array();
            if ($debugging) {
                return json_decode(file_get_contents(".\\mock_data\\pacientes".$piso.".json"), true);
            } else {
                $pacientes_raw = json_decode(file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=pacientes&piso=".$piso), true);
            }
            foreach ($pacientes_raw['pacientes'] as $paciente) {
                $pacientes_array[] = array('HC' => $paciente['pacientes']['hist_clinica'], "Nombre" => $paciente['pacientes']['apellido1'].", ".$paciente['pacientes']['nombre'],"Cama" => $paciente['pacientes']['cama']);
            }
            return $pacientes_array;
	}

	function ordenes_de_paciente($HC) {	
            global $debugging, $fecha_actual;
            $ordenes_array = array();
            if ($debugging) {
                $ordenes_raw = json_decode(file_get_contents(".\\mock_data\\ordenes\\ordenes".$HC.".json"), true);
            } else {
                $ordenes_raw = json_decode(file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=ordenestot&HC=".$HC), true);
            }
                    foreach ($ordenes_raw['ordenestot'] as $orden)	{
                            $fecha_labo = substr($orden['ordenestot']['RECEPCION'], 0, 10); #Elimina la hora del timestamp del lab, deja solo la fecha.
                            if ($fecha_labo == $fecha_actual) {  #Limita los laboratorios a los que sean del día actual. TODO: Manejo de fechas.
                                    $ordenes_array[] = array("n_solicitud" => $orden['ordenestot']['NRO_SOLICITUD'], "timestamp" => $orden['ordenestot']['RECEPCION']);

                            }


                    }
                    return $ordenes_array;
            }

	function procesar_estudio($orden) {
            
            #Recopila los datos en bruto del laboratorio y los pre-procesa
            global $debugging, $agrupar_estudios_array, $grupo_estudios_actual;
            $estudio_array = array();
            if ($debugging) {
                $estudio_raw = json_decode(file_get_contents(".\\mock_data\\estudios\\estudio_".$orden.".json"), true);
            } else {
                $estudio_raw = json_decode(file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=estudiostot&orden=".$orden), true);
            }
            $estudio_array['orden'] = $orden;
            
            #Agrupa cada resultado del laboratorio segun los grupos definidos en $agrupar_estudios_array (Hemograma, hepatograma, etc)
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
                        $estudio_array[$grupo][$codigo] = array(
                            'nombre_estudio' => $estudio['estudiostot']['NOMANALISIS'], 
                            'resultado' => $estudio['estudiostot']['RESULTADO'],
                            'unidades' => $estudio['estudiostot']['UNIDAD']
                            );
                        $categoria_encontrada = true;
                        break;
                    }
                }
                if (!$categoria_encontrada) { #Si no entra en ninguna categoria preestablecida, va a "otros"
                    $estudio_array['Otros'][$codigo] = array(
                    'nombre_estudio' => $estudio['estudiostot']['NOMANALISIS'], 
                    'resultado' => $estudio['estudiostot']['RESULTADO'],
                    'unidades' => $estudio['estudiostot']['UNIDAD']
                    );
                }
            }
            uksort($estudio_array, "ordenar_grupos_de_estudios");
            foreach ($estudio_array as $key => $value) {
                $grupo_estudios_actual = $key;
                if ($key == "orden") {
                    continue;
                }
                uksort($value, "ordenar_estudios");
                $estudio_array[$key] = $value;
                }
            return $estudio_array;
	}
        
        function ordenar_grupos_de_estudios ($a, $b) {
            global $agrupar_estudios_array;
            $a_pos = array_search($a, array_keys($agrupar_estudios_array)); 
            $b_pos = array_search($b, array_keys($agrupar_estudios_array));

            $resultado = $a_pos - $b_pos;
            if ($a_pos == NULL) {
                $resultado = -1;
            }   
            if ($b_pos == NULL) {
                $resultado = +1;
            }
            return $resultado;
        }
        
        function ordenar_estudios ($a, $b) {
            global $agrupar_estudios_array, $grupo_estudios_actual;
            $a_pos = array_search($a, array_values($agrupar_estudios_array[$grupo_estudios_actual])); 
            $b_pos = array_search($b, array_values($agrupar_estudios_array[$grupo_estudios_actual]));
            $resultado = $a_pos - $b_pos;
            return $resultado;
        }
      
$array_final = array();
$pacientes = pacientes_por_piso($_GET['piso']);
foreach ($pacientes as $paciente) {
	foreach(ordenes_de_paciente($paciente['HC']) as $orden) {
            $resultado = procesar_estudio($orden['n_solicitud']);
            $array_final[] = array_merge($paciente, $resultado);
	}
	
}

return json_encode($array_final);




/*
GENERADOR DE  DATOS DE PRUEBA
$fp = fopen('pacientes9.json', 'w');
fwrite($fp, json_encode($pacientes));
fclose($fp);

foreach ($pacientes as $paciente) {
	$HC = $paciente['HC'];
	$ordenes = file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=ordenestot&HC=".$HC);
	$fp = fopen('ordenes' . $HC . '.json', 'w');
	fwrite($fp, $ordenes);
	fclose($fp);

}


foreach ($pacientes as $paciente) {
	foreach(ordenes_de_paciente($paciente['HC']) as $orden) {
		echo $orden['n_solicitud'];
		$estudio = file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=estudiostot&orden=" . $orden['n_solicitud']);
		$fp = fopen('estudio_' . $orden['n_solicitud'] . '.json', 'w');
		fwrite($fp, $estudio);
		fclose($fp);	
	}
	
}

*/


?>
