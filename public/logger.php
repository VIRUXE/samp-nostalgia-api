<?php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

function setupLogger($db) {
    $logger = new Logger('session_logger');

    // Log to the debug.log file
    $logger->pushHandler(new StreamHandler(__DIR__ . '/debug.log', Logger::DEBUG));

    return $logger;
}
