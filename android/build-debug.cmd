@echo off

if /i "%~1"=="--internal" goto :internal
if not exist "%~dp0build" mkdir "%~dp0build"
call "%~f0" --internal %~1 > "%~dp0build\build-debug.log" 2>&1
set "BUILD_EXIT_CODE=%ERRORLEVEL%"
type "%~dp0build\build-debug.log"
echo Build script exit code: %BUILD_EXIT_CODE%
exit /b %BUILD_EXIT_CODE%

:internal
setlocal EnableExtensions

set "BMLHS_SDK_ROOT=%ANDROID_HOME%"
if not defined BMLHS_SDK_ROOT set "BMLHS_SDK_ROOT=%ANDROID_SDK_ROOT%"
if not defined BMLHS_SDK_ROOT if exist "%LOCALAPPDATA%\Android\Sdk" set "BMLHS_SDK_ROOT=%LOCALAPPDATA%\Android\Sdk"
if not defined BMLHS_SDK_ROOT if exist "%USERPROFILE%\AppData\Local\Android\Sdk" set "BMLHS_SDK_ROOT=%USERPROFILE%\AppData\Local\Android\Sdk"

if not defined BMLHS_SDK_ROOT (
    echo Android SDK not found. Install Android SDK Platform 35 using Android Studio SDK Manager.
    echo Then set ANDROID_HOME to the SDK location or install it in the default location:
    echo %LOCALAPPDATA%\Android\Sdk
    exit /b 1
)

set "ANDROID_SDK_ROOT=%BMLHS_SDK_ROOT%"
set "ANDROID_HOME=%ANDROID_SDK_ROOT%"
set "BMLHS_JAVA_HOME=%JAVA_HOME%"
if not defined BMLHS_JAVA_HOME set "BMLHS_JAVA_HOME=%JAVA_HOME_17_X64%"
if defined BMLHS_JAVA_HOME if exist "%BMLHS_JAVA_HOME%\bin\javac.exe" goto :use_java
set "BMLHS_JAVA_HOME="

if exist "%LOCALAPPDATA%\Programs\BMLHS\jdk-17\bin\javac.exe" set "BMLHS_JAVA_HOME=%LOCALAPPDATA%\Programs\BMLHS\jdk-17"
if exist "%ProgramFiles%\Android\Android Studio\jbr\bin\javac.exe" set "BMLHS_JAVA_HOME=%ProgramFiles%\Android\Android Studio\jbr"
if not defined BMLHS_JAVA_HOME if exist "%LOCALAPPDATA%\Programs\Android Studio\jbr\bin\javac.exe" set "BMLHS_JAVA_HOME=%LOCALAPPDATA%\Programs\Android Studio\jbr"

:use_java
if not defined BMLHS_JAVA_HOME (
    echo JDK not found. Install JDK 17 or Android Studio, then set JAVA_HOME.
    echo Example: winget install --id EclipseAdoptium.Temurin.17.JDK --exact
    exit /b 1
)
set "JAVA_HOME=%BMLHS_JAVA_HOME%"
set "PATH=%JAVA_HOME%\bin;%PATH%"
echo Using Java:
java -version
if errorlevel 1 exit /b 1

set "GRADLE_USER_HOME=%LOCALAPPDATA%\Programs\BMLHS\gradle-home"
if not exist "%GRADLE_USER_HOME%" mkdir "%GRADLE_USER_HOME%"
set "GRADLE_PROJECT_CACHE=%LOCALAPPDATA%\Programs\BMLHS\project-cache"
if not exist "%GRADLE_PROJECT_CACHE%" mkdir "%GRADLE_PROJECT_CACHE%"

if exist "%ANDROID_SDK_ROOT%\platforms\android-35\android.jar" goto :build

set "SDKMANAGER="
if exist "%ANDROID_SDK_ROOT%\cmdline-tools\latest\bin\sdkmanager.bat" set "SDKMANAGER=%ANDROID_SDK_ROOT%\cmdline-tools\latest\bin\sdkmanager.bat"
if not defined SDKMANAGER goto :sdk_missing

echo Accept the Android SDK licenses when prompted.
call "%SDKMANAGER%" --licenses
if errorlevel 1 exit /b 1
call "%SDKMANAGER%" "platforms;android-35" "build-tools;35.0.0" "platform-tools"
if errorlevel 1 exit /b 1

:build
pushd "%~dp0"
if errorlevel 1 (
    echo Could not switch to the Android project directory "%~dp0".
    exit /b 1
)
echo Building BMLHS debug APK.
if /i "%~2"=="--force" (
    call gradlew.bat --no-daemon --console=plain --stacktrace --project-cache-dir "%GRADLE_PROJECT_CACHE%" --rerun-tasks :app:assembleDebug
) else (
    call gradlew.bat --no-daemon --console=plain --stacktrace --project-cache-dir "%GRADLE_PROJECT_CACHE%" :app:assembleDebug
)
set "BUILD_EXIT_CODE=%ERRORLEVEL%"
popd
echo Gradle exit code: %BUILD_EXIT_CODE%
if "%BUILD_EXIT_CODE%"=="0" (
    echo APK: "%~dp0app\build\outputs\apk\debug\app-debug.apk"
)
exit /b %BUILD_EXIT_CODE%

:sdk_missing
echo Android SDK Platform 35 is missing and sdkmanager was not found.
echo Install Android SDK Platform 35 and Android SDK Build-Tools using Android Studio SDK Manager.
exit /b 1
