<?php
declare(strict_types=1);

namespace Facturador;

/**
 * Facturador ARCA simple
 * Copyright (c) 2026 DDW — https://ddw.com.ar
 * Licencia MIT: ver el archivo LICENSE.
 *
 * Arranque de la aplicación: autoload, configuración, sesión, vistas y manejo de errores.
 * Es el único archivo que se incluye a mano desde public/:
 *     require __DIR__ . '/../src/App.php';
 *     \Facturador\App::iniciar();
 */
final class App
{
    private const NOMBRE_SESION = 'facturador';

    private static ?array $config = null;
    private static bool $iniciada = false;

    public static function iniciar(bool $conSesion = true): void
    {
        if (self::$iniciada) {
            return;
        }
        self::$iniciada = true;

        self::registrarAutoload();
        self::registrarManejadores();
        self::cargarConfig();

        // La fecha del comprobante y la tolerancia de días las mira ARCA: PHP y SQLite
        // tienen que estar en la misma zona horaria que el emisor.
        date_default_timezone_set((string) self::config('zona_horaria', 'America/Argentina/Buenos_Aires'));

        if ($conSesion && \PHP_SAPI !== 'cli') {
            self::iniciarSesion();
            self::frenarSiEsDemo();
        }
    }

    // ------------------------------------------------------- modo demostración

    /** La instalación es una demostración: se ve todo, no se puede hacer nada. */
    public static function esDemo(): bool
    {
        return (bool) self::config('modo_demo', false);
    }

    public const MENSAJE_DEMO = 'Este es un demo para que puedas ver las pantallas. '
        . 'Todas las funciones y operaciones están deshabilitadas. '
        . 'Contactanos si te interesa implementar la aplicación en tu negocio o empresa.';

    /**
     * Freno del lado del servidor: en demostración no pasa ninguna acción.
     * El popup del navegador es para avisar; esto es lo que garantiza que no
     * se emita, no se guarde y no se toque un certificado.
     */
    private static function frenarSiEsDemo(): void
    {
        if (!self::esDemo() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        self::log('Acción bloqueada por modo demo: ' . ($_SERVER['REQUEST_URI'] ?? '?'));

        $esApi = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/');

        if ($esApi) {
            self::json(['ok' => false, 'demo' => true, 'error' => self::MENSAJE_DEMO], 403);
        }

        self::aviso(self::MENSAJE_DEMO, 'atencion');
        $volver = basename((string) ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        self::redirigir(str_ends_with($volver, '.php') ? $volver : 'index.php');
    }

    // ---------------------------------------------------------------- autoload

    private static function registrarAutoload(): void
    {
        $vendor = self::raiz() . '/vendor/autoload.php';
        if (is_file($vendor)) {
            require_once $vendor;
        }

        // Autoload propio para Facturador\: la app tiene que arrancar incluso
        // si en el hosting todavía no se corrió composer install.
        spl_autoload_register(static function (string $clase): void {
            if (!str_starts_with($clase, 'Facturador\\')) {
                return;
            }
            $relativa = str_replace('\\', '/', substr($clase, strlen('Facturador\\')));
            $archivo = self::raiz() . '/src/' . $relativa . '.php';
            if (is_file($archivo)) {
                require_once $archivo;
            }
        });
    }

    // ----------------------------------------------------------- configuración

    private static function cargarConfig(): void
    {
        $archivo = self::raiz() . '/config/config.php';
        if (!is_file($archivo)) {
            self::morir(
                'Falta la configuración',
                'Copiá <code>config/config.example.php</code> a <code>config/config.php</code> y completá '
                . '<code>app_key</code>, <code>app_url</code> y el usuario.'
            );
        }

        $config = require $archivo;
        if (!is_array($config)) {
            self::morir('Configuración inválida', 'El archivo <code>config/config.php</code> tiene que devolver un array.');
        }
        self::$config = $config;

        $key = (string) self::config('app_key', '');
        if (strlen($key) !== 64 || !ctype_xdigit($key)) {
            self::morir(
                'Falta la clave de la aplicación',
                'Completá <code>app_key</code> en <code>config/config.php</code> con 64 caracteres hexadecimales. '
                . 'Generala con: <code>php -r "echo bin2hex(random_bytes(32));"</code>'
            );
        }
        if (!is_array(self::config('usuarios')) || self::config('usuarios') === []) {
            self::morir('Falta el usuario', 'Agregá al menos un usuario en <code>usuarios</code> de <code>config/config.php</code>.');
        }
    }

    /** Lee la configuración con notación de puntos: config('arca.homo.wsaa'). */
    public static function config(?string $clave = null, mixed $defecto = null): mixed
    {
        if (self::$config === null) {
            self::cargarConfig();
        }
        if ($clave === null) {
            return self::$config;
        }

        $valor = self::$config;
        foreach (explode('.', $clave) as $parte) {
            if (!is_array($valor) || !array_key_exists($parte, $valor)) {
                return $defecto;
            }
            $valor = $valor[$parte];
        }
        return $valor;
    }

    /** Configuración de ARCA del ambiente indicado ('homo' | 'prod'). */
    public static function configArca(string $ambiente): array
    {
        $datos = self::config('arca.' . $ambiente);
        if (!is_array($datos)) {
            throw new \RuntimeException("Ambiente de ARCA desconocido: {$ambiente}");
        }
        return $datos + [
            'timeout_conexion_seg'  => (int) self::config('arca.timeout_conexion_seg', 15),
            'timeout_respuesta_seg' => (int) self::config('arca.timeout_respuesta_seg', 45),
        ];
    }

    // ------------------------------------------------------------------ rutas

    public static function raiz(): string
    {
        return dirname(__DIR__);
    }

    /** Ruta absoluta dentro de storage/, creando el directorio si hace falta. */
    public static function storage(string $relativa = ''): string
    {
        $base = (string) self::config('rutas.storage', self::raiz() . '/storage');
        $ruta = rtrim($base, '/\\') . ($relativa === '' ? '' : '/' . ltrim($relativa, '/\\'));
        $dir = str_contains(basename($ruta), '.') ? dirname($ruta) : $ruta;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("No se pudo crear el directorio {$dir}");
        }
        return $ruta;
    }

    /** URL pública de la app, sin barra final. */
    public static function url(string $ruta = ''): string
    {
        $base = rtrim((string) self::config('app_url', ''), '/');
        return $base . ($ruta === '' ? '' : '/' . ltrim($ruta, '/'));
    }

    // ----------------------------------------------------------------- sesión

    private static function iniciarSesion(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        session_name(self::NOMBRE_SESION);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $https,   // en producción el sitio va con SSL obligatorio
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    // ----------------------------------------------------------------- vistas

    public static function esc(?string $texto): string
    {
        return htmlspecialchars((string) $texto, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Dibuja una página completa con el layout. */
    public static function render(string $titulo, string $contenido, array $datos = []): void
    {
        $datos['titulo'] = $titulo;
        $datos['contenido'] = $contenido;
        $datos['usuario'] = Seguridad\Auth::usuario();
        extract($datos, \EXTR_SKIP);
        require self::raiz() . '/templates/layout.php';
    }

    /** Captura la salida de un bloque para pasarla al layout. */
    public static function capturar(callable $fn): string
    {
        ob_start();
        try {
            $fn();
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public static function redirigir(string $ruta): never
    {
        header('Location: ' . $ruta, true, 302);
        exit;
    }

    /** Mensaje para la próxima pantalla (patrón flash). */
    public static function aviso(?string $texto = null, string $tipo = 'ok'): ?array
    {
        if ($texto !== null) {
            $_SESSION['aviso'] = ['texto' => $texto, 'tipo' => $tipo];
            return null;
        }
        $aviso = $_SESSION['aviso'] ?? null;
        unset($_SESSION['aviso']);
        return $aviso;
    }

    public static function json(array $datos, int $codigo = 200): never
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($datos, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ----------------------------------------------------- errores y bitácora

    private static function registrarManejadores(): void
    {
        error_reporting(\E_ALL);
        ini_set('display_errors', '0');

        set_error_handler(static function (int $nivel, string $msg, string $archivo = '', int $linea = 0): bool {
            if ((error_reporting() & $nivel) === 0) {
                return false;
            }
            // Los avisos de las librerías (PhpSpreadsheet lee XLSX con namespaces raros)
            // se registran pero no cortan la operación. Los de nuestro código sí.
            if (str_contains(str_replace('\\', '/', $archivo), '/vendor/')) {
                self::log("Aviso de librería [{$nivel}] {$msg} en {$archivo}:{$linea}");
                return true;
            }
            throw new \ErrorException($msg, 0, $nivel, $archivo, $linea);
        });

        set_exception_handler(static function (\Throwable $e): void {
            self::log('ERROR ' . $e::class . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
            if (\PHP_SAPI === 'cli') {
                fwrite(\STDERR, 'Error: ' . $e->getMessage() . "\n");
                exit(1);
            }
            self::morir(
                'Algo falló',
                'No pudimos completar la operación. El detalle quedó registrado; probá de nuevo o avisá al soporte.'
            );
        });
    }

    public static function log(string $texto, string $archivo = 'app'): void
    {
        try {
            $ruta = self::storage('logs/' . $archivo . '-' . date('Y-m') . '.log');
            $linea = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), str_replace("\n", ' ', $texto));
            @file_put_contents($ruta, $linea, \FILE_APPEND | \LOCK_EX);
        } catch (\Throwable) {
            // Si no se puede escribir el log, no se interrumpe la operación.
        }
    }

    /** Página de error mínima, sin depender de la configuración ni de la base. */
    public static function morir(string $titulo, string $mensajeHtml, int $codigo = 500): never
    {
        if (\PHP_SAPI === 'cli') {
            fwrite(\STDERR, $titulo . ': ' . strip_tags($mensajeHtml) . "\n");
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code($codigo);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><html lang="es"><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::esc($titulo) . '</title>'
            . '<style>:root{color-scheme:dark}'
            . 'body{font:16px/1.5 system-ui,"Segoe UI",sans-serif;margin:0;background:#0f1620;color:#e6edf3}'
            . '.caja{max-width:560px;margin:12vh auto;background:#18212c;padding:32px;border-radius:12px;'
            . 'border:1px solid #27323f}h1{font-size:20px;margin:0 0 12px}a{color:#4aa3e8}'
            . 'code{background:#1f2a37;padding:2px 5px;border-radius:4px;font-size:14px}</style>'
            . '<div class="caja"><h1>' . self::esc($titulo) . '</h1><p>' . $mensajeHtml . '</p></div>';
        exit;
    }

    // ---------------------------------------------------------------- emisor

    /**
     * Emisor con el que trabaja la app. Si hay más de uno cargado, se elige en
     * Configuración y queda en la sesión; si hay uno solo, es ese.
     */
    public static function emisor(): ?array
    {
        if (!Db::tablaExiste('emisores')) {
            return null;
        }
        $id = $_SESSION['emisor_id'] ?? null;
        if (is_int($id)) {
            $emisor = Db::una('SELECT * FROM emisores WHERE id = ?', [$id]);
            if ($emisor !== null) {
                return $emisor;
            }
            unset($_SESSION['emisor_id']);
        }
        $emisor = Db::una('SELECT * FROM emisores ORDER BY id LIMIT 1');
        if ($emisor !== null && session_status() === \PHP_SESSION_ACTIVE) {
            $_SESSION['emisor_id'] = (int) $emisor['id'];
        }
        return $emisor;
    }

    /**
     * Aviso de certificado vencido o por vencer (30 días), o null si está bien.
     * @return array{texto:string,tipo:string}|null
     */
    public static function avisoCertificado(?array $emisor): ?array
    {
        if ($emisor === null) {
            return null;
        }
        $vence = (string) ($emisor['crt_vence'] ?? '');
        if ($vence === '') {
            return null;
        }
        $dias = (int) floor((strtotime($vence . ' 23:59:59') - time()) / 86400);
        if ($dias < 0) {
            return ['texto' => 'El certificado venció el ' . date('d/m/Y', (int) strtotime($vence)) . '. Hasta que lo renueves no se puede emitir.', 'tipo' => 'error'];
        }
        if ($dias <= 30) {
            return ['texto' => 'El certificado vence el ' . date('d/m/Y', (int) strtotime($vence)) . ' (en ' . $dias . ' día' . ($dias === 1 ? '' : 's') . ').', 'tipo' => 'atencion'];
        }
        return null;
    }

    // ----------------------------------------------------------------- útiles

    /** Valida un CUIT de 11 dígitos con dígito verificador (módulo 11). */
    public static function cuitValido(string $cuit): bool
    {
        $cuit = preg_replace('/\D/', '', $cuit) ?? '';
        if (strlen($cuit) !== 11) {
            return false;
        }
        $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        for ($i = 0; $i < 10; $i++) {
            $suma += (int) $cuit[$i] * $pesos[$i];
        }
        $resto = $suma % 11;
        $verificador = match ($resto) {
            0 => 0,
            1 => 9,
            default => 11 - $resto,
        };
        return $verificador === (int) $cuit[10];
    }
}
