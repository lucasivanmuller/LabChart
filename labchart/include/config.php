<?php		

$duracion_del_cache = 2; // En minutos. Si el cache es mas reciente que este parámetro, labchart.php no actualiza los datos sino que usa el cache.
$modo_desarrollo = true;// True, trabaja a nivel local, sin conectarse a la DB, con los datos del directorio /mock_data. 
                       // False: Versión de producción. Se conecta al web-service para recabar los datos de la DB del laboratorio
$modo_solo_cache = false; // Otro modo para pruebas, como su nombre lo indica solo se conecta al cache almacenado. 

?> 