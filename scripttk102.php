<?php

$ip_address = "MI_DIRECCION_IP";
$port = "MI_PUERTO";

$trigger_status = false;
$trigger_payload = "";

$server = stream_socket_server("tcp://$ip_address:$port", $errno, $errorMesage);

if (!$server) {
	echo "No se ha establecido la conexión con el GPS.";
}

if ($server) {
	echo "Conexión establecida. Esperando datos...";
}
$client_sockets = array();

while (true) {

	$read_sockets = $client_sockets;
	$read_sockets[] = $server;

	if (!stream_select($read_sockets, $write, $except, 300000)) {
		die('stream_select error.');
	}

	if (in_array($server, $read_sockets)) {
		$socket = @stream_socket_accept($server);

		if ($socket) {
			$client_sockets[] = $socket;
			echo "GPS conectados: " . count($client_sockets) . "\n";
		}
		if (!$socket) {
			echo "No se creó el socket del GPS.";
		}

		unset($read_sockets[ array_search($server, $read_sockets) ]);
	}

	foreach ($read_sockets as $socket) {
		$data = fread($socket, 512);

		echo "data: " . $data . "\n"; 
		/*
		Manejo el protocolo de los GPS TK-102, para referencia. Voy a mostrar un ejemplo de cómo llegan normalmente:

		datos: imei:359587010124900,tracker,0809231929,13554900601,F,112909.397,A,2234.4669,N,11354.3287,E,0.11,;

		Pero a veces los datos llegan así:

		datos: cker,0809231929,13554900601,F,112909.397,A,2234.4669,N,11354.3287,E,0.11,;imei:359587010124900,tracker,0809231929,13554900601,F,112909.397,A,2234.4669,N,11354.3287,E,0.11,;

		Y no tengo idea de por qué...
		*/

		$tk102_data = explode( ',', $data);
		$response = "";

		if ($trigger_status && $trigger_payload !== "") {
			fwrite($socket, $trigger_payload);
			echo "Enviamos " . $trigger_payload . " al GPS.\n";
			$trigger_status = false;
			$trigger_payload = "";
		}

		switch (count($tk102_data)) {
			case 1: 
				$response = "ON";
				echo "Enviamos ON al GPS\n";
				break;
			case 3: 
				if ($tk102_data[0] == "##") {
					$response = "LOAD";
					echo "Enviamos LOAD al GPS\n";
				}
				break;
			case 6:
				$imei = substr($tk102_data[0], 5);
				$alarm = $tk102_data[1];
				if (strcmp($alarm,"help me") == 0) {
					send_notification($imei);
				}
				break;
			case 13:
				echo "Conexión establecida. Recibiendo datos GPS...\n";
				$imei = substr($tk102_data[0], 5);
				$alarm = $tk102_data[1];
				$gps_time = nmea_to_mysql_time($tk102_data[2]);
				$latitude = degree_to_decimal($tk102_data[7], $tk102_data[8]);
				$longitude = degree_to_decimal($tk102_data[9], $tk102_data[10]);
				$speed_in_knots = $tk102_data[11];
				$speed_in_km = 1.852 * $speed_in_knots;
				//$bearing = $tk102_data[12];

				echo "El imei es " . $imei . ".\n";
				echo "Estado actual de la alarma: " . $alarm . ".\n";
				echo "La fecha reportada del GPS es: " . $gps_time . ".\n";
				echo "La latitud es: " . $latitude . " y la longitud es: " . $longitude . ".\n";
				break;
			case 15:
				if ($tk102_data[1] == "109" || $tk102_data == "509") {
					echo "El vehículo se ha apagado exitosamente. \n";
				} else if ($tk102_data[1] == "110") {
					echo "El vehículo se ha restaurado exitosamente. \n";
				}
			default:
				break;
		}

		if (!$data) {
			unset($client_sockets[ array_search($socket, $client_sockets) ]);
			@fclose($socket);
			echo "Cliente desconectado. Clientes conectados: ". count($client_sockets) . "\n";
			continue;
		}

		if (sizeof($response) > 0) {
			fwrite($socket, $response);
		}
	}
}

// Función para convertir el tiempo del GPS a Fecha que posteriormente se envía como String
function nmea_to_mysql_time($date_time){

	$year = substr($date_time,0,2);
	$month = substr($date_time,2,2);
	$day = substr($date_time,4,2);
	$hour = substr($date_time,6,2);
	$minute = substr($date_time,8,2);
	$second = substr($date_time,10,2);

	return date("Y-m-d H:i:s", mktime($hour,$minute,$second,$month,$day,$year));
}

// Conversor de grados a decimales, para la ubicación
function degree_to_decimal($coordinates_in_degrees, $direction){

	$degrees = (int)($coordinates_in_degrees / 100);
	$minutes = $coordinates_in_degrees - ($degrees * 100);
	$seconds = $minutes / 60;
	$coordinates_in_decimal = $degrees + $seconds;

	if(($direction == "S") || ($direction == "W")) {
		$coordinates_in_decimal = $coordinates_in_decimal * (-1);
	}

	return number_format($coordinates_in_decimal, 6, '.', '');
}

?>
