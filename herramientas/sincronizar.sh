#!/bin/bash
# Copia el CÓDIGO de esta instalación a otra, sin tocar su configuración ni sus datos.
# Sirve cuando en un mismo servidor conviven la demostración y la instalación real:
# se actualiza una y con esto se pasa a la otra.
#
#     ./herramientas/sincronizar.sh ~/facturador-arca-prod
#
# Nunca copia: config/ (app_key, usuarios, modo_demo) ni storage/ (base, certificados,
# PDF, logs). Esos son propios de cada instalación.

set -e

ORIGEN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DESTINO="${1:-}"

if [ -z "$DESTINO" ]; then
    echo "Uso: $0 /ruta/de/la/otra/instalacion" >&2
    exit 1
fi
if [ ! -d "$DESTINO" ]; then
    echo "No existe $DESTINO" >&2
    exit 1
fi
if [ ! -f "$DESTINO/config/config.php" ]; then
    echo "$DESTINO no parece una instalación (no tiene config/config.php)" >&2
    exit 1
fi
if [ "$ORIGEN" = "$DESTINO" ]; then
    echo "El origen y el destino son el mismo directorio" >&2
    exit 1
fi

echo "Origen:  $ORIGEN"
echo "Destino: $DESTINO"
echo

for CARPETA in src public templates db plantilla herramientas tests vendor; do
    [ -d "$ORIGEN/$CARPETA" ] || continue
    if [ "$CARPETA" = "public" ]; then
        # El .htaccess de public/ lleva el handler de PHP que puso cPanel, y en la
        # demostración puede haber un symlink a la otra instalación: no se pisan.
        rsync -a --delete \
            --exclude '.htaccess' --exclude 'produccion' \
            "$ORIGEN/$CARPETA/" "$DESTINO/$CARPETA/"
    else
        rsync -a --delete "$ORIGEN/$CARPETA/" "$DESTINO/$CARPETA/"
    fi
    echo "  $CARPETA"
done

for ARCHIVO in composer.json composer.lock README.md LICENSE CLAUDE.md config/config.example.php; do
    [ -f "$ORIGEN/$ARCHIVO" ] && cp "$ORIGEN/$ARCHIVO" "$DESTINO/$ARCHIVO" && echo "  $ARCHIVO"
done

echo
echo "Permisos (suexec)"
find "$DESTINO" -type d -exec chmod 755 {} \;
find "$DESTINO" -type f -exec chmod 644 {} \;
chmod -R u+w "$DESTINO/storage"
chmod 640 "$DESTINO/config/config.php"
[ -f "$DESTINO/herramientas/sincronizar.sh" ] && chmod 755 "$DESTINO/herramientas/sincronizar.sh"

echo
echo "Listo. La configuración y los datos de $DESTINO quedaron intactos."
