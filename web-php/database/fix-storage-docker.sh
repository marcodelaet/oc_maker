#!/bin/sh
# Ajusta permissões de storage dentro do container Docker (Apache/PHP como www-data).
# No Windows (host): database\fix-storage-docker.bat
# Manual (use barras /, não \): docker exec -u root webserver_php sh /var/www/html/oc_maker/web-php/database/fix-storage-docker.sh

set -eu

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
STORAGE="$ROOT/storage"

if [ "$(id -u)" -ne 0 ]; then
    echo "Execute como root dentro do container." >&2
    echo "Ex.: database\\fix-storage-docker.bat" >&2
    exit 1
fi

mkdir -p "$STORAGE/uploads" "$STORAGE/avatars" "$STORAGE/spreadsheets/campaign-cache" "$STORAGE/pdf" "$STORAGE/creatives"
chown -R www-data:www-data "$STORAGE"
chmod -R 775 "$STORAGE"

if id www-data >/dev/null 2>&1; then
    su -s /bin/sh www-data -c "php '$ROOT/database/ensure-storage.php'"
else
    php "$ROOT/database/ensure-storage.php"
fi

PHP_INI_DIR="/usr/local/etc/php/conf.d"
if [ -d "$PHP_INI_DIR" ]; then
    cat > "$PHP_INI_DIR/99-oc-maker-uploads.ini" << 'EOF'
; OC Maker — uploads de planilha e criativos
upload_max_filesize = 32M
post_max_size = 36M
max_file_uploads = 20
max_execution_time = 120
max_input_time = 120
memory_limit = 256M
EOF
    echo "PHP ini aplicado em $PHP_INI_DIR/99-oc-maker-uploads.ini"
    if command -v apache2ctl >/dev/null 2>&1; then
        apache2ctl graceful 2>/dev/null || true
    fi
fi
