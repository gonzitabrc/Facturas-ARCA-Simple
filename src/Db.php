<?php
declare(strict_types=1);

namespace Facturador;

/**
 * Conexión SQLite y helpers de consulta.
 * La base se crea sola a partir de db/schema.sql la primera vez que se usa.
 */
final class Db
{
    private static ?\PDO $pdo = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }

        $ruta = (string) App::config('rutas.db', App::raiz() . '/storage/facturador.sqlite');
        $dir = dirname($ruta);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("No se pudo crear el directorio de la base: {$dir}");
        }

        $pdo = new \PDO('sqlite:' . $ruta, null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 10000');
        self::modoWal($pdo);

        self::$pdo = $pdo;

        if (!self::tablaExiste('emisores')) {
            self::aplicarEsquema();
        }
        return self::$pdo;
    }

    /**
     * WAL aguanta mejor el loop AJAX, pero algunos hostings compartidos
     * (filesystems de red) no lo soportan: en ese caso se sigue con el modo por defecto.
     */
    private static function modoWal(\PDO $pdo): void
    {
        try {
            $modo = (string) $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn();
            if (strtolower($modo) !== 'wal') {
                App::log("Aviso: no se pudo activar WAL en SQLite (journal_mode={$modo}).");
            }
        } catch (\Throwable $e) {
            App::log('Aviso: PRAGMA journal_mode falló: ' . $e->getMessage());
        }
    }

    public static function tablaExiste(string $tabla): bool
    {
        // Se llama desde pdo() mientras se abre la conexión: usar self::$pdo si ya está.
        $pdo = self::$pdo ?? self::pdo();
        $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type IN ('table','view') AND name = ?");
        $st->execute([$tabla]);
        return $st->fetchColumn() !== false;
    }

    /** Aplica db/schema.sql. Es idempotente: todo el esquema usa IF NOT EXISTS. */
    public static function aplicarEsquema(): void
    {
        $archivo = App::raiz() . '/db/schema.sql';
        if (!is_file($archivo)) {
            throw new \RuntimeException('No se encontró db/schema.sql');
        }
        $sql = (string) file_get_contents($archivo);
        $parciales = self::soportaIndicesParciales();
        $pendientes = [];

        foreach (self::dividirSentencias($sql) as $sentencia) {
            // Hostings con SQLite viejo (CloudLinux 7 trae 3.7.17) no entienden
            // los índices parciales: se reemplazan por algo equivalente más abajo.
            if (!$parciales && preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX/i', $sentencia) === 1 && preg_match('/\sWHERE\s/i', $sentencia) === 1) {
                $pendientes[] = $sentencia;
                continue;
            }
            // query() y no exec(): los PRAGMA devuelven filas.
            $st = self::pdo()->query($sentencia);
            if ($st instanceof \PDOStatement) {
                $st->closeCursor();
            }
        }

        if ($pendientes !== []) {
            foreach (self::equivalentesSinIndicesParciales() as $sentencia) {
                $st = self::pdo()->query($sentencia);
                if ($st instanceof \PDOStatement) {
                    $st->closeCursor();
                }
            }
            App::log('SQLite sin índices parciales (' . self::versionSqlite() . '): se usaron triggers equivalentes.');
        }

        App::log('Base creada/actualizada desde db/schema.sql');
    }

    public static function versionSqlite(): string
    {
        return (string) self::pdo()->query('SELECT sqlite_version()')->fetchColumn();
    }

    /** Los índices parciales (CREATE INDEX ... WHERE) existen desde SQLite 3.8.0. */
    public static function soportaIndicesParciales(): bool
    {
        return version_compare(self::versionSqlite(), '3.8.0', '>=');
    }

    /**
     * Lo mismo que los dos índices parciales de schema.sql, para SQLite viejo:
     *
     * - ux_cbte_numero: en SQLite los NULL son distintos entre sí, así que un índice
     *   único normal ya equivale al parcial "WHERE nro IS NOT NULL".
     * - ux_cbte_idext_vivo: no se puede hacer con un índice, así que se hace con
     *   triggers que abortan si la referencia ya está facturada o en proceso.
     *
     * @return list<string>
     */
    private static function equivalentesSinIndicesParciales(): array
    {
        $vivos = "('ENVIANDO','AUTORIZADO','INCIERTO','REVISION_MANUAL','PDF_OK')";
        $mensaje = 'Esa referencia (id_externo) ya está facturada o en proceso para este emisor.';

        return [
            'CREATE UNIQUE INDEX IF NOT EXISTS ux_cbte_numero
               ON comprobantes (emisor_id, punto_venta, tipo_cbte, nro)',

            "CREATE TRIGGER IF NOT EXISTS tr_cbte_idext_vivo_insert
               BEFORE INSERT ON comprobantes
               WHEN NEW.estado IN {$vivos}
                AND EXISTS (SELECT 1 FROM comprobantes
                             WHERE emisor_id = NEW.emisor_id
                               AND id_externo = NEW.id_externo
                               AND estado IN {$vivos})
             BEGIN
               SELECT RAISE(ABORT, '{$mensaje}');
             END",

            "CREATE TRIGGER IF NOT EXISTS tr_cbte_idext_vivo_update
               BEFORE UPDATE OF estado, id_externo ON comprobantes
               WHEN NEW.estado IN {$vivos}
                AND EXISTS (SELECT 1 FROM comprobantes
                             WHERE emisor_id = NEW.emisor_id
                               AND id_externo = NEW.id_externo
                               AND id <> NEW.id
                               AND estado IN {$vivos})
             BEGIN
               SELECT RAISE(ABORT, '{$mensaje}');
             END",
        ];
    }

    /**
     * Parte un script SQL en sentencias, ignorando los ";" que aparecen dentro
     * de comentarios (-- ...) o de literales de texto ('...').
     *
     * @return list<string>
     */
    private static function dividirSentencias(string $sql): array
    {
        $sentencias = [];
        $actual = '';
        $enTexto = false;
        $largo = strlen($sql);

        for ($i = 0; $i < $largo; $i++) {
            $c = $sql[$i];

            if ($enTexto) {
                $actual .= $c;
                if ($c === "'") {
                    if (($sql[$i + 1] ?? '') === "'") {   // '' = apóstrofo escapado
                        $actual .= "'";
                        $i++;
                    } else {
                        $enTexto = false;
                    }
                }
                continue;
            }

            if ($c === "'") {
                $enTexto = true;
                $actual .= $c;
                continue;
            }

            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                while ($i < $largo && $sql[$i] !== "\n") {
                    $i++;
                }
                $actual .= "\n";
                continue;
            }

            if ($c === ';') {
                $sentencias[] = $actual;
                $actual = '';
                continue;
            }

            $actual .= $c;
        }
        $sentencias[] = $actual;

        return array_values(array_filter(
            array_map('trim', $sentencias),
            static fn (string $s): bool => $s !== ''
        ));
    }

    // ------------------------------------------------------------- consultas

    public static function ejecutar(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute(self::normalizar($params));
        return $st;
    }

    public static function todas(string $sql, array $params = []): array
    {
        return self::ejecutar($sql, $params)->fetchAll();
    }

    public static function una(string $sql, array $params = []): ?array
    {
        $fila = self::ejecutar($sql, $params)->fetch();
        return $fila === false ? null : $fila;
    }

    public static function valor(string $sql, array $params = []): mixed
    {
        $valor = self::ejecutar($sql, $params)->fetchColumn();
        return $valor === false ? null : $valor;
    }

    public static function insertar(string $tabla, array $datos): int
    {
        $columnas = array_keys($datos);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $tabla,
            implode(', ', $columnas),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columnas))
        );
        self::ejecutar($sql, $datos);
        return (int) self::pdo()->lastInsertId();
    }

    /** Actualiza por id y refresca la columna "actualizado" si la tabla la tiene. */
    public static function actualizar(string $tabla, int $id, array $datos): int
    {
        if ($datos === []) {
            return 0;
        }
        $asignaciones = [];
        foreach (array_keys($datos) as $columna) {
            $asignaciones[] = "{$columna} = :{$columna}";
        }
        if (self::columnaExiste($tabla, 'actualizado') && !array_key_exists('actualizado', $datos)) {
            $asignaciones[] = "actualizado = datetime('now','localtime')";
        }
        $datos['__id'] = $id;
        $sql = sprintf('UPDATE %s SET %s WHERE id = :__id', $tabla, implode(', ', $asignaciones));
        return self::ejecutar($sql, $datos)->rowCount();
    }

    public static function columnaExiste(string $tabla, string $columna): bool
    {
        static $cache = [];
        if (!isset($cache[$tabla])) {
            $cache[$tabla] = array_column(self::todas("PRAGMA table_info({$tabla})"), 'name');
        }
        return in_array($columna, $cache[$tabla], true);
    }

    /** Transacción. Devuelve lo que devuelva el callback; hace rollback ante cualquier excepción. */
    public static function enTransaccion(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn($pdo);
        }
        $pdo->beginTransaction();
        try {
            $resultado = $fn($pdo);
            $pdo->commit();
            return $resultado;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Los bool de PHP no entran lindo en SQLite: se guardan como 1/0. */
    private static function normalizar(array $params): array
    {
        foreach ($params as $clave => $valor) {
            if (is_bool($valor)) {
                $params[$clave] = $valor ? 1 : 0;
            }
        }
        return $params;
    }
}
