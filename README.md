# Facturador ARCA simple

Subís un Excel con las facturas → la app valida → pide los CAE a ARCA (ex AFIP) con el certificado del
propio emisor → genera los PDF con QR → descargás un CSV con número de comprobante, CAE y link a cada PDF.

Pensado para la empresa que **tiene sistema de gestión pero no facturación electrónica**: en vez de cargar
una por una en el portal de ARCA, exporta una planilla y emite el lote entero.

PHP + SQLite, sin frameworks y sin build. Corre en un hosting compartido con cPanel.

---

## Qué hace

- **Lee XLSX y CSV**: fechas de Excel o escritas a mano, decimales con coma o punto, encabezados con
  acentos y mayúsculas, columnas con nombres alternativos.
- **Valida antes de emitir** y muestra la vista previa en verde y rojo: CUIT con dígito verificador,
  totales que no cierran, fechas fuera de tolerancia, servicios sin período, alícuotas inexistentes,
  notas de crédito sin comprobante asociado, referencias repetidas. Cada error se explica en castellano
  y diciendo qué corregir.
- **Emite de a una factura por llamada**, pidiéndole siempre a ARCA el último número autorizado.
- **No duplica ni pierde comprobantes.** Antes de llamar a ARCA graba el número que va a usar; si el
  proceso se corta, al continuar le pregunta a ARCA qué pasó con ese número en vez de reintentar a ciegas.
- **Genera el PDF** (A4, con QR según la especificación de ARCA) y lo sirve por un link con token.
- **Exporta** `emitidas.csv` (con CAE y link al PDF, para volver a cargar en el sistema de gestión),
  `errores.csv` (mismas columnas de la planilla + motivo, para corregir y volver a subir) y `pdfs.zip`.
- **Gestiona el certificado**: genera la clave y el pedido (CSR), o importa uno que ya exista; guarda la
  clave privada cifrada con AES-256-GCM y avisa 30 días antes del vencimiento.
- **Notas de crédito y débito** con comprobante asociado.
- Factura A, B y C; productos, servicios o ambos; pesos u otra moneda con cotización.

Lo que busca asegurar es **transparencia**: que ninguna factura se pierda ni se duplique sin que se vea.

## Requisitos

- PHP 8.1 o superior con `soap`, `openssl`, `pdo_sqlite`, `mbstring`, `zip` y `gd`.
- Composer (o subir `vendor/` ya armado).
- Un certificado de ARCA y un punto de venta tipo "Web Services" **exclusivo** para la app.
- SSL en el dominio.

## Instalación

```bash
git clone https://github.com/USUARIO/facturador-arca.git
cd facturador-arca
composer install --no-dev
php herramientas/preparar_config.php https://facturas.tudominio.com.ar --usuario=admin
```

El document root del dominio tiene que apuntar a `public/`. El resto de las carpetas queda fuera de la
web (y hay `.htaccess` que las bloquean por las dudas). La base SQLite se crea sola en el primer acceso.

En cPanel, después de subir por SSH o SFTP:

```bash
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod -R u+w storage
```

Y antes de facturar, el chequeo del servidor:

```bash
php tests/revisar_servidor.php
```

Revisa versión de PHP, extensiones, permisos, que la base se cree, que se pueda leer el WSDL de ARCA y
que `storage/` no quede expuesto.

## Primeros pasos

1. **Configuración** → datos del emisor y punto de venta.
2. **Certificado**: *Subir o actualizar el certificado* si ya lo tenés, o generar el pedido (CSR),
   subirlo a ARCA, autorizar el alias para *Facturación Electrónica* y subir el `.crt`.
3. *Probar conexión con ARCA* (no emite nada).
4. Descargar la plantilla, completar una fila por factura, subir, revisar y emitir.

La pantalla de **Ayuda** tiene el manual de uso, los pasos del certificado paso a paso y las condiciones
de uso. Se puede leer sin iniciar sesión.

## Herramientas de línea de comandos

```bash
php herramientas/preparar_config.php <url> [--usuario=] [--clave=]   # arma config/config.php
php herramientas/preparar_emisor.php --cuit= --razon= ... [--probar] # emisor + certificado + prueba
php herramientas/poner_usuarios.php usuario:clave [...]              # usuarios de acceso
php herramientas/armar_planilla.php --total=1210                     # planilla de una fila, ya validada
php herramientas/armar_planilla.php --nota-de-credito=123            # la NC espejo de un comprobante
php tests/emitir_prueba.php [--emitir]                               # prueba de punta a punta contra ARCA
php tests/verificar.php                                              # 81 chequeos, sin tocar ARCA
```

`tests/verificar.php` cubre cifrado y certificados, el armado y la firma del TRA de WSAA (verificada con
OpenSSL), el armado de `FECAESolicitar` para C y B, el importador y el validador, y la máquina de estados
completa con un ARCA simulado: rechazos, numeración desfasada, timeouts y las tres salidas de la
reconciliación. No emite nada ni necesita certificado válido. El detalle está en `tests/casos.md`.

## Cómo está hecho

```
public/     lo único expuesto a la web
src/        Arca (Wsaa, Wsfe, Errores) · Lote (Importador, Validador, Procesador, Reconciliador)
            Pdf · Export · Seguridad (Cifrado, Certificados, Auth)
templates/  layout y el HTML del comprobante
tests/      pruebas automáticas y casos de prueba
herramientas/ scripts de instalación y mantenimiento
storage/    base, certificados cifrados, PDF, logs (fuera de la web)
```

La especificación completa, con la máquina de estados y las reglas de validación, está en `CLAUDE.md`.

## Soporte e implementación

El código es libre y se puede usar sin pagar nada: si te lo instalás vos, no nos debés nada y tampoco
tenés soporte.

**El soporte es un servicio pago, y está incluido durante un mes para quien contrata la implementación**
con [DDW](https://ddw.com.ar). La implementación incluye:

- instalación y puesta en marcha en el servidor,
- trámites y gestiones en ARCA (certificado, alias, punto de venta),
- configuración del emisor y la plantilla estándar,
- pruebas iniciales hasta dejarlo emitiendo,
- **un mes de soporte** desde la puesta en marcha.

Después de ese mes, el soporte se cotiza **por hora** según las
[tarifas de desarrollo a medida](https://ddw.com.ar/tarifas.html#desarrolloprogramacion-web-a-medida).
La renovación del certificado de ARCA, que es cada 2 años, corre por cuenta del cliente o se gestiona
con costo aparte. El hosting se contrata por separado; cuando el hosting es de DDW, los problemas de
hosting se atienden sin cargo.

Sobre los issues de este repositorio:

- **Bugs**: bienvenidos. Contá qué esperabas, qué pasó y cómo reproducirlo. Nunca pegues certificados,
  claves privadas, tokens ni datos de contribuyentes.
- **Dudas de instalación, de ARCA o de impuestos**: no se responden por acá, son parte del servicio pago.
- **Pull requests**: bienvenidos si vienen con una explicación de qué resuelven.

## Qué nunca entra al repositorio

Los secretos y los datos de cada contribuyente viven fuera del control de versiones, y lo que se
versiona son archivos de ejemplo con datos inventados:

| Real (ignorado) | Ejemplo (versionado) |
|---|---|
| `config/config.php` — `app_key`, usuarios y claves | `config/config.example.php` |
| `storage/` — base, certificados cifrados, tickets, PDF y planillas subidas | solo la estructura de carpetas |
| `deploy/` — notas de instalación, `.csr`, `.crt`, `.key`, planillas reales | `deploy/PASOS-ejemplo.md` |

El `.gitignore` también bloquea `*.crt`, `*.key`, `*.csr`, `*.pem`, `*.p12` y `*.sqlite` en
cualquier carpeta, por si algún archivo queda fuera de su lugar. En la documentación y en los
ejemplos se usan siempre datos inventados: CUIT `20123456789`, dominio `facturas.tudominio.com.ar`.
Los tests trabajan con un emisor de prueba con nombres `ZZTEST` que borran al terminar.

## Condiciones de uso

Este software se entrega **tal cual está**, bajo [licencia MIT](LICENSE), sin garantías de ningún tipo.
Se puede usar, modificar, distribuir y vender libremente; lo único que pide la licencia es conservar el
aviso de copyright y la licencia en las copias del código.

Quien emite comprobantes con esta aplicación es el **único responsable** de los datos que carga, de los
comprobantes que emite y de sus consecuencias fiscales. Los autores y DDW quedan eximidos de toda
responsabilidad por daños, multas, sanciones, pérdida de datos o reclamos de terceros derivados del uso o
de la imposibilidad de uso del software. **El uso de la aplicación implica la aceptación de estas
condiciones.**

Esta aplicación no reemplaza al contador ni da asesoramiento impositivo, y **no está afiliada a ARCA**
(ex AFIP) ni avalada por el organismo. "ARCA" y "AFIP" se usan solo para identificar los servicios con los
que la aplicación se comunica.
