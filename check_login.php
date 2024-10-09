<?php
    header("Access-Control-Allow-Origin: *");
    session_start();
    if (empty($_SESSION["usuario"])) {
	echo "no";
    	session_write_close();

	die(2);
    }
    session_write_close();

    echo "si";
    exit;
?>

