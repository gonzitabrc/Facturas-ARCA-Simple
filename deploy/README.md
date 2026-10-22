# deploy/ — datos reales de cada instalación

**Esta carpeta no entra al repositorio** (está en `.gitignore`), salvo estos dos archivos de
ejemplo: `README.md` y `PASOS-ejemplo.md`.

Acá va lo que es de cada cliente y no se comparte con nadie:

| Qué | Por qué no se versiona |
|---|---|
| `PASOS.md` o notas de instalación | tienen CUIT, punto de venta, alias del certificado, rutas y host del servidor |
| `*.csr`, `*.crt`, `*.key` | material del certificado fiscal |
| `*.xlsx` de planillas reales | datos de contribuyentes y de sus clientes |
| `facturador-arca.zip` | el paquete armado, que puede traer configuración |

Si hay que documentar o ejemplificar algo en el repositorio, va con **datos de ejemplo**:
CUIT `20123456789`, dominio `facturas.tudominio.com.ar`, usuario `USUARIO`. Nunca con los reales.

Para armar la guía de una instalación nueva, copiá `PASOS-ejemplo.md` a `PASOS.md` y completalo:
ese archivo ya queda ignorado por git.

## Dónde vive cada secreto

| Secreto | Dónde está | En el repo |
|---|---|---|
| `app_key` (cifra las claves privadas) | `config/config.php` | no |
| Usuarios y claves de acceso | `config/config.php` | no |
| Clave privada del certificado | `storage/certs/{cuit}/{ambiente}/clave.key.enc`, cifrada | no |
| Certificado `.crt` | `storage/certs/{cuit}/{ambiente}/` | no |
| Ticket de acceso de ARCA | `storage/ta/*.json` | no |
| Base con los comprobantes | `storage/facturador.sqlite` | no |
| PDF emitidos | `storage/pdf/{cuit}/{mes}/` | no |
| Planillas subidas | `storage/lotes/{mes}/` | no |

Lo único que el repositorio trae sobre configuración es `config/config.example.php`, con
marcadores para completar.
