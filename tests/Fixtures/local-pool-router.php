<?php
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/slow')usleep(2000000);
header('Content-Type: application/json');
echo json_encode(['pid'=>getmypid()]);
