<?php
declare(strict_types=1);

require __DIR__ . '/../src/App.php';

use Facturador\App;
use Facturador\Seguridad\Auth;

App::iniciar();
Auth::cerrarSesion();
App::redirigir('login.php');
