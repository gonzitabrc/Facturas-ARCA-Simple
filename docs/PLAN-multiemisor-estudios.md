# Plan: facturar para varios CUIT con un solo certificado

**Estado: propuesta. Nada de esto está implementado todavía.**
La aplicación hoy trabaja con un emisor y su propio certificado. Este documento describe el
upgrade para que un estudio contable (o cualquier representante) emita comprobantes de varios
contribuyentes con **un único certificado**, el suyo.

Fecha: 22/10/2026 · Verificado contra el Manual del Desarrollador WSFEv1 v4.8.

---

## 1. Qué permite ARCA

El mecanismo existe y es oficial: se llama **CUIT representada**.

Cada llamada a WSFEv1 lleva un bloque `Auth` con tres campos:

```xml
<ar:Auth>
    <ar:Token>…</ar:Token>   <!-- del ticket de acceso, obtenido con UN certificado -->
    <ar:Sign>…</ar:Sign>
    <ar:Cuit>…</ar:Cuit>     <!-- "Cuit contribuyente (representado o Emisora)" -->
</ar:Auth>
```

El manual define `Cuit` textualmente como *"Cuit contribuyente (representado o Emisora)"*, y en
`FEParamGetCondicionIvaReceptor` aclara: *"Recibe las credenciales de autenticación y la cuit del
usuario representado"*. Es decir: **el certificado y el CUIT que factura pueden ser distintos**.

### Lo que tiene que hacer cada cliente, una vez

Con su clave fiscal, el contribuyente entra a **Administrador de Relaciones** y delega el servicio
**Facturación Electrónica** al *computador fiscal* (el alias del certificado) del estudio. Es el
mismo trámite que hoy hace cada emisor para su propio certificado, pero apuntando al del estudio.

Si falta ese paso, ARCA responde con error de autorización: en el manual aparece
*"CUIT representada no incluida en token"*.

### Lo que sigue siendo de cada cliente

- **El punto de venta.** Cada CUIT necesita el suyo, tipo "Web Services", y exclusivo de la app.
- **La numeración**, que es por CUIT + punto de venta + tipo de comprobante.
- **La responsabilidad fiscal** de lo que se emite.

---

## 2. Qué hace la app hoy y qué le falta

| | Hoy | Hace falta |
|---|---|---|
| Tabla `emisores` | varios CUIT posibles, cada uno con `key_path`/`crt_path` propios | que varios emisores compartan un certificado |
| Ticket de acceso | se cachea en `storage/ta/{cuit_emisor}_{servicio}_{ambiente}.json` | cachearlo por **titular del certificado**, no por emisor |
| `Auth.Cuit` | ya manda el CUIT del emisor (`Wsfe::llamar()`) | sin cambios: ya es el CUIT representado |
| Firma del TRA | `Wsaa::ta($emisor)` usa el certificado **del emisor** | usar el certificado del titular |
| Pantallas | asumen un emisor (`App::emisor()` en 7 archivos de `public/`) | listado de clientes, selector y pantalla de certificados |
| Lock de emisión | `storage/locks/{cuit}_{pv}.lock` | sin cambios: ya aísla por emisor |
| Validaciones | ya filtran por `emisor_id` | sin cambios |

**El problema que hay que resolver sí o sí:** ARCA entrega **un solo ticket de acceso por
certificado y servicio**, y dura 12 horas. Con el cache actual, el segundo cliente pediría un
ticket nuevo con el mismo certificado y ARCA respondería *"El CEE ya posee un TA valido"*. Con 10
clientes, 9 no podrían facturar.

---

## 3. Modelo de datos

Se agrega una tabla `certificados` y los emisores pasan a apuntar a ella. Hoy el certificado vive
como tres columnas dentro de `emisores`, lo que impide compartirlo; además el titular del
certificado (el estudio) puede no ser un emisor: tal vez no factura con este sistema.

```sql
-- Certificados de ARCA. Uno puede servir a varios emisores (estudio contable),
-- o pertenecer al mismo contribuyente que factura (caso de un solo emisor).
CREATE TABLE IF NOT EXISTS certificados (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    cuit_titular  TEXT    NOT NULL,            -- CUIT dueño del certificado
    nombre        TEXT    NOT NULL,            -- "Estudio Pérez", para las pantallas
    ambiente      TEXT    NOT NULL,            -- 'homo' | 'prod'
    alias         TEXT,                        -- CN del .crt, lo lee la app
    key_path      TEXT,                        -- clave privada cifrada (AES-256-GCM)
    csr_path      TEXT,
    crt_path      TEXT,
    crt_vence     TEXT,                        -- YYYY-MM-DD
    creado        TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (cuit_titular, ambiente)
);

-- Cada emisor usa un certificado (el propio o el del representante).
ALTER TABLE emisores ADD COLUMN certificado_id INTEGER REFERENCES certificados(id);

-- Para dar de baja un cliente sin perder su historial.
ALTER TABLE emisores ADD COLUMN activo INTEGER NOT NULL DEFAULT 1;
```

Las columnas `emisores.key_path`, `csr_path`, `crt_path` y `crt_vence` quedan **en desuso** después
de la migración; no se borran en esta etapa (SQLite 3.7 del hosting no soporta `DROP COLUMN`), pero
ningún código las lee.

### Dónde quedan los archivos

Sin cambios: `storage/certs/{cuit}/{ambiente}/`, donde `{cuit}` pasa a ser el del **titular**. Para
las instalaciones que ya existen, titular y emisor son el mismo CUIT, así que **los archivos no se
mueven** y la clave sigue descifrándose: el contexto AES-GCM (`cuit=…;ambiente=…`) no cambia.

### Migración (automática, en `Db::aplicarEsquema()`)

1. Si la tabla `certificados` no existe, se crea y se agregan las dos columnas a `emisores`.
2. Por cada emisor con `crt_path` no nulo: se inserta un `certificados` con
   `cuit_titular = emisores.cuit`, `nombre = emisores.razon_social`, y los cuatro paths/fechas.
3. Se setea `emisores.certificado_id` apuntando a esa fila.
4. Los emisores sin certificado quedan con `certificado_id` en NULL.

Una instalación de un solo emisor queda funcionando **exactamente igual**, sin intervención.

---

## 4. Cambios en el código

### `src/Seguridad/Certificados.php`

Todas las funciones pasan a trabajar sobre un **certificado**, no sobre un emisor:

| Hoy | Después |
|---|---|
| `generarPedido(array $emisor, string $alias)` | `generarPedido(array $certificado, string $alias)` |
| `guardarCertificado(array $emisor, string $crtPem)` | `guardarCertificado(array $certificado, string $crtPem)` |
| `importarPar(array $emisor, …)` | `importarPar(array $certificado, …)` |
| `estado(array $emisor)` | `estado(array $certificado)` |
| `clavePrivada(array $emisor)` | `clavePrivada(array $certificado)` |
| `certificadoPem(array $emisor)` | `certificadoPem(array $certificado)` |
| `dir(array $emisor)` | `dir(array $certificado)` — usa `cuit_titular` |
| `quitar(array $emisor)` | `quitar(array $certificado)` — avisa a cuántos emisores deja sin certificado |

Se agrega:

```php
/** El certificado con el que factura este emisor, o null si no tiene. */
public static function delEmisor(array $emisor): ?array;

/** Emisores que dependen de este certificado (para avisos y para no borrarlo a ciegas). */
public static function emisoresQueLoUsan(int $certificadoId): array;
```

`Cifrado::contexto()` pasa a recibir el certificado (`cuit_titular` + `ambiente`) en lugar del
emisor. Es el mismo string para los casos ya existentes, así que no invalida nada guardado.

### `src/Arca/Wsaa.php`

```php
// Hoy
public static function ta(array $emisor, string $servicio = 'wsfe'): array
private static function rutaCache(array $emisor, string $servicio): string
    => "ta/{$emisor['cuit']}_{$servicio}_{$emisor['ambiente']}.json"

// Después
public static function ta(array $certificado, string $servicio = 'wsfe'): array
private static function rutaCache(array $certificado, string $servicio): string
    => "ta/{$certificado['cuit_titular']}_{$servicio}_{$certificado['ambiente']}.json"
```

El lock pasa a `storage/locks/wsaa_{cuit_titular}_{ambiente}.lock`. Resultado: **un solo ticket para
todos los emisores que comparten el certificado**, pedido una vez cada 12 horas.

### `src/Arca/Wsfe.php`

```php
public function __construct(private array $emisor, ?array $certificado = null)
{
    $this->certificado = $certificado ?? Certificados::delEmisor($emisor)
        ?? throw new ArcaExcepcion(Errores::AUTH, 'Este cliente todavía no tiene un certificado asignado.');
}
```

- `llamar()` pide el ticket con `Wsaa::ta($this->certificado)`.
- `Auth.Cuit` sigue siendo `$this->emisor['cuit']`: **el CUIT representado**. No cambia.
- El resto de los métodos no se tocan.

### `src/Arca/Errores.php`

Mensajes nuevos para el caso de representación, que hoy saldrían como texto crudo de ARCA:

| Código / texto de ARCA | Mensaje para el cliente |
|---|---|
| `600`, `601`, `602` con "no autorizado" | "El certificado del estudio no está autorizado para facturar por este CUIT. El contribuyente tiene que delegar el servicio de Facturación Electrónica en Administrador de Relaciones." |
| "CUIT representada no incluida en token" | igual que el anterior, con el CUIT del emisor en el mensaje |

### `src/App.php`

```php
public static function emisor(): ?array        // ya existe: emisor activo de la sesión
public static function emisores(bool $soloActivos = true): array   // nuevo: para el selector
public static function cambiarEmisor(int $id): void                // nuevo: valida y guarda en sesión
public static function certificadoActivo(): ?array                 // nuevo: el del emisor activo
```

`avisoCertificado()` pasa a mirar el certificado del emisor activo, y suma un aviso global: *"el
certificado del estudio vence en N días y afecta a N clientes"*.

### Lo que no se toca

`Lote/Importador.php`, `Lote/Validador.php`, `Lote/Procesador.php`, `Lote/Reconciliador.php`,
`Pdf/*`, `Export/*` y el esquema de `lotes`, `comprobantes` y `eventos`: ya trabajan por
`emisor_id`. El lock de emisión ya es por CUIT y punto de venta, así que dos clientes pueden emitir
en paralelo sin pisarse.

---

## 5. Pantallas

### Nueva: `public/clientes.php` — los contribuyentes del estudio

Listado con alta, edición y baja lógica:

| Cliente | CUIT | Condición | PV | Certificado | Último comprobante | Lotes |
|---|---|---|---|---|---|---|
| Hotel Las Cumbres SRL | 30-71234567-1 | RI | 0003 | Estudio Pérez ✔ | B 0003-00000412 | 14 |
| Cabañas del Bosque | 30-71456789-2 | Monotributo | 0002 | Estudio Pérez ✔ | C 0002-00000087 | 9 |
| Excursiones Nahuel | 30-71111111-1 | Monotributo | 0005 | **sin asignar** | — | 0 |

El alta de un cliente es el formulario de emisor que ya existe (razón social, domicilio, condición
de IVA, punto de venta, ambiente) más un desplegable de certificado.

### Nueva: `public/certificados.php` — los certificados de la instalación

Lo que hoy está en *Configuración*, pero por certificado y con la lista de clientes que dependen de
cada uno: generar pedido (CSR), subir o actualizar, quitar, probar conexión y el aviso de
vencimiento. Antes de quitar uno, avisa a cuántos clientes deja sin poder facturar.

### Cambia: la barra superior

Selector del cliente activo, visible en todas las pantallas:

```
Facturador ARCA simple   [ Hotel Las Cumbres SRL ▾ ]   Lotes  Clientes  Certificados  Ayuda
```

Guarda la elección en `$_SESSION['emisor_id']` (ya existe) y todo lo demás sigue filtrando por ahí.

### Cambian poco

- `index.php` (Lotes): filtra por el cliente activo, con una opción "todos los clientes" que suma
  una columna *Cliente*.
- `subir.php`: muestra de quién es la planilla que se está subiendo y no deja subir si el cliente
  no tiene certificado o punto de venta.
- `lote.php`: cabecera con el cliente del lote.
- `configuracion.php`: queda para los datos de la instalación; lo del emisor se va a `clientes.php`
  y lo del certificado a `certificados.php`.
- `ayuda.php`: sección nueva **"Para estudios contables"** con el trámite de delegación explicado
  igual que los demás pasos.

---

## 6. Casos borde y riesgos

| Situación | Qué pasa | Qué hacemos |
|---|---|---|
| El certificado del estudio vence | **ningún** cliente puede facturar | aviso a 60 y 30 días en todas las pantallas, con la cantidad de clientes afectados |
| Un cliente revoca la delegación | ARCA rechaza solo ese CUIT | mensaje claro con el trámite exacto a rehacer |
| Dos clientes emiten a la vez | locks distintos, mismo ticket cacheado | sin cambios; ya funciona |
| Otro sistema usa el mismo certificado (el CRM del estudio) | se pelean el ticket de acceso | documentado: un alias por sistema |
| Se cambia el certificado del estudio | hay que olvidar el ticket cacheado | `Wsaa::olvidarCache()` ya se llama al guardar un certificado |
| Un cliente con su propio certificado | convive sin problema | es el mismo modelo: un certificado con un solo emisor |
| Se borra un certificado en uso | varios clientes quedan sin poder facturar | confirmación que lista los clientes afectados |
| Límite de CUIT representados | ARCA no documenta un máximo | no se asume ninguno; si aparece, se maneja como error de ARCA |

**Seguridad:** con un certificado que factura para muchos, el archivo cifrado pasa a ser mucho más
sensible. No cambia el mecanismo (AES-256-GCM con la `app_key`), pero sí la recomendación: backup de
la `app_key` aparte del backup de `storage/`, y login con clave fuerte, sin excepciones.

---

## 7. Pruebas que se agregan

Sobre el ARCA simulado que ya existe en `tests/casos/04_…`:

1. Dos emisores con el mismo certificado emiten y **se pide un solo ticket de acceso** (se cuentan
   las llamadas a WSAA).
2. El `Auth.Cuit` que viaja es el del emisor representado, y la firma es del titular.
3. Un emisor sin certificado asignado da un mensaje claro y no llega a llamar a ARCA.
4. La numeración y el `id_externo` siguen aislados por emisor (dos clientes pueden tener la misma
   referencia sin pisarse).
5. Migración: una base con el esquema viejo (emisor con certificado propio) queda con su
   `certificados` y su `certificado_id`, y sigue facturando igual.
6. Quitar un certificado usado por dos emisores los deja a los dos sin certificado y lo avisa.

---

## 8. Orden de implementación

| Etapa | Qué incluye | Estimación |
|---|---|---|
| 1 | Esquema, migración automática y `Certificados` trabajando por certificado | 2–3 h |
| 2 | `Wsaa` y `Wsfe` con representación; ticket compartido; mensajes de error nuevos | 2 h |
| 3 | Pantallas: clientes, certificados y selector en la barra | 3–4 h |
| 4 | Ayuda para estudios, pruebas nuevas y documentación | 2 h |
| | **Total** | **9–11 h** |

Cada etapa deja la app funcionando: la 1 y la 2 no cambian nada visible para una instalación de un
solo emisor, y la 3 agrega las pantallas nuevas sin romper las que hay.

## 9. Lo que no entra en este upgrade

- **Una planilla con varios CUIT.** Se sube una por cliente. Partir un archivo en varios lotes con
  una columna `cuit_emisor` queda para más adelante; agrega ambigüedad en la vista previa y en los
  errores, y no hace falta para el caso de uso.
- **Usuarios con permisos por cliente.** Hoy el login es de la instalación: quien entra ve todos los
  clientes. Separar usuarios por cliente es otro upgrade.
- **Facturación automática por API.** La app sigue siendo "subís un archivo y emitís".
- **Multi-estudio en una sola instalación.** Un estudio, una instalación.
