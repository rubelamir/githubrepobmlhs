@echo off
setlocal EnableExtensions

set "JDK_HOME=%LOCALAPPDATA%\Programs\BMLHS\jdk-17"
if exist "%JDK_HOME%\bin\javac.exe" (
    echo JDK 17 is already installed at "%JDK_HOME%".
    exit /b 0
)

where curl.exe >nul 2>&1
if errorlevel 1 (
    echo curl.exe is required. Use a current Windows 10 or Windows 11 installation.
    exit /b 1
)
where tar.exe >nul 2>&1
if errorlevel 1 (
    echo tar.exe is required. Use a current Windows 10 or Windows 11 installation.
    exit /b 1
)

set "STAGING=%TEMP%\bmlhs-jdk17-%RANDOM%-%RANDOM%"
set "ARCHIVE=%STAGING%.zip"
echo Downloading Eclipse Temurin JDK 17 from Adoptium...
curl.exe --fail --location --retry 2 --output "%ARCHIVE%" "https://api.adoptium.net/v3/binary/latest/17/ga/windows/x64/jdk/hotspot/normal/eclipse"
if errorlevel 1 (
    echo JDK download failed. Check the network connection and try again.
    exit /b 1
)

mkdir "%STAGING%"
if errorlevel 1 (
    echo Could not create temporary extraction folder "%STAGING%".
    del /q "%ARCHIVE%" >nul 2>&1
    exit /b 1
)
tar.exe -xf "%ARCHIVE%" -C "%STAGING%"
if errorlevel 1 (
    echo JDK archive extraction failed.
    rmdir /s /q "%STAGING%"
    del /q "%ARCHIVE%" >nul 2>&1
    exit /b 1
)
del /q "%ARCHIVE%" >nul 2>&1

set "EXTRACTED_JDK="
for /d %%D in ("%STAGING%\*") do if exist "%%~fD\bin\javac.exe" set "EXTRACTED_JDK=%%~fD"
if not defined EXTRACTED_JDK (
    echo The downloaded archive did not contain a JDK compiler.
    rmdir /s /q "%STAGING%"
    exit /b 1
)

if exist "%JDK_HOME%" (
    echo The target folder exists but is not a usable JDK: "%JDK_HOME%".
    rmdir /s /q "%STAGING%"
    exit /b 1
)
for %%D in ("%JDK_HOME%") do if not exist "%%~dpD" mkdir "%%~dpD"
move "%EXTRACTED_JDK%" "%JDK_HOME%" >nul
if errorlevel 1 (
    echo Could not install JDK into "%JDK_HOME%".
    rmdir /s /q "%STAGING%"
    exit /b 1
)
rmdir /s /q "%STAGING%"

set "JAVA_HOME=%JDK_HOME%"
set "PATH=%JAVA_HOME%\bin;%PATH%"
echo Installed JDK:
java -version
if errorlevel 1 exit /b 1
javac -version
exit /b %ERRORLEVEL%
