<?php	
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json; charset=UTF-8");
    $method = $_SERVER['REQUEST_METHOD'];
    
    /* lachart.php: Script principal de la aplicación. Se lo consulta desde el frontend cuando el usuario selecciona un piso. Devuelve un JSON 
     * con la data preparada para su visualización; o frena el proceso y devuelve string("usar cache") en caso de que el cache sea muy reciente.
     * Entrada: $_GET['piso'] existe en array(1, 3, 5, 6, 7, 8, 9)
     * Funciones: Se encarga de recopilar los datos, conexión con la base de datos del laboratorio, darles la estructura, preprocesarlos, analizarlos,
     * y hacerlos disponibles al frontend. 
     * Devuelve: echo array_JSON
     */
    
    ### CONFIGURACIÓN Y PARÁMETROS INICIALES 
    date_default_timezone_set("America/Argentina/Buenos_Aires");
    require_once 'include/vars.php'; // Importa variables configurables,  $agrupar_estudios_array y $abreviar_nombres_estudios
	require_once 'include/config.php'; // Variables de configuracion, como $duracion_del_cache y $modo_desarrollo,
    $piso = $_GET['piso']; // Única variable de entrada: El piso al cual se hace la consulta.   

    // Variables auxiliares de fechas.    
    if (!$modo_desarrollo) {
        $fecha_actual = date_create(date('d-m-Y H:i'));
        $fecha_menos_24h = date_create(date('d-m-Y H:i'))->modify('-1 day');
        $fecha_menos_48h = date_create(date('d-m-Y H:i'))->modify('-2 day');
    }
    if ($modo_desarrollo) { // Entorno virtual para el modo debugging, coincidente con las fechas de los estudios de /mock_data
        $fecha_actual = DateTime::createFromFormat('d/m/Y H:i', '07/12/2020 23:00');
        $fecha_menos_24h = DateTime::createFromFormat('d/m/Y H:i', '07/12/2020 23:00')->modify('-1 day')->modify('-2 hour');
        $fecha_menos_48h = DateTime::createFromFormat('d/m/Y H:i', '07/12/2020 23:00')->modify('-2 day');
    }

    
    ### SISTEMA DE CACHE:
    /* 
    Mejora el rendimiento de la aplicación. El JSON final que devuelve labchart.php queda almacenado como archivo /cache/cache_piso_X.json
    *  al final del proceso. Si existe un cache más reciente que $duracion_del_cache (por defecto: 2 min), reutiliza ese JSON almacenado 
    * transmitiendo al frontend "usar cache" y detiene el script.
    * Si es más antiguo que $duracion_del_cache, el cache será lo que se muestre inicialmente hasta que labchart.php termine de ejecutarse
    * y envie al frontend los datos actualizados.   */ 
    
    $cache_file = "cache/cache_piso_" . $piso . ".json";
    if ((file_exists($cache_file) && (filemtime($cache_file) > (time() - 60 * $duracion_del_cache)) ) || $modo_solo_cache) {
        echo "usar cache";
        exit(0);
    }
    // Fin del cache.

    
    ### INTERFAZ VÍA WEB-SERVICE CON LA BASE DE DATOS (Laboratorio y HCA)
    function pacientes_por_piso($piso) {	
        /* Consulta al web-service función 'pacientes', organiza los datos recibidos en un array de estructura (HC, Nombre, Cama)
         * Devuelve: array('HC', 'Nombre', 'Cama')    */
        global $modo_desarrollo; 
        $pacientes_array = array(); //Array principal.
		
        if ($modo_desarrollo) {
            return json_decode(file_get_contents(".\\mock_data\\pacientes".$piso.".json"), true);
        }
        
        # Obtener datos del web-service
        if (in_array($piso, array(3, 5, 6, 7, 8, 9)))  { # Usa la funcion "pacientes" del web-service
            $pacientes_raw = json_decode(file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=pacientes&piso=".$piso), true);
            foreach ($pacientes_raw['pacientes'] as $paciente) {
                $pacientes_array[] = array('HC' => $paciente['pacientes']['hist_clinica'], "Nombre" => $paciente['pacientes']['apellido1'].", ".$paciente['pacientes']['nombre'],"Cama" => $paciente['pacientes']['cama']);
            }
        }
        
        if ($piso == 1) { # Los datos de la guardia externa (piso 1) se recolectan de la funcion "urgencia" del web-service
            $pacientes_raw = json_decode(file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=urgencia"), true);
            foreach ($pacientes_raw['urgencia'] as $paciente) {
                $pacientes_array[] = array('HC' => $paciente['urgencia']['hist_clinica'], "Nombre" => $paciente['urgencia']['apellido1'].", ".$paciente['urgencia']['nombre'],"Cama" => $paciente['urgencia']['cama']);
            }
        }
        return $pacientes_array; //  array('HC', 'Nombre', 'Cama')
    }

    function ordenes_de_paciente($HC) {	
        /* Consulta al web-service función ordenestot, junta todas las ordenes de un determinado paciente (identificado por N° de HC) .
         * Devuelve:array (n_solicitud, timestamp)" */
        global $modo_desarrollo;
        $ordenes_array = array();
        
        if ($modo_desarrollo) {
            $ordenes_raw = json_decode(file_get_contents(".\\mock_data\\ordenes\\ordenes".$HC.".json"), true);
        } else {
            $ordenes_raw = json_decode(file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=ordenestot&HC=".$HC), true);
        }
        foreach ($ordenes_raw['ordenestot'] as $orden) {
            $timestamp_labo = DateTime::createFromFormat('d/m/Y H:i', $orden['ordenestot']['RECEPCION']);
            $ordenes_array[] = array("n_solicitud" => $orden['ordenestot']['NRO_SOLICITUD'], "timestamp" => $timestamp_labo);
        }
        return $ordenes_array;
    }

    function procesar_estudio($orden, $timestamp) {
        /* Busca los resultados de laboratorio de una orden en el webservice "estudiostot", y los preprocesa para darles la siguiente estructura final:
         * array(
         *      "HC" => 987654
         *      "Nombre" => FORD, JOHN
         *      "Cama" => 601
         *      "timestamp" => "20/11/2020 06:00"
         *      "solicitud" => 01234567,
         *      "Hemograma" => array(
         *              "HTO" => array(
         *                      "nombre_estudio" => "Hematocrito",
         *                      "resultado" => "34",
         *                      "unidades" => "%"
         *                      )
         *              "LEU" => array(
         *                      nombre_estudio => Leucocitos
         *                      "resultado => 11.2"
         *                      )
         *              (...)
         *              )
         *      "Hepatograma" => array(
         *              (...)
         *              )    
         * )
         */
        global $modo_desarrollo, $agrupar_estudios_array, $grupo_estudios_actual;
        $estudio_array = array();
        $estudio_array['solicitud'] = $orden;
        $estudio_array['timestamp'] = $timestamp;  // El timestamp del estudio viene de ordenestot
        
        ## Recopilación de los datos
        if (!$modo_desarrollo) {
            $estudio_raw = json_decode(file_get_contents("http://172.24.24.131:8007/html/internac.php?funcion=estudiostot&orden=".$orden), true);
        } else { // Debugging mode
            $estudio_raw = json_decode(@ file_get_contents(".\\mock_data\\estudios\\estudio_".$orden.".json"), true);
            if (!$estudio_raw) {
                return NULL;
            }
        }
        // Preprocesamiento de los datos del estudio:
        // Agrupa cada resultado del laboratorio segun los grupos definidos en $agrupar_estudios_array (Hemograma, hepatograma, etc)
        foreach ($estudio_raw['estudiostot'] as $estudio) {	
            $codigo = $estudio['estudiostot']['CODANALISI']; // 3 o 4 letras en mayúscula que identifican un estudio. Ej: HTO = Hematocrito.
            
            if (in_array($codigo, $agrupar_estudios_array['Excluir'])) { 
                continue;
            }
            if ($estudio['estudiostot']['NOMANALISIS'] == " ") { # Algunos "resultados" que en realidad no lo son. Ej: orden de material descartable, interconsultas)
                continue;
            }
            if (is_null($estudio['estudiostot']['UNIDAD'])) { 
                $estudio['estudiostot']['UNIDAD'] = ""; 
            }
            if ($estudio['estudiostot']['NOMANALISIS'] == "Total") { 
                $estudio['estudiostot']['NOMANALISIS'] = "Bilirrubina total";
            }
            if ($estudio['estudiostot']['NOMANALISIS'] == "Directa") { 
                $estudio['estudiostot']['NOMANALISIS'] = "Bilirrubina directa";
            }
            if ($estudio['estudiostot']['NOMANALISIS'] == "Indirecta") { 
                $estudio['estudiostot']['NOMANALISIS'] = "Bilirrubina indirecta";
            }

             # Itera en los distintos $grupos de $estudios predefinidos, buscando a cual pertenece este $estudio. Cuando lo encuentra, break.
             # Si no lo encuentra en ningun grupo: permanece $categoria_encontrada = false, y el grupo es "Otros".
            $categoria_encontrada = false;
            foreach ($agrupar_estudios_array as $grupo => $estudios) { 
                if (in_array($codigo, $estudios)) {
                    $grupo_del_estudio = $grupo;
                    $categoria_encontrada = true;
                    break; 
                }
            }
            if (!$categoria_encontrada) {
                $grupo_del_estudio = 'Otros';
            }
            
            // Comienza a crear el array que devuelve la función
            $estudio_array[$grupo_del_estudio][$codigo] = array(
            'nombre_estudio' => $estudio['estudiostot']['NOMANALISIS'], 
            'resultado' => $estudio['estudiostot']['RESULTADO'],
            'unidades' => $estudio['estudiostot']['UNIDAD'],
            'color' => "black", // Color con el que se mostrará. Las alertas pueden modificar este parámetro más adelante
            'info' => ""  // Snippet que se muestra al poner el mouse sobre el valor. Las alertas pueden agregar info acá.
            );
        }
        
        // Postprocesamiento de los datos.
        # Ordena los resultados según el orden predefinido en agrupar_estudios_array: primero el orden de los grupos, luego orden de estudios.
        uksort($estudio_array, "ordenar_grupos_de_estudios");
        foreach ($estudio_array as $key => $value) {
            $grupo_estudios_actual = $key;
            if ($key == "solicitud" or $key == "timestamp") {
                continue;
            }
            uksort($value, "ordenar_estudios");
            $estudio_array[$key] = $value;
            }
        
        // Devuelve array('HC', 'Nombre', 'Cama', 'timestamp', 'solicitud', grupo de estudios => codigo => array(nombre_estudio, resultado, unidades, color, info)...)
        return $estudio_array; 
    }

    
    
    ### MODULO DE ANÁLISIS DE LOS RESULTADOS Y GENERACIÓN DE ALERTAS
    function analisis_de_alertas($estudios_de_hoy, $todos_los_estudios, $piso) {
        /* Sigue una serie de reglas preestablecidas para detectar resultados anormales. En caso de detectarse, agrega al array de resultados
         * una descripción del alerta en el campo "info", la cual se mostrará en un snippet por el frontend. Además, cambia el valor "color" en 
         * ese mismo array, lo cual se verá reflejado desde el frontend con un cambio en el color del texto de dicho resultado.
         * 
         * Entrada: $estudios_de_hoy: Se limita a los resultados de las últimas 24hs, que son los que se analizan y se muestran en el frontend.
         *          $todos_los_estudios: Se utiliza solo con fines de análisis, para comparar los $estudios_de_hoy  con otros resultados previos. 
         *          No se muestran en el frontend directamente.  
         *          $piso.
         * Salida: aray $estudios_de_hoy modificado en sus campos "info" y "color" de los estudios que lo requieran
         * Funcionamiento: Itera cada uno de las órdenes de estudios, luego itera sobre cada estudio individual (Hematocrito, hemoglobina, etc).
         * De cada estudio individual, busca si dicho estudio tiene una serie de reglas, en cuyo caso las sigue.
         * En general estas reglas son comparaciones con valores de corte absolutos. En algunos casos
         * pueden ser comparaciones relativas a valores previos, en dicho caso también itera sobre los estudios previos para compararlos.
           Ej: Si el estudio es HTO (Hematocrito) y el valor es < 21, info= 'Anemia severa', color = 'red'.  */
         
        foreach($estudios_de_hoy as $key_estudio => $estudio_analizado) {
            foreach(array_slice($estudio_analizado, 5, -2) as $key_grupos => $grupo_de_estudios) {
                foreach($grupo_de_estudios as $key_codigo => $array_resultado) {
                    $resultado_de_hoy = $array_resultado['resultado'];
                    
                    if (!is_numeric($resultado_de_hoy)) { # Esto podría cambiarse más adelante, pero en principio solo analiza resultados numéricos.
                            continue; 
                    }
                    
                    #ANALISIS DE HEMOGRAMA
                    #Hematocrito
                    if ($key_codigo == "HTO") { 
                        #Puntos de corte:
                        if ($resultado_de_hoy < 40 and $resultado_de_hoy > 21) {
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "orange";
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Anemia. ";  
                        }
                        if ($resultado_de_hoy <= 21) {
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Anemia severa con probable requerimiento tranfusional. ";  
                        }
                        # Analisis comparativo con resultados previos. Busca caídas bruscas del hematocrito en las últimas 96hs.
                        $estudios_comparativos = array_filter($todos_los_estudios, function($estudio_a_comparar) use($estudio_analizado) {return $estudio_a_comparar["timestamp"] < $estudio_analizado['timestamp'] && $estudio_a_comparar["HC"] == $estudio_analizado['HC'] && isset($estudio_a_comparar["Hemograma"]["HTO"]);});
                        foreach($estudios_comparativos as $estudio_a_comparar) {
                            $delta_resultado = $resultado_de_hoy - $estudio_a_comparar["Hemograma"]["HTO"]["resultado"];
                            $delta_tiempo = $estudio_analizado["timestamp"]->diff($estudio_a_comparar["timestamp"]);
                            $delta_horas = $delta_tiempo->h;
                            $delta_horas += $delta_tiempo->days*24;  # Delta en num de horas entre el estudio actual y el estudio viejo con el que comparo
                            if (($delta_resultado <= -7 and $delta_horas <=48) or ($delta_resultado <= -10 and $delta_horas <=96)) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Caida de " . $delta_resultado . " puntos en " . $delta_horas . " hs. ";  
                            }
                        } 

                    }
                    #Hemoglobina
                    if ($key_codigo == "HGB") {
                        if ($resultado_de_hoy <= 7) {
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Anemia con probable requerimiento tranfusional. ";  

                        }
                    }
                    #Plaquetas
                    if ($key_codigo == "PLLA") { 
                        if (10 < $resultado_de_hoy and $resultado_de_hoy <= 20) {
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "orange";
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Plaquetopenia severa. ";  
                        }
                        if ($resultado_de_hoy <= 10) {
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Plaquetopenia con requerimiento tranfusional. ";  
                        }
                    }
                    # FIN DE HEMOGRAMA
                    # INICIO DE FUNCION RENAL
                    # Creatinina
                    if ($key_codigo == "CRE") { 
                        #Puntos de corte:
                        if ($resultado_de_hoy > 1.4) {
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "orange";
                        }

                        #Comparación con resultados de dias previos
                        $estudios_comparativos = array_filter($todos_los_estudios, function($estudio_a_comparar) use($estudio_analizado) {return $estudio_a_comparar["timestamp"] < $estudio_analizado['timestamp'] && isset($estudio_a_comparar["Funcion renal"]["CRE"]);});
                        foreach($estudios_comparativos as $estudio_a_comparar) {
                            if ($estudio_a_comparar['HC'] != $estudio_analizado["HC"]) {
                            continue;
                            }
                            $creatinina_previa = $estudio_a_comparar["Funcion renal"]["CRE"]["resultado"];
                            $delta_resultado = $resultado_de_hoy - $creatinina_previa;
                            $delta_tiempo = $estudio_analizado["timestamp"]->diff($estudio_a_comparar["timestamp"]);
                            $delta_horas = $delta_tiempo->h;
                            $delta_horas += $delta_tiempo->days*24;
                            if (($delta_resultado >= 0.3 and $delta_horas <=48 and $resultado_de_hoy < 3) or ($resultado_de_hoy > ($creatinina_previa * 1.5) and $delta_horas <=72)) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] = "AKI. Aumento de " . $delta_resultado . "mg/dl en " . $delta_horas . " hs. ";  
                            }
                        } 
                    }


                    # MEDIO INTERNO
                    # Sodio:
                    if ($key_codigo == "NAS") {
                        if ($resultado_de_hoy <= 130 and $resultado_de_hoy > 125) {
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "orange";
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Hiponatremia moderada. ";
                        }
                        if ($resultado_de_hoy <= 125) {
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Hiponatremia severa. ";
                        }

                        $estudios_comparativos = array_filter($todos_los_estudios, function($estudio_a_comparar) use($estudio_analizado) {return $estudio_a_comparar["timestamp"] < $estudio_analizado['timestamp'] && isset($estudio_a_comparar["Medio interno"]["NAS"]);});
                        foreach($estudios_comparativos as $estudio_a_comparar) {
                            if ($estudio_a_comparar['HC'] != $estudio_analizado["HC"]) {
                            continue;
                            }
                            $sodio_previo = $estudio_a_comparar["Medio interno"]["NAS"]["resultado"];
                            $delta_resultado = $resultado_de_hoy - $sodio_previo;
                            $delta_tiempo = $estudio_analizado["timestamp"]->diff($estudio_a_comparar["timestamp"]);
                            $delta_horas = $delta_tiempo->h;
                            $delta_horas += $delta_tiempo->days*24;
                            if (abs($delta_resultado) >= 10 and $delta_horas <=36) {
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                                $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Shift de sodio de " . $delta_resultado . "mEq/l en " . $delta_horas . " hs. ";  
                            }
                        } 

                    }
                    # Potasio:
                    if ($key_codigo == "KAS") {
                        if (($resultado_de_hoy < 4 and in_array($piso, array("3", "5", "6"))) or $resultado_de_hoy < 3.5) {
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "orange";
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Hipokalemia. ";
                        }
                        if ($resultado_de_hoy < 3) {
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "HIPOKALEMIA SEVERA. ";
                        }
                        if ($resultado_de_hoy > 5.5) {
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "orange";
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "Hiperkalemia. ";
                        }
                        if ($resultado_de_hoy > 6) {
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["color"] = "red";
                            $estudios_de_hoy[$key_estudio][$key_grupos][$key_codigo]["info"] .= "HIPERKALEMIA SEVERA. ";
                        }
                    }
                }
            }
        }
    return $estudios_de_hoy; # Devuelve un array con la misma estructura de la entrada, modificando "info" y "color" con las alarmas que surgieran.
    }  

    function textificar_array($estudios) {
                    /* Resume el resultado de un laboratorio entero en dos strings, y las agrega al array de estudios.
                            - $textificado_corto: Modo compacto y resumido para el pase. Agrupa algunos estudios (hepatograma, coagulograma) con una sintaxis
                            tipo 12/34/56/78/90. No usa unidades. Omite algunos resultados no relevantes.
                            - $textificado_largo: Modo expresivo, completo, con unidades, que cumple con formalidades médico-legales

                            returns: nuevo array de estudios actualizado con las strings.
                            */
        global $abreviar_nombres_estudios;
        foreach ($estudios as $key => $solicitud) {
            $textificado_largo = "";
            $textificado_corto = "";
            foreach(array_slice($solicitud, 5) as $key_grupos => $grupo_de_estudios) {
                                    // Formateo especial para hepatograma completo tipo: "H: GOT/GPT/FAL/Bili total / Bili direta / GGT".
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

                                    // Formateo especial para el coagulograma tipo "C: TP/RIN/APTT":
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
                                    
                                    // Formateo de estado ácido base / gasometria
                                    if ($key_grupos == "Gasometria") {
                                            if ($solicitud["Gasometria"]["TM"]["resultado"] == "AR") { 
                                                    $EAB = "EABa ";			
                                            }
                                            else {
                                                    $EAB = "EABv ";			
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

                // Formateo del resto de estudios no contemplados en los casos especiales previos
                // NOTA: En textificado_corto, sólo se muestran los estudios especificamente incluidos en el array $abreviar_nombres_estudios
                // el cual se configura desde /include/vars.php . Para agregar nuevos estudios, remitirse a dicho archivo.
                // $textificado_largo muestra todos, excepto los que hayan sido por el array $agrupar_estudios_array alojado en el mismo fichero.
                foreach($grupo_de_estudios as $codigo_de_estudio => $estudio) {
                    $textificado_largo .= $estudio["nombre_estudio"] . " " . $estudio["resultado"] . " " . $estudio["unidades"] . ", ";
                    if (isset($abreviar_nombres_estudios[$codigo_de_estudio])) { 
                        $textificado_corto .= $abreviar_nombres_estudios[$codigo_de_estudio] . " " . $estudio["resultado"] . " ";
                    }
                } 
            }
            $estudios[$key]["text_largo"] = substr($textificado_largo, 0, -2);
            $estudios[$key]["text_corto"] = $textificado_corto;
        }
        return $estudios;
    }

    
    ### FUNCIONES AUXILIARES:
    # Prox 2 funciones: usadas por uksort para emparejar el orden de los resultados con el preestablecido en $agrupar_estudios_array
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

    function formatear_fechas_visualizacion($estudio) {
        $estudio["timestamp"] = $estudio["timestamp"]->format("d/m/Y H:i");
        return $estudio;
    }
    
    function guardar_cache($file, $array_final) {
        $fp = fopen($file, 'w');
        fwrite($fp, $array_final);
        fclose($fp);
    }
    
/*  function agrupar_por_pacientes($array_original, $pacientes) {
        # FUNCION DESHABILITADA.
        # Quizás utilice esto en algun momento para emparchar la estructura de datos. El objetivo es  agrupar los estudios por paciente. 
        * Requiere cambios en el frontend.
        $array_resultado = array();
        foreach ($pacientes as $paciente) {
            $array_resultado[$paciente['HC']] = $paciente;
            foreach ($array_original as $estudio) {
                if ($estudio['HC'] == $paciente['HC']) {
                    $nuevo_array_estudios = array("Solicitud" => array_slice($estudio, 3, $length = 2) + array_slice($estudio, -2)) + array_slice($estudio, 5, -2);
                    $array_resultado[$estudio["HC"]][]= $nuevo_array_estudios;
                }
            }
        }
        return $array_resultado;
    }
 */
 
#### MAIN LOOP   ####
$todos_los_estudios = array();
$piso = filter_input(INPUT_GET, "piso", FILTER_SANITIZE_NUMBER_INT);
$pacientes = pacientes_por_piso($piso);
foreach ($pacientes as $paciente) { // Loop que construye el array con todos los estudios de un cierto piso.
	foreach(ordenes_de_paciente($paciente['HC']) as $orden) {
            $resultado = procesar_estudio($orden['n_solicitud'], $orden['timestamp']);
            if($resultado == NULL)continue;
            $todos_los_estudios[] = array_merge($paciente, $resultado);
	}
}

$estudios_de_hoy = array_filter($todos_los_estudios, function($estudio) use($fecha_menos_24h) { return $estudio["timestamp"] > $fecha_menos_24h;});
$estudios_de_hoy = textificar_array($estudios_de_hoy); // Le agrega a cada estudio el texto que luego rellena los botones de "Copiar al portapapeles"
$estudios_de_hoy_analizados = analisis_de_alertas($estudios_de_hoy, $todos_los_estudios, $piso); // Genera las alertas de los estudios.
$estudios_analizados_formateados = array_map("formatear_fechas_visualizacion", $estudios_de_hoy_analizados); // Le da el formato a la fecha del estudio
$array_final_JSON = json_encode(array_values($estudios_analizados_formateados));
guardar_cache($cache_file, $array_final_JSON); // Genera el nuevo cache con el archivo final

echo $array_final_JSON;  // Resultado del archivo.

 /* Estructura del JSON final:
  * [
        {
           "HC":11111,
           "Nombre":"PEREZ, JUAN",
           "Cama":"701A",
           "timestamp":"08\/12\/2020 06:00"   (nuevo)
           "solicitud":"222222222",
           "Hemograma":{
              "HTO":{
                 "nombre_estudio":"Hematocrito",
                 "resultado":"29",
                 "unidades": " "
                 "color":"red", 
  *              "info":"Anemia"  
              },
              "HGB":{
                 "nombre_estudio":"Hemoglobina",
                 "resultado":"12.5",
                 "unidades":"gr/dl",
  *              "color":"black",
  *              "info":""
              }
           }
           "Hepatograma":{
           ....
           }
  *        "text_largo": "Hematocrito 29%, Hemoglobina 12.5gr/dl",  
  *        "text_corto": "Hto 29, Hb 12.5"
       },
       {
           "HC":11112,
           "Nombre": "Clooney, George"
           ....
       }
   ]
  */







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
