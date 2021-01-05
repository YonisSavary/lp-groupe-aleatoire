<?php

require "../kernel/autoloader.php";

use Kernel\Router;

$router = new Router();
$router->routeRequest();