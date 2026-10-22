# Casos de prueba

Dos partes: lo que se prueba **sin tocar ARCA** (scripts que corren solos) y lo que hay que
probar **a mano con el certificado real**, porque emite comprobantes de verdad.

---

## 1. Automático, sin ARCA

Los scripts de `tests/` no emiten nada y no necesitan certificado válido. Corren con un emisor
de prueba (CUIT `20123456786`, prefijo `ZZTEST`) que borran al terminar.

```bash
php tests/verificar.php
```

Cubre, en orden:

**Cifrado y certificados**
- Cifrado AES-256-GCM ida y vuelta; rechaza otro contexto (CUIT/ambiente) y un sobre alterado.
- Alias inválido (con espacios) se rechaza.
- Genera clave RSA 2048 + CSR con el DN que pide ARCA (`C`, `O`, `CN`, `serialNumber=CUIT`).
- La clave privada en disco está cifrada (empieza con `FAC1.` y no contiene `PRIVATE KEY`).
- Acepta el `.crt` que corresponde; rechaza uno de otra clave, de otro CUIT, vencido, o un
  archivo que no es certificado.
- Al renovar, el par en uso no se toca hasta que se sube el `.crt` nuevo, y queda copia del anterior.
- Importar un par `.crt` + `.key` que ya existe (el que el emisor usa en otro sistema).

**WSAA**
- El TRA tiene `uniqueId`, `generationTime` y `expirationTime` (ahora ±10 minutos) y `service`.
- La firma CMS es base64 sin encabezados MIME, lleva adentro el TRA exacto y **verifica con OpenSSL**.
- No quedan archivos temporales con la clave en `storage/tmp`.
- El ticket de acceso cacheado se reusa si le faltan más de 10 minutos; se olvida al cambiar el certificado.

**WSFE (armado, sin llamar)**
- Factura C: `ImpNeto` = total, sin `Iva`, `ImpIVA` = 0.
- Factura B: `AlicIva` con Id 5 para 21 %, y `ImpTotal = ImpTotConc + ImpNeto + ImpOpEx + ImpIVA + ImpTrib`.
- Concepto 2 o 3: agrega `FchServDesde`, `FchServHasta`, `FchVtoPago`.
- Nota de crédito: agrega `CbtesAsoc`.
- Alícuota que no existe en ARCA se rechaza antes de llamar.
- Clasificación de errores: 10016 → NUMERACION, 600 → AUTH, 10048 → DATOS, SoapFault → TECNICO.
- La bitácora nunca guarda `Token` ni `Sign`.

**Importador y validador**
- Fechas: `31/12/2026`, `2026-12-31`, `5-1-26` y número de serie de Excel.
- Importes: `1.234,56`, `1,234.56`, `$ 121000`, `12,5`.
- Encabezados con mayúsculas, acentos y espacios; alias de columnas; filas vacías ignoradas.
- CSV con BOM y punto y coma; XLSX con la hoja `Facturas`.
- Rechaza: referencia vacía o repetida, referencia ya facturada, CUIT con dígito verificador mal,
  total que no cierra, fecha fuera de tolerancia, servicios sin fechas, alícuota inexistente,
  punto de venta ajeno, nota de crédito sin asociado, condición de IVA fuera de la tabla de ARCA,
  descripción vacía, clase de comprobante que no corresponde a la condición del emisor,
  factura C con alícuota.
- Archivo sin los encabezados de la plantilla y archivo al que le falta una columna obligatoria.

**Procesador, reconciliador, PDF y exportaciones** (con un ARCA simulado)
- Tres facturas seguidas: números consecutivos, CAE guardado, PDF generado, lote FINALIZADO.
- Rechazo por datos: no consume número, el lote sigue, la fila sale en `errores.csv`.
- Numeración desfasada (10016): reconsulta y reintenta **una** vez; si vuelve a fallar, pausa el
  lote con "alguien emitió por fuera de la app".
- Error técnico: la fila queda `INCIERTO` y el lote pausado. Al continuar, **consulta antes de
  reintentar**: si ARCA no lo tiene vuelve a la cola; si lo tiene, recupera el CAE sin duplicar;
  si hay otro comprobante con ese número, `REVISION_MANUAL` y pausa.
- Problema de certificado: la fila vuelve a la cola y el lote se pausa.
- `FEDummy` en error: no se emite nada.
- Lock por punto de venta: si otra pestaña está emitiendo, el paso contesta `ocupado`.
- Un comprobante con CAE no se reintenta ni se descarta nunca.
- QR: la URL lleva el JSON con `ver`, `fecha`, `cuit`, `ptoVta`, `tipoCmp`, `nroCmp`, `importe`,
  `moneda`, `ctz`, `tipoDocRec`, `nroDocRec`, `tipoCodAut` = `E` y `codAut` = CAE.
- `emitidas.csv` con BOM, separador `;`, decimales con coma, columnas de la plantilla + CAE y link al PDF.
- `errores.csv` con las mismas columnas de la plantilla + `motivo`.
- `pdfs.zip` con los PDF del lote.

---

## 2. A mano, con el certificado real

Antes de la primera factura de verdad.

### 2.1 Sin emitir nada

```bash
php tests/emitir_prueba.php
```

Tiene que mostrar: `FEDummy` en OK, ticket de acceso con su vencimiento, la tabla de condiciones
de IVA y el último número autorizado del punto de venta. Si falla acá, el problema es el
certificado o la autorización del alias, no la app.

Lo mismo se puede hacer desde la pantalla de **Configuración** con *Probar conexión con ARCA*.

### 2.2 Una factura de prueba

En homologación:

```bash
php tests/emitir_prueba.php --emitir --tipo=11 --total=1210
```

En producción (la factura es **real**, con validez fiscal):

```bash
php tests/emitir_prueba.php --emitir --si-es-produccion --tipo=11 --total=1210
```

El script muestra todos los campos antes de emitir y **frena** si falta el segundo flag.
Después de autorizar, consulta el comprobante en ARCA y compara importe y fecha.

### 2.3 Desde la app, con una planilla de una fila

1. Descargar la plantilla desde *Subir facturas*.
2. Una sola fila, con una referencia tipo `PRUEBA-001`.
3. Subir y revisar la vista previa (tiene que salir en verde).
4. *Emitir* y esperar la barra.
5. Descargar `emitidas.csv` y el PDF: el QR tiene que abrir la página de ARCA con los datos del
   comprobante, y el CAE coincidir.
6. Si fue en producción y era solo una prueba, anularla con una nota de crédito: en la planilla,
   `tipo_cbte` 13 (o 3 / 8 según la clase), `asoc_tipo`, `asoc_pto_vta` y `asoc_nro` del
   comprobante original.

### 2.4 Casos que conviene provocar una vez

| Caso | Cómo provocarlo | Qué tiene que pasar |
|---|---|---|
| CUIT inválido | poner un CUIT con el último dígito cambiado | la fila sale en rojo en la vista previa, no se emite |
| Total que no cierra | neto 1000, alícuota 21, total 1000 | fila en rojo con los dos importes en el mensaje |
| Servicios sin fechas | concepto 2 sin `serv_desde` | fila en rojo pidiendo las fechas |
| Timeout | `simular_timeout_cada_n => 2` en `config.php` (**solo en homologación**) | la fila queda "sin respuesta de ARCA", el lote se pausa, y al continuar se reconcilia sola |
| Numeración desfasada | emitir un comprobante desde el portal de ARCA con el mismo punto de venta | el lote se pausa avisando que alguien emitió por fuera |
| Certificado vencido | cambiar el ambiente a uno sin certificado | el lote se pausa con la instrucción de renovar |
| Referencia repetida | subir dos veces el mismo archivo | la segunda vez las filas ya emitidas se rechazan por referencia duplicada |

---

## 3. Antes de pasar a producción

- [ ] `login_requerido` en `true` en `config/config.php`.
- [ ] `simular_timeout_cada_n` en `0`.
- [ ] `app_url` con el dominio real y HTTPS.
- [ ] `monto_identificacion_cf` con el valor vigente de la resolución de ARCA.
- [ ] Ambiente del emisor en `prod`, con su certificado de producción y el punto de venta real.
- [ ] El punto de venta es exclusivo de la app (nadie emite desde el portal con ese número).
- [ ] `storage/` no accesible por web (probar `https://.../storage/facturador.sqlite` → 403).
- [ ] Permisos: carpetas `755`, archivos `644`, y `storage/` escribible.
