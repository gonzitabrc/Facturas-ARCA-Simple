<?php
declare(strict_types=1);

namespace Facturador\Seguridad;

use Facturador\App;

/**
 * Login simple contra los usuarios de config.php + token CSRF.
 * No hay tabla de usuarios: la app es de un cliente y la maneja una o dos personas.
 */
final class Auth
{
    private const MAX_INTENTOS = 5;
    private const BLOQUEO_SEG = 300;

    /**
     * Con 'login_requerido' => false en config.php la app no pide clave.
     * Es solo para probar en una máquina local: en producción va en true.
     */
    public static function loginRequerido(): bool
    {
        return (bool) App::config('login_requerido', true);
    }

    public static function usuario(): ?string
    {
        $usuario = $_SESSION['usuario'] ?? null;
        if (is_string($usuario) && $usuario !== '') {
            return $usuario;
        }
        return self::loginRequerido() ? null : 'pruebas';
    }

    public static function logueado(): bool
    {
        return self::usuario() !== null;
    }

    /** Corta la ejecución y manda al login si no hay sesión. */
    public static function exigirSesion(): void
    {
        if (!self::loginRequerido()) {
            return;
        }
        if (!self::logueado()) {
            $destino = $_SERVER['REQUEST_URI'] ?? '';
            $_SESSION['volver_a'] = is_string($destino) ? $destino : '';
            App::redirigir('login.php');
        }
    }

    /**
     * Devuelve null si entró, o el mensaje de error para mostrar.
     * Mensaje único para usuario o clave incorrectos: no se revela qué falló.
     */
    public static function intentarLogin(string $usuario, string $clave): ?string
    {
        $espera = self::segundosBloqueado();
        if ($espera > 0) {
            return 'Demasiados intentos fallidos. Probá de nuevo en ' . (int) ceil($espera / 60) . ' minuto(s).';
        }

        $usuarios = (array) App::config('usuarios', []);
        $hash = $usuarios[$usuario] ?? null;

        // Se verifica siempre un hash para que el tiempo de respuesta no delate si el usuario existe.
        $hashAComparar = is_string($hash) && $hash !== ''
            ? $hash
            : '$2y$10$usuarioinexistenteusuarioinexistenteusuarioinexistenteusuarioi';

        if (!password_verify($clave, $hashAComparar) || !is_string($hash)) {
            self::registrarFallo();
            App::log('Login fallido para "' . $usuario . '" desde ' . self::ip());
            return 'Usuario o clave incorrectos.';
        }

        self::limpiarFallos();
        session_regenerate_id(true);
        $_SESSION['usuario'] = $usuario;
        $_SESSION['desde'] = time();
        App::log('Login OK de "' . $usuario . '" desde ' . self::ip());
        return null;
    }

    public static function cerrarSesion(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'httponly' => true,
                'secure'   => (bool) $p['secure'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    // -------------------------------------------------------------------- CSRF

    public static function tokenCsrf(): string
    {
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /** Campo oculto listo para pegar en un formulario. */
    public static function campoCsrf(): string
    {
        return '<input type="hidden" name="csrf" value="' . App::esc(self::tokenCsrf()) . '">';
    }

    public static function csrfValido(?string $token): bool
    {
        $esperado = $_SESSION['csrf'] ?? null;
        return is_string($esperado) && is_string($token) && hash_equals($esperado, $token);
    }

    /** Verifica el CSRF de un POST; corta con mensaje claro si no coincide. */
    public static function exigirCsrf(): void
    {
        $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!self::csrfValido(is_string($token) ? $token : null)) {
            App::log('CSRF inválido en ' . ($_SERVER['REQUEST_URI'] ?? '?') . ' desde ' . self::ip());
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                App::json(['ok' => false, 'error' => 'La sesión expiró. Recargá la página.'], 419);
            }
            App::morir(
                'La sesión expiró',
                'Recargá la página y volvé a intentar. <a href="' . App::esc($_SERVER['PHP_SELF'] ?? 'index.php') . '">Volver</a>',
                419
            );
        }
    }

    // ------------------------------------------------------ intentos fallidos

    private static function archivoIntentos(): string
    {
        return App::storage('intentos.json');
    }

    private static function intentos(): array
    {
        $archivo = self::archivoIntentos();
        if (!is_file($archivo)) {
            return [];
        }
        $datos = json_decode((string) @file_get_contents($archivo), true);
        return is_array($datos) ? $datos : [];
    }

    private static function segundosBloqueado(): int
    {
        $registro = self::intentos()[self::ip()] ?? null;
        if (!is_array($registro) || (int) ($registro['fallos'] ?? 0) < self::MAX_INTENTOS) {
            return 0;
        }
        $restante = (int) ($registro['ultimo'] ?? 0) + self::BLOQUEO_SEG - time();
        return max(0, $restante);
    }

    private static function registrarFallo(): void
    {
        $ip = self::ip();
        $intentos = self::intentos();
        $registro = $intentos[$ip] ?? ['fallos' => 0, 'ultimo' => 0];

        // Pasado el bloqueo, el contador arranca de nuevo.
        if (time() - (int) $registro['ultimo'] > self::BLOQUEO_SEG) {
            $registro['fallos'] = 0;
        }
        $registro['fallos'] = (int) $registro['fallos'] + 1;
        $registro['ultimo'] = time();
        $intentos[$ip] = $registro;

        // Limpieza de registros viejos para que el archivo no crezca.
        foreach ($intentos as $clave => $dato) {
            if (time() - (int) ($dato['ultimo'] ?? 0) > 86400) {
                unset($intentos[$clave]);
            }
        }
        @file_put_contents(self::archivoIntentos(), json_encode($intentos), \LOCK_EX);
    }

    private static function limpiarFallos(): void
    {
        $intentos = self::intentos();
        unset($intentos[self::ip()]);
        @file_put_contents(self::archivoIntentos(), json_encode($intentos), \LOCK_EX);
    }

    private static function ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        return is_string($ip) ? $ip : 'desconocida';
    }
}
