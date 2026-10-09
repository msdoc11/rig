@echo off
rem rig: rig dev | rig check | rig push | rig help   (PowerShell: .\rig dev)
chcp 65001 >nul
php "%~dp0rig\cli.php" %*
