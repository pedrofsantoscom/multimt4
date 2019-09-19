@echo off
start "" ping 127.0.0.1 -n 1 > nul & explorer "http://localhost:8000/indicatorsConfigs.php"
php -S localhost:8000