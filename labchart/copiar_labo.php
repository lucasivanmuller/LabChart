<html lang="en"> 
<head>
	<meta charset="utf-8"/>

</head>
<body>
	<form>
  		<label>Solicitud:
    			<input name="solicitud" autocomplete="name">
  		</label>
  		<button formmethod="get">Buscar</button>
	</form>
</body>
</html>

<?php		
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method == "GET"){
        $solicitud = $_GET['solicitud'];
    }

	require_once("include/vars.php");

	$estudio_raw = json_decode(file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=estudiostot&orden=".$solicitud), true);
	$estudio_array = array(solicitud=>$solicitud);
	#Agrupa cada resultado del laboratorio segun los grupos definidos en $agrupar_estudios_array (Hemograma, hepatograma, etc)
	foreach ($estudio_raw['estudiostot'] as $estudio) {	
		$codigo = $estudio['estudiostot']['CODANALISI']; 
		if (in_array($codigo, $agrupar_estudios_array['Excluir'])) {
			continue;
		}
		if ($estudio['estudiostot']['NOMANALISIS'] == " ") { # Algunos "resultados" que en realidad no lo son (ej: orden de material descartable utilizado, interconsultas)
			continue;
		}
		if (is_null($estudio['estudiostot']['UNIDAD'])) { # Contempla casos de resultados que no tienen unidades.
			$estudio['estudiostot']['UNIDAD'] = ""; 
		}
		
		 # Itera en los distintos $grupos de $estudios predefinidos buscando a cual pertenece el $estudio. Cuando lo encuentra, break
		 # Si no lo encuentra: el grupo es "Otros".
		$grupo_encontrado = false;
		foreach ($agrupar_estudios_array as $grupo => $estudios) { 
			if (in_array($codigo, $estudios)) {
				$estudio_array[$grupo][$codigo] = array(
					'nombre_estudio' => $estudio['estudiostot']['NOMANALISIS'], 
					'resultado' => $estudio['estudiostot']['RESULTADO'],
					'unidades' => $estudio['estudiostot']['UNIDAD'],
					'color' => "black",
					'info' => ""
					);
		
		// Las siguientes 3 excepciones existen porque el nombre del estudio llegan incorrectos del webservice
				if ($estudio['estudiostot']['NOMANALISIS'] == "Total") { 
					$estudio_array[$grupo][$codigo]['nombre_estudio'] = "Bilirrubina total";
				}
				if ($estudio['estudiostot']['NOMANALISIS'] == "Directa") { 
					$estudio_array[$grupo][$codigo]['nombre_estudio'] = "Bilirrubina directa";
				}
				if ($estudio['estudiostot']['NOMANALISIS'] == "Indirecta") { 
					$estudio_array[$grupo][$codigo]['nombre_estudio'] = "Bilirrubina indirecta";
				}

				$grupo_encontrado = true;
				break; 
			}
		}
		
		if (!$grupo_encontrado) { # Si no entra en ningun grupo preestablecido, se le asigna "Otros"
			$estudio_array['Otros'][$codigo] = array(
			'nombre_estudio' => $estudio['estudiostot']['NOMANALISIS'], 
			'resultado' => $estudio['estudiostot']['RESULTADO'],
			'unidades' => $estudio['estudiostot']['UNIDAD'],
			'color' => "black",
			'info' => ""
			);
		}
	}
	
	
	# Ordena los resultados primero según el orden predefinido en agrupar_estudios_array: primero el orden de los grupos, luego orden de estudios.
	uksort($estudio_array, "ordenar_grupos_de_estudios");
	foreach ($estudio_array as $key => $value) {
		$grupo_estudios_actual = $key;
		if ($key == "solicitud" or $key == "timestamp") {
			continue;
		}
		uksort($value, "ordenar_estudios");

		$estudio_array[$key] = $value;
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

        function textificar_array($estudio) {
			/* Resume el resultado de un laboratorio entero en dos strings, y las agrega al array de estudios.
				- $textificado_corto: Modo compacto y resumido para el pase. Agrupa algunos estudios (hepatograma, coagulograma) con una sintaxis
				tipo 12/34/56/78/90. No usa unidades. Omite algunos resultados no relevantes.
				- $textificado_largo: Modo expresivo, completo, con unidades, que cumple con formalidades médico-legales
				
				returns: nuevo array de estudios actualizado con las strings.
				*/
            global $abreviar_nombres_estudios;
                $textificado_largo = "";
                $textificado_corto = "";
                foreach(array_slice($estudio, 1) as $key_grupos => $grupo_de_estudios) {
					// Formateo especial para hepatograma completo.
					if ($key_grupos == "Hepatograma" && sizeof($grupo_de_estudios) > 5) {
						$hepatograma = "H: ";
						foreach($grupo_de_estudios as $codigo_de_estudio => $estudio) {
							if ($codigo_de_estudio == "BILI") {  // "La bilirrubina indirecta no se informa en el texto compacto
								continue;
							}
							$hepatograma .= $estudio["resultado"] . "/";
							$textificado_largo .= $estudio["nombre_estudio"] . ": " . $estudio["resultado"] . " " . $estudio["unidades"] . ", ";
						}
						$textificado_corto .= substr($hepatograma, 0, -1) . " ";
						continue;
					}
					
					// Formateo especial para el coagulograma:
					if ($key_grupos == "Coagulograma" && sizeof($grupo_de_estudios) > 2) {
						$coagulograma = "C: ";
						foreach($grupo_de_estudios as $codigo_de_estudio => $estudio) {
							$coagulograma .= $estudio["resultado"] . "/";
							if ($codigo_de_estudio == "FIBRI") { // Fibrinogeno se informa aparte.
								$textificado_corto .= $abreviar_nombres_estudios[$codigo_de_estudio] . ": " . $estudio["resultado"] . " ";
								continue;
							}

							$textificado_largo .= $estudio["nombre_estudio"] . ": " . $estudio["resultado"] . " " . $estudio["unidades"] . ", ";
						}
						$textificado_corto .= substr($coagulograma, 0, -1) . " ";
						continue;
					}
					// Formateo de gasometria
					if ($key_grupos == "Gasometria") {
						if ($solicitud["Gasometria"]["TM"]["resultado"] == "AR") { 
							$EAB = "EABa: ";			
						}
						else {
							$EAB = "EABv: ";			
						}
						foreach($grupo_de_estudios as $codigo_de_estudio => $estudio) {
							if ($codigo_de_estudio == "TM" or $codigo_de_estudio == "HB") {
								continue;
							}
							$EAB .= $estudio["resultado"] . "/";
							$textificado_largo .= $estudio["nombre_estudio"] . ": " . $estudio["resultado"] . " " . $estudio["unidades"] . ", ";
						}
						$textificado_corto .= substr($EAB, 0, -1) . " ";
						continue;
					}
					
					// Formateo del resto de estudios
                    foreach($grupo_de_estudios as $codigo_de_estudio => $estudio) {
                        $textificado_largo .= $estudio["nombre_estudio"] . ": " . $estudio["resultado"] . " " . $estudio["unidades"] . ", ";
                        if (isset($abreviar_nombres_estudios[$codigo_de_estudio])) { 
                            $textificado_corto .= $abreviar_nombres_estudios[$codigo_de_estudio] . ": " . $estudio["resultado"] . " ";
                        }
                    } 
                }
                $estudios[$key]["text_largo"] = substr($textificado_largo, 0, -2);
                $estudios[$key]["text_corto"] = $textificado_corto;
            return $estudios;
        }
	
	$textificado = textificar_array($estudio_array);
	echo $textificado[""]["text_largo"];

?>




