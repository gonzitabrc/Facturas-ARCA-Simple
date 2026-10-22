<?php
declare(strict_types=1);

require __DIR__ . '/../src/App.php';

use Facturador\App;
use Facturador\Seguridad\Auth;

App::iniciar();

if (Auth::logueado() || !Auth::loginRequerido()) {
    App::redirigir('index.php');
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Auth::exigirCsrf();
    $error = Auth::intentarLogin(
        trim((string) ($_POST['usuario'] ?? '')),
        (string) ($_POST['clave'] ?? '')
    );
    if ($error === null) {
        // Solo se vuelve a una página de la propia app (nada de redirecciones abiertas).
        $volver = basename((string) ($_SESSION['volver_a'] ?? ''));
        unset($_SESSION['volver_a']);
        $destino = (str_ends_with($volver, '.php') && is_file(__DIR__ . '/' . $volver)) ? $volver : 'index.php';
        App::redirigir($destino);
    }
}

$contenido = App::capturar(static function () use ($error): void { ?>
    <form method="post" class="tarjeta angosta">
        <?= Auth::campoCsrf() ?>
        <?php if ($error !== null): ?>
            <p class="aviso error"><?= App::esc($error) ?></p>
        <?php endif; ?>
        <label>Usuario
            <input type="text" name="usuario" autocomplete="username" autofocus required>
        </label>
        <label>Clave
            <input type="password" name="clave" autocomplete="current-password" required>
        </label>
        <button type="submit" class="boton">Entrar</button>
    </form>
<?php });

App::render('Entrar', $contenido);
