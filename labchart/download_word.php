<?php
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json; charset=UTF-8");
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method == "GET"){
        $piso = $_GET['piso'];
    }
    $fecha = date('d/m/Y H_i');

    require "vendor/autoload.php";
    $pw = new \PhpOffice\PhpWord\PhpWord();

    $array = json_decode(file_get_contents("http://http://192.168.15.168:8001/html/labchart/labchart.php?piso=" . $piso), true);
    
    foreach ($array as $estudio) {
        $texto = "<p>(" . $estudio['Cama'] . ") " . $estudio["Nombre"] . " Hora: " . $estudio["timestamp"] . "</p> <p>" . $estudio["text_corto"] . "</p>";
            foreach(array_slice($estudio, 5, -2) as $key_grupos => $grupo_de_estudios) {
                foreach($grupo_de_estudios as $grupo => $estudio) {
                    if (!empty($estudio["info"])) {
                        $texto .= "<p>" . $estudio["info"] . "</p>";
                    }
                }

            }
        $texto .= "<p>  </p>";
        $section = $pw->addSection();
        \PhpOffice\PhpWord\Shared\Html::addHtml($section, $texto, false, false);
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment;filename="labos ' . $fecha  . ' piso ' . $piso . '.docx"');
    $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($pw, 'Word2007');
    $objWriter->save('php://output');

?>
