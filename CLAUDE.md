# Facturador ARCA simple — especificación para Claude Code

Mini aplicación web en PHP que toma un Excel/CSV con facturas, valida los datos, pide el CAE a ARCA
(WSAA + WSFEv1) con el **certificado del propio cliente**, genera los PDF con QR y exporta un CSV con
número de comprobante, CAE y link al PDF.

El cliente tiene un sistema de gestión **sin** facturación electrónica. La experiencia tiene que ser
hipersencilla: subir archivo → ver errores → emitir → descargar CSV. El cliente nunca debe ver un
error técnico crudo: cada error se traduce a una frase que diga qué corregir.

---

## Stack (no cambiar sin consultar)

- PHP 8.1+ con extensiones `soap`, `openssl`, `pdo_sqlite`, `mbstring`, `zip`, `gd`.
- Composer: `phpoffice/phpspreadsheet`, `dompdf/dompdf`, `endroid/qr-code`.
- SQLite (`storage/facturador.sqlite`), esquema en `db/schema.sql`.
- Frontend: HTML + CSS propio + JS vanilla (fetch). Sin frameworks, sin build.
- Debe correr en **hosting compartido** (cPanel). Nada de colas, workers, cron obligatorio ni Docker.

## Estructura

```
facturador-arca/
├── CLAUDE.md                 ← este archivo
├── README.md                 ← instalación y onboarding del cliente (para humanos)
├── composer.json
├── config/
│   ├── config.example.php    ← copiar a config.php (NO versionar config.php)
├── db/schema.sql
├── public/                   ← ÚNICO directorio expuesto a la web (document root)
│   ├── index.php             ← historial de lotes + botón "Subir archivo"
│   ├── configuracion.php     ← datos del emisor, CSR, subir .crt, PV, ambiente
│   ├── subir.php             ← upload + vista previa con validación
│   ├── lote.php              ← progreso, continuar, reintentar inciertos, descargas
│   ├── api/procesar.php      ← AJAX: procesa UNA factura por llamada y devuelve JSON
│   ├── descargar.php         ← sirve PDF por token: descargar.php?t=xxxxx
│   ├── exportar.php          ← emitidas.csv | errores.csv | pdfs.zip
│   └── assets/ (app.css, app.js)
├── src/
│   ├── Db.php
│   ├── Arca/Wsaa.php         ← login CMS, cache de TA por CUIT+servicio+ambiente
│   ├── Arca/Wsfe.php         ← FEDummy, FECompUltimoAutorizado, FECAESolicitar, FECompConsultar, FEParamGet*
│   ├── Arca/Errores.php      ← mapeo código ARCA → mensaje claro + categoría (DATOS|TECNICO|AUTH|NUMERACION)
│   ├── Lote/Importador.php   ← XLSX/CSV → filas normalizadas
│   ├── Lote/Validador.php    ← reglas de integridad (ver abajo)
│   ├── Lote/Procesador.php   ← máquina de estados, una factura por paso
│   ├── Lote/Reconciliador.php← resuelve INCIERTO con FECompConsultar
│   ├── Pdf/GeneradorPdf.php
│   ├── Pdf/Qr.php
│   ├── Export/ExportadorCsv.php
│   └── Seguridad/{Cifrado.php, Certificados.php, Auth.php}
├── templates/factura.php     ← HTML del comprobante para dompdf
├── storage/                  ← FUERA de public/. Si el hosting no permite, .htaccess "Require all denied"
│   ├── certs/{cuit}/         ← clave cifrada, csr, crt
│   ├── ta/                   ← tickets WSAA cacheados (JSON)
│   ├── lotes/                ← archivos subidos
│   ├── pdf/{cuit}/{YYYY-MM}/
│   ├── locks/
│   └── logs/
└── plantilla/plantilla_facturas.xlsx
```

---

## ARCA: datos técnicos

> Verificar endpoints y campos contra el **Manual del Desarrollador WSFEv1 vigente (v4.8)** y el de WSAA
> antes de pasar a producción.

| | Homologación | Producción |
|---|---|---|
| WSAA | `https://wsaahomo.afip.gov.ar/ws/services/LoginCms` | `https://wsaa.afip.gov.ar/ws/services/LoginCms` |
| WSFEv1 | `https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL` | `https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL` |

### WSAA
- Armar TRA (XML con `uniqueId`, `generationTime` = ahora − 10 min, `expirationTime` = ahora + 10 min, `service`=`wsfe`).
- Firmar con `openssl_pkcs7_sign` (cert + clave descifrada en memoria, nunca escribir la clave en claro a disco;
  si `openssl_pkcs7_sign` exige archivos, usar `tempnam` en `storage/` y borrar en `finally`).
- Quitar encabezados MIME, enviar el CMS base64 a `loginCms`.
- Cachear `token`, `sign`, `expirationTime` en `storage/ta/{cuit}_{servicio}_{ambiente}.json`.
  **Reusar mientras falten > 10 min para vencer.** Pedirlo de nuevo con uno vigente → ARCA rechaza
  ("El CEE ya posee un TA valido"). En ese caso: si no hay cache, esperar y avisar; no reintentar en loop.

### WSFEv1 — campos clave de FECAESolicitar
- `CondicionIVAReceptorId` **siempre** (obligatorio con rechazo automático desde 01/12/2026).
- Concepto 2 o 3 → `FchServDesde`, `FchServHasta`, `FchVtoPago` obligatorios.
- IVA en `AlicIva`: Id 5 = 21 %, 4 = 10,5 %, 6 = 27 %, 3 = 0 %. No enviar `Iva` para Factura C.
- `ImpTotal = ImpTotConc + ImpNeto + ImpOpEx + ImpIVA + ImpTrib`. Redondeo a 2 decimales **antes** de sumar.
- `MonId` `PES` con `MonCotiz` 1; otra moneda requiere cotización.
- NC/ND: `CbtesAsoc` con tipo, PV, número (y CUIT emisor + fecha si corresponde según manual).
- Enviar **un comprobante por llamada** (CbteDesde = CbteHasta). Simple y suficiente.

### Códigos de error a mapear como mínimo (Arca/Errores.php)
Categoría `NUMERACION`: 10016 (número no es el siguiente). Categoría `AUTH`: 600/601/602 y errores de
WSAA (certificado vencido, no autorizado para el servicio, CUIT no relacionado). Categoría `DATOS`: todo
`Resultado = R` con `Errors`/`Observaciones` de validación. Categoría `TECNICO`: timeouts, SoapFault de
transporte, HTTP 5xx, respuestas vacías. Todo código no mapeado → mostrar código + mensaje original de
ARCA, y loguear para agregarlo al mapa.

---

## Máquina de estados (Lote/Procesador.php)

```
CARGADO → VALIDADO → ENVIANDO → AUTORIZADO → PDF_OK
    ↓                    ↓   ↘
ERROR_DATOS          RECHAZADO  INCIERTO → (reconciliación) → AUTORIZADO | VALIDADO | REVISION_MANUAL
```

**Un paso de `api/procesar.php`:**
1. Tomar `flock` exclusivo **no bloqueante** sobre `storage/locks/{cuit}_{pv}.lock`. Si está tomado → JSON `{ocupado:true}`.
2. Si el lote tiene filas `INCIERTO` → reconciliar primero (ver abajo). No se emite nada nuevo hasta resolverlas.
3. Si es el primer paso del lote → `FEDummy`. Si ARCA no está OK → pausar lote con motivo claro.
4. Tomar la siguiente fila `VALIDADO` (por `fila_excel`).
5. `FECompUltimoAutorizado(pv, tipo)` → `nro_esperado = ultimo + 1`.
6. **Persistir** `estado=ENVIANDO`, `nro_esperado`, `intentos+1` **y commit** antes de llamar a ARCA.
7. `FECAESolicitar`. Según resultado:
   - `A` → `AUTORIZADO`, guardar `nro`, `cae`, `vto_cae`, `observaciones_arca`. Generar PDF → `PDF_OK`.
     Si el PDF falla, queda `AUTORIZADO` (se regenera desde la UI). **Nunca** se revierte un CAE.
   - `R` categoría DATOS → `RECHAZADO` con mensaje claro. El número no se consumió. Seguir con la próxima.
   - `R` categoría NUMERACION → reconsultar último autorizado y reintentar **una** vez. Si vuelve a fallar
     → pausar lote: "Alguien emitió comprobantes en este punto de venta por fuera de la app".
   - AUTH → pausar lote con instrucción concreta al cliente. Volver la fila a `VALIDADO`.
   - TECNICO (timeout, red, 5xx, respuesta ilegible) → `INCIERTO` y **pausar lote**.
8. Registrar request/response en `eventos` (sin token/sign).
9. Liberar lock. Responder JSON con progreso: `{procesadas, total, autorizadas, rechazadas, pausado, motivo}`.

### Reconciliación (Lote/Reconciliador.php)
Para cada `INCIERTO` (y cada `ENVIANDO` con más de 2 minutos, que indica un proceso cortado):
1. `FECompConsultar(tipo, pv, nro_esperado)`.
2. Existe y coinciden `DocNro`, `ImpTotal` y `CbteFch` → `AUTORIZADO` con el CAE devuelto, y generar PDF.
3. No existe → volver a `VALIDADO` (seguro reintentar).
4. Existe pero **no coincide** → `REVISION_MANUAL` y pausar lote.
5. Si `FECompConsultar` también falla técnicamente → sigue `INCIERTO`, lote pausado, mensaje "ARCA no responde, probá en unos minutos".

### Reglas generales
- **Nunca** reintentar `FECAESolicitar` a ciegas después de un error técnico.
- **Nunca** asignar números localmente sin consultar `FECompUltimoAutorizado` en ese mismo paso.
- Timeout SOAP: `connection_timeout` 15 s, `default_socket_timeout` 45 s.
- El JS llama a `api/procesar.php` en loop hasta terminar o pausar. Si se cierra el navegador, al volver a
  `lote.php` aparece "Continuar".

---

## Validaciones (Lote/Validador.php)

Todas antes de enviar; un lote puede tener filas válidas e inválidas, y se emiten solo las válidas.

**Estructura:** encabezados exactos de la plantilla (tolerar mayúsculas/espacios/acentos); filas vacías ignoradas.

**Por fila:**
- `id_externo` obligatorio y único dentro del archivo; avisar si ya existe emitido en lotes anteriores (índice `ux_cbte_idext_vivo`).
- `punto_venta` = PV configurado del emisor.
- `tipo_cbte` coherente con el emisor: Monotributo/Exento → solo C (11, 12, 13). RI → A (1,2,3) o B (6,7,8).
- RI: receptor con `condicion_iva_receptor` = 1 (RI) o 6/13/16 (monotributo) → tipo A; resto → tipo B. Advertencia (no error) si no coincide, porque hay casos especiales.
- `doc_tipo` 80 → CUIT de 11 dígitos con **dígito verificador válido** (módulo 11). 96 → DNI 7-8 dígitos. 99 → `doc_nro` 0.
- Consumidor final sin identificar (99) por encima del monto que exige identificación → error. El monto va en `config.php` (`monto_identificacion_cf`) porque ARCA lo actualiza por resolución.
- `condicion_iva_receptor` en la lista de `FEParamGetCondicionIvaReceptor` (cachear la tabla por 24 h).
- `fecha`: concepto 1 → hasta 5 días antes/después de hoy; concepto 2/3 → hasta 10 días. No anterior al último comprobante emitido en ese PV/tipo (advertir).
- Concepto 2/3 → `serv_desde`, `serv_hasta`, `vto_pago` obligatorios, `serv_desde ≤ serv_hasta`.
- Importes ≥ 0, máximo 2 decimales. **Una alícuota por factura** (`neto_gravado` + `alicuota_iva`).
  Factura A/B → `alicuota_iva` obligatoria (21, 10.5, 27 o 0). Factura C → `alicuota_iva` vacía y `neto_gravado` = importe; se envía `ImpNeto` sin `Iva`.
- Calcular IVA = neto_gravado × alícuota redondeado a 2 decimales. **`total` informado vs. total calculado: diferencia > 0,01 → error** con ambos valores en el mensaje.
- `moneda` ≠ PES → `cotizacion` > 0 obligatoria.
- NC/ND → comprobante asociado completo y, si existe en la base, que esté autorizado.
- Se acepta fecha como fecha de Excel, `DD/MM/AAAA` o `AAAA-MM-DD`. Decimales con coma o punto.

Cada error se guarda como texto para el cliente: _"Fila 14: el CUIT 20-12345678-0 no es válido (dígito verificador)."_

---

## PDF (Pdf/GeneradorPdf.php + templates/factura.php)

- A4, original. Encabezado con letra (A/B/C) y código de comprobante, datos del emisor (logo, razón social,
  domicilio, condición IVA, CUIT, IIBB, inicio de actividades), PV-número `0005-00000123`, fecha.
- Receptor: razón social, documento, condición IVA, domicilio.
- Cuerpo: descripción, subtotales por alícuota (A), total. En B el IVA va incluido; en C sin IVA.
- Pie: CAE, vencimiento CAE, **QR**.
- **QR** según especificación de ARCA: URL `https://www.afip.gob.ar/fe/qr/?p={base64(json)}` con JSON
  `{"ver":1,"fecha":"AAAA-MM-DD","cuit":...,"ptoVta":...,"tipoCmp":...,"nroCmp":...,"importe":...,"moneda":"PES","ctz":1,"tipoDocRec":...,"nroDocRec":...,"tipoCodAut":"E","codAut":<CAE numérico>}`.
  Verificar la especificación vigente en arca.gob.ar/fe/qr antes de producción.
- Nombre: `{LETRA}-{PV4}-{NRO8}-{token}.pdf` en `storage/pdf/{cuit}/{YYYY-MM}/`. `pdf_token` = `bin2hex(random_bytes(16))`.

## Exportaciones (Export/ExportadorCsv.php)

- CSV con separador `;`, UTF-8 **con BOM** (para que Excel en español lo abra bien), decimales con coma.
- `emitidas.csv`: columnas de la plantilla + `estado;nro_comprobante;comprobante;cae;vto_cae;url_pdf;observaciones`.
- `errores.csv`: **mismas columnas que la plantilla** + `motivo` al final, para corregir y volver a subir tal cual.
- `pdfs.zip`: todos los PDF del lote.

## Seguridad

- Login simple (usuario + `password_hash`) en `config.php`. Sesión con `httponly`, `secure`, `samesite=Lax`.
- CSRF token en todos los formularios y en `api/procesar.php`.
- Clave privada: generada con `openssl_pkey_new` (RSA 2048), guardada cifrada con AES-256-GCM
  (`Seguridad/Cifrado.php`) usando `app_key` de `config.php`. Descifrar solo en memoria al firmar el TRA.
- CSR con `openssl_csr_new`: `C=AR`, `O={razón social}`, `CN={alias}`, `serialNumber=CUIT {cuit}`.
- Al subir el `.crt`: verificar que corresponde a la clave (`openssl_x509_check_private_key`), leer vencimiento, guardar `crt_vence`.
- Aviso visible si `crt_vence` < 30 días.
- `descargar.php` valida token, no expone rutas, envía `Content-Type: application/pdf`.
- Uploads: solo `.xlsx`/`.csv`, máx 5 MB, nombre aleatorio en disco.

---

## Orden de implementación (milestones)

1. **Base**: `Db.php`, aplicar `schema.sql`, `config.php`, login, layout.
2. **Certificados**: generar CSR, subir CRT, cifrado. Probar en homologación con certificado de testing.
3. **WSAA + WSFE**: `FEDummy`, `FECompUltimoAutorizado`, `FEParamGetCondicionIvaReceptor`, emitir 1 factura C y 1 factura B a mano desde un script de prueba (`tests/emitir_prueba.php`).
4. **Importador + Validador** con la plantilla y la vista previa (filas en verde/rojo).
5. **Procesador + Reconciliador** con el loop AJAX. Simular timeouts (flag en config `simular_timeout_cada_n`) para probar `INCIERTO`.
6. **PDF + QR** y `descargar.php`.
7. **Exportaciones** y pantalla de lote.
8. **Pruebas en homologación** con el set de casos de `tests/casos.md` (crear): CUIT inválido, total que no cierra, servicios sin fechas, timeout simulado, número desfasado (emitir uno por fuera), certificado vencido.
9. Pasaje a producción: cambiar ambiente del emisor, certificado de producción, PV real.

## Convenciones

- Código y comentarios en español. `declare(strict_types=1)`. PSR-4 con namespace `Facturador\`.
- Importes como `string` decimal o enteros en centavos internamente; nunca `float` para comparar totales.
- Fechas en la base `YYYY-MM-DD`; a ARCA `YYYYMMDD`.
- Ninguna excepción llega cruda al usuario: capturar, registrar en `eventos` / `storage/logs`, mostrar mensaje claro.
