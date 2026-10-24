@echo off
cd /d "%~dp0"
start "Reverb" cmd /k php artisan reverb:start --host=0.0.0.0 --port=8081
php artisan serve
