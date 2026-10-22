-- Facturador ARCA por lotes — esquema SQLite
-- Ejecutar con: sqlite3 storage/facturador.sqlite < db/schema.sql
PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;

-- ---------------------------------------------------------------
-- Emisores: el CUIT del cliente y su certificado.
-- Una instalación puede tener 1 o más emisores.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS emisores (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    cuit                TEXT    NOT NULL UNIQUE,          -- 11 dígitos sin guiones
    razon_social        TEXT    NOT NULL,
    domicilio           TEXT    NOT NULL,
    condicion_iva       TEXT    NOT NULL,                 -- 'RI' | 'MONO' | 'EXENTO'
    iibb                TEXT,
    inicio_actividades  TEXT,                             -- YYYY-MM-DD
    logo_path           TEXT,
    punto_venta         INTEGER NOT NULL,                 -- PV tipo "Web Services" EXCLUSIVO de la app
    ambiente            TEXT    NOT NULL DEFAULT 'homo',  -- 'homo' | 'prod'
    -- Certificado (archivos en storage/certs/{cuit}/, clave privada cifrada)
    key_path            TEXT,                             -- clave privada cifrada (AES-256-GCM)
    csr_path            TEXT,
    crt_path            TEXT,
    crt_vence           TEXT,                             -- YYYY-MM-DD, para avisar 30 días antes
    creado              TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

-- ---------------------------------------------------------------
-- Lotes: cada archivo subido.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lotes (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    emisor_id           INTEGER NOT NULL REFERENCES emisores(id),
    archivo_original    TEXT    NOT NULL,                 -- nombre del archivo subido
    archivo_path        TEXT    NOT NULL,                 -- copia guardada en storage/lotes/
    archivo_sha256      TEXT    NOT NULL,                 -- para advertir si se sube dos veces el mismo
    estado              TEXT    NOT NULL DEFAULT 'CARGADO',
        -- CARGADO | VALIDADO | PROCESANDO | PAUSADO | FINALIZADO | FINALIZADO_CON_ERRORES
    motivo_pausa        TEXT,                             -- por qué se frenó (timeout, certificado, desfase...)
    total_filas         INTEGER NOT NULL DEFAULT 0,
    creado              TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    actualizado         TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);

-- ---------------------------------------------------------------
-- Comprobantes: una fila por factura del archivo.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS comprobantes (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    lote_id             INTEGER NOT NULL REFERENCES lotes(id),
    emisor_id           INTEGER NOT NULL REFERENCES emisores(id),
    fila_excel          INTEGER NOT NULL,                 -- para reportar "fila 14: ..."
    id_externo          TEXT    NOT NULL,                 -- referencia del sistema de gestión del cliente

    -- Datos de entrada (tal cual la plantilla)
    punto_venta         INTEGER NOT NULL,
    tipo_cbte           INTEGER NOT NULL,                 -- 1 FA, 6 FB, 11 FC, 3/8/13 NC...
    concepto            INTEGER NOT NULL,                 -- 1 productos, 2 servicios, 3 ambos
    fecha               TEXT    NOT NULL,                 -- YYYY-MM-DD
    doc_tipo            INTEGER NOT NULL,                 -- 80 CUIT, 96 DNI, 99 CF sin identificar
    doc_nro             TEXT    NOT NULL,
    condicion_iva_receptor INTEGER NOT NULL,
    razon_social        TEXT,
    domicilio           TEXT,
    email               TEXT,
    descripcion         TEXT    NOT NULL,
    -- Importes como TEXT decimal ("12345.67") para no comparar floats
    neto_gravado        TEXT    NOT NULL DEFAULT '0',     -- en Factura C: el importe total
    alicuota_iva        TEXT,                             -- '21' | '10.5' | '27' | '0'; vacío en Factura C
    no_gravado          TEXT    NOT NULL DEFAULT '0',
    exento              TEXT    NOT NULL DEFAULT '0',
    total               TEXT    NOT NULL,
    moneda              TEXT    NOT NULL DEFAULT 'PES',
    cotizacion          TEXT    NOT NULL DEFAULT '1',
    serv_desde          TEXT,
    serv_hasta          TEXT,
    vto_pago            TEXT,
    asoc_tipo           INTEGER,                          -- solo NC/ND
    asoc_pto_vta        INTEGER,
    asoc_nro            INTEGER,

    -- Calculados por la app
    iva                 TEXT    NOT NULL DEFAULT '0',

    -- Ciclo de vida
    estado              TEXT    NOT NULL DEFAULT 'CARGADO',
        -- CARGADO | VALIDADO | ERROR_DATOS | ENVIANDO | AUTORIZADO | RECHAZADO | INCIERTO | REVISION_MANUAL | PDF_OK
    nro_esperado        INTEGER,                          -- se graba ANTES de llamar a ARCA
    nro                 INTEGER,                          -- número confirmado por ARCA
    cae                 TEXT,
    vto_cae             TEXT,
    observaciones_arca  TEXT,                             -- JSON con Observaciones (resultado A con obs)
    error_cod           TEXT,
    error_msg           TEXT,                             -- mensaje en lenguaje claro para el cliente
    intentos            INTEGER NOT NULL DEFAULT 0,
    pdf_path            TEXT,
    pdf_token           TEXT    UNIQUE,                   -- token aleatorio para descargar.php?t=
    actualizado         TEXT    NOT NULL DEFAULT (datetime('now','localtime')),

    UNIQUE (lote_id, fila_excel)
);

-- Evita facturar dos veces la misma operación del sistema de gestión,
-- aunque el cliente suba el mismo archivo de nuevo.
CREATE UNIQUE INDEX IF NOT EXISTS ux_cbte_idext_vivo
    ON comprobantes (emisor_id, id_externo)
    WHERE estado IN ('ENVIANDO','AUTORIZADO','INCIERTO','REVISION_MANUAL','PDF_OK');

-- Un número de comprobante emitido es único por emisor + PV + tipo
CREATE UNIQUE INDEX IF NOT EXISTS ux_cbte_numero
    ON comprobantes (emisor_id, punto_venta, tipo_cbte, nro)
    WHERE nro IS NOT NULL;

CREATE INDEX IF NOT EXISTS ix_cbte_lote_estado ON comprobantes (lote_id, estado);

-- ---------------------------------------------------------------
-- Eventos: bitácora de cada request/response con ARCA.
-- Es lo primero que se mira cuando algo falla.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS eventos (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    comprobante_id      INTEGER REFERENCES comprobantes(id),
    lote_id             INTEGER REFERENCES lotes(id),
    emisor_id           INTEGER REFERENCES emisores(id),
    tipo                TEXT    NOT NULL,                 -- WSAA_LOGIN | FEDUMMY | ULTIMO_AUTORIZADO | SOLICITAR | CONSULTAR | PDF | ERROR
    request_xml         TEXT,                             -- sin Token/Sign
    response_xml        TEXT,
    ok                  INTEGER NOT NULL,                 -- 1 / 0
    detalle             TEXT,
    duracion_ms         INTEGER,
    creado              TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS ix_eventos_cbte ON eventos (comprobante_id);
