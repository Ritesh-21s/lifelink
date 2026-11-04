@echo off
REM LifeLink – Full Deploy + Migration Script
REM Run this as Administrator from the blood-donation folder
REM Step 1: Start Apache + MySQL via XAMPP Control Panel first,
REM         then run this script.

echo ===================================
echo  LifeLink Deploy + Migrate Script
echo ===================================

SET SRC=D:\blood-donation\lifelink
SET DST=C:\xampp\htdocs\lifelink
SET MYSQL=C:\xampp\mysql\bin\mysql.exe
SET PHP=C:\xampp\php\php.exe

echo.
echo [1/3] Copying files to XAMPP htdocs...
robocopy "%SRC%" "%DST%" /E /XD vendor .git /XF .gitignore /NFL /NDL /NJH /NJS /nc /ns /np
if %ERRORLEVEL% LEQ 1 (echo  Files copied OK.) else (echo  WARNING: robocopy reported errors.)

echo.
echo [2/3] Running migration_002_location_chat.sql...
"%MYSQL%" -u root lifelink < "%SRC%\database\migration_002_location_chat.sql"
if %ERRORLEVEL% EQU 0 (echo  Migration OK.) else (echo  ERROR: Migration failed - check MySQL is running.)

echo.
echo [3/3] Verifying new tables exist...
"%MYSQL%" -u root lifelink -e "SHOW TABLES LIKE 'conversations'; SHOW TABLES LIKE 'notification_queue'; SHOW TABLES LIKE 'location_cache';"

echo.
echo Repairing legacy conversation columns if needed...
"%PHP%" "%DST%\upgrade_conversations.php"

echo.
echo Done. Open http://localhost/lifelink to test.
pause

