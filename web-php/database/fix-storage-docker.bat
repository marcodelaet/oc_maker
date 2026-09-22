@echo off
setlocal
REM Container Apache/PHP local: webserver_php
REM Caminho no container: /var/www/html/oc_maker/web-php

echo Container: webserver_php
echo Caminho:   /var/www/html/oc_maker/web-php/database/fix-storage-docker.sh
echo.

docker exec -u root webserver_php sh /var/www/html/oc_maker/web-php/database/fix-storage-docker.sh
if errorlevel 1 (
  echo.
  echo Falha ao ajustar storage. Verifique se o container webserver_php esta rodando.
  exit /b 1
)

echo.
echo Storage pronto para uploads e criativos.
exit /b 0
