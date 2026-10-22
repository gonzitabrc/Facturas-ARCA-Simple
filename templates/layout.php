<?php
/**
 * Facturador ARCA simple
 * Copyright (c) 2026 DDW — https://ddw.com.ar
 * Licencia MIT: ver el archivo LICENSE.
 *
 * Layout base. Se usa siempre vía App::render($titulo, $contenido).
 *
 * @var string      $titulo
 * @var string      $contenido
 * @var string|null $usuario
 * @var array|null  $avisoCert   aviso de certificado por vencer (opcional)
 */

declare(strict_types=1);

use Facturador\App;

$aviso = App::aviso();
$pagina = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
$menu = [
    'index.php'         => 'Lotes',
    'configuracion.php' => 'Configuración',
    'ayuda.php'         => 'Ayuda',
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= App::esc($titulo) ?> · Facturador ARCA simple</title>
    <link rel="stylesheet" href="assets/app.css?v=3">
</head>
<body<?= App::esDemo() ? ' data-demo="1" data-demo-mensaje="' . App::esc(App::MENSAJE_DEMO) . '" data-demo-contacto="' . App::esc((string) App::config('demo_contacto', 'https://ddw.com.ar')) . '"' : '' ?>>
<?php if (App::esDemo()): ?>
    <div class="cinta-demo">
        <span><strong>Demostración.</strong> <?= App::esc(App::MENSAJE_DEMO) ?></span>
        <a class="boton" href="<?= App::esc((string) App::config('demo_contacto', 'https://ddw.com.ar')) ?>" target="_blank" rel="noopener">Contactanos</a>
    </div>
<?php endif; ?>
<header class="barra">
    <a class="marca" href="index.php">Facturador <span>ARCA</span> simple</a>
    <?php if ($usuario !== null): ?>
        <nav>
            <?php foreach ($menu as $archivo => $nombre): ?>
                <a href="<?= App::esc($archivo) ?>"<?= $pagina === $archivo ? ' class="actual"' : '' ?>><?= App::esc($nombre) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if (\Facturador\Seguridad\Auth::loginRequerido()): ?>
            <div class="sesion">
                <span><?= App::esc($usuario) ?></span>
                <a href="salir.php">Salir</a>
            </div>
        <?php elseif (!App::esDemo()): ?>
            <div class="sesion"><span class="etiqueta">sin clave de acceso</span></div>
        <?php endif; ?>
    <?php else: ?>
        <nav>
            <a href="ayuda.php"<?= $pagina === 'ayuda.php' ? ' class="actual"' : '' ?>>Ayuda</a>
        </nav>
        <div class="sesion"><a href="login.php">Entrar</a></div>
    <?php endif; ?>
</header>

<main>
    <?php if ($aviso !== null): ?>
        <p class="aviso <?= App::esc($aviso['tipo']) ?>"><?= App::esc($aviso['texto']) ?></p>
    <?php endif; ?>

    <?php if (!App::esDemo() && !\Facturador\Seguridad\Auth::loginRequerido() && (App::emisor()['ambiente'] ?? 'homo') === 'prod'): ?>
        <p class="aviso error">
            La app está en producción y sin clave de acceso: cualquiera que llegue a esta URL puede emitir
            facturas. Poné <code>'login_requerido' =&gt; true</code> en <code>config/config.php</code>.
        </p>
    <?php endif; ?>

    <?php if (!empty($avisoCert)): ?>
        <p class="aviso atencion">
            <?= App::esc($avisoCert['texto']) ?>
            <a href="configuracion.php">Renovar el certificado</a> ·
            <a href="ayuda.php#renovar">Cómo se hace</a>
        </p>
    <?php endif; ?>

    <h1><?= App::esc($titulo) ?></h1>
    <?= $contenido ?>
</main>

<footer class="pie">
    <span>
        Emisión de facturas electrónicas con el certificado del propio emisor.
        <a href="ayuda.php">Ayuda</a> ·
        <a href="ayuda.php#alcances">Condiciones de uso</a>
    </span>
    <span class="firma">Desarrollado por <a href="https://ddw.com.ar" target="_blank" rel="noopener">DDW</a></span>
</footer>
<script src="assets/app.js?v=3"></script>
</body>
</html>
