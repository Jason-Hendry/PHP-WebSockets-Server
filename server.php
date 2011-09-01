<?php

include 'WebSocketServer.php';
// Start WebSocketServer
$server = new WebSocketServer("0.0.0.0",12345);
$server->debug = true;
$server->begin();
