<?php

require_once __DIR__ . '/lanzou_parser.php';

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    header('Content-Type: application/json; charset=utf-8');
    la_handle_request();
}
