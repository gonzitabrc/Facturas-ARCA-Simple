# Instalar en un subdominio con cPanel

Plantilla de la guía de instalación. Copiala a `PASOS.md` (que no se versiona) y completá los
datos reales de cada cliente.

En los comandos, reemplazar:

- `USUARIO` → el usuario de cPanel
- `facturas.tudominio.com.ar` → el subdominio
- `20123456789` → el CUIT del emisor

---

## 1. Crear el subdominio en cPanel

1. cPanel → **Dominios** → *Crear un dominio* → `facturas.tudominio.com.ar`.
2. **Document root:** `/home/USUARIO/facturador-arca/public` (termina en `/public`).
3. **SSL/TLS Status** → emitir el certificado Let's Encrypt del subdominio.
4. **MultiPHP Manager** → poner el subdominio en PHP 8.1 o superior.
5. **Select PHP Version** → *Extensions* → marcar `soap`, `openssl`, `pdo_sqlite`, `mbstring`,
   `zip` y `gd`.

## 2. Subir y descomprimir

```bash
cd ~
# subir facturador-arca.zip por SFTP o con el File Manager
unzip facturador-arca.zip
rm facturador-arca.zip
```

Si el `.htaccess` de `public/` ya existía (lo crea cPanel con el handler de MultiPHP), conservá ese
bloque arriba del que trae la app: si se pisa, el subdominio vuelve a la versión vieja de PHP.

## 3. Permisos

suexec rechaza lo que quede escribible por el grupo y devuelve **500 sin dejar nada en el log**:

```bash
cd ~/facturador-arca
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod -R u+w storage
```

## 4. Configuración

```bash
php herramientas/preparar_config.php https://facturas.tudominio.com.ar --usuario=admin
```

Genera la `app_key`, el hash de la clave de acceso y deja el login exigido. **Muestra la clave una
sola vez**: anotala en ese momento. Si la `app_key` se pierde o cambia, hay que volver a cargar los
certificados.

## 5. Chequeo del servidor

```bash
php tests/revisar_servidor.php
php tests/verificar.php
```

El primero revisa PHP, extensiones, permisos, base y que `storage/` no quede expuesto. El segundo
corre las pruebas automáticas (no tocan ARCA). Probá también a mano que esto dé 403 o 404:

- `https://facturas.tudominio.com.ar/storage/facturador.sqlite`
- `https://facturas.tudominio.com.ar/../config/config.php`

## 6. Emisor y certificado

Desde la pantalla de **Configuración**, o por línea de comandos:

```bash
php herramientas/preparar_emisor.php --cuit=20123456789 --razon="Nombre o razón social" \
    --domicilio="Dirección, Provincia" --clase=MONO --pv=4 --ambiente=prod \
    --crt=/ruta/certificado.crt --key=/ruta/clave.key --probar
```

**El punto de venta tiene que ser exclusivo de esta app**, tipo "Factura Electrónica - Web Services".
Si se comparte con otro sistema, los dos se pelean la numeración.

**El certificado también conviene que sea propio.** ARCA entrega un solo ticket de acceso por
certificado y dura 12 horas: si dos sistemas comparten el mismo, el que lo pide segundo recibe
*"El CEE ya posee un TA valido"* y queda esperando.

## 7. Primera factura

```bash
php tests/emitir_prueba.php                  # solo consulta, no emite
php herramientas/armar_planilla.php --total=1210
```

Después, desde la app: *Subir facturas* → revisar la vista previa → *Emitir* → descargar el PDF y
`emitidas.csv`. Si fue una prueba en producción, anularla con una nota de crédito:

```bash
php herramientas/armar_planilla.php --nota-de-credito=NUMERO
```

## 8. Antes de entregarlo

- [ ] `login_requerido` en `true`.
- [ ] `simular_timeout_cada_n` en `0`.
- [ ] `monto_identificacion_cf` con el valor vigente de ARCA.
- [ ] Un usuario propio para el cliente (`php herramientas/poner_usuarios.php cliente:CLAVE`).
- [ ] Backup: alcanza con copiar `storage/` y guardar la `app_key` aparte.
- [ ] Mostrarle la pantalla de **Ayuda**: manual de uso y condiciones de uso.

## Si algo sale mal

| Qué ves | Qué es |
|---|---|
| 500 sin nada en el log | permisos: repetir el paso 3 |
| "Falta la configuración" | no se corrió el paso 4, o `config/config.php` no se puede leer |
| "No se pudo conectar con ARCA" | falta `soap`, o el firewall de salida del hosting |
| "ARCA no aceptó el certificado" | el alias no está autorizado para Facturación Electrónica |
| "El CEE ya posee un TA valido" | otro sistema está usando el mismo certificado |
| La app anda pero no emite | mirar la tabla `eventos` y `storage/logs/` |
