import java.net.URI

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

val apiBaseUrl = providers.gradleProperty("BMLHS_API_BASE_URL")
    .orElse("https://bmlhs.site/bmlhs/api/v1/")
    .get()
val apiBaseUri = URI(apiBaseUrl)
val adminLoginUrl = providers.gradleProperty("BMLHS_ADMIN_LOGIN_URL")
    .orElse("${apiBaseUri.scheme}://${apiBaseUri.authority}/dist/examples/login-v2.php")
    .get()
val adminWebSessionUrl = URI(adminLoginUrl).resolve("/dist/mobile-session.php").toString()
val releaseSigningValues = listOf(
    providers.environmentVariable("BMLHS_RELEASE_STORE_FILE").orNull,
    providers.environmentVariable("BMLHS_RELEASE_STORE_PASSWORD").orNull,
    providers.environmentVariable("BMLHS_RELEASE_KEY_ALIAS").orNull,
    providers.environmentVariable("BMLHS_RELEASE_KEY_PASSWORD").orNull,
)
val hasReleaseSigningValues = releaseSigningValues.any { !it.isNullOrBlank() }
val hasCompleteReleaseSigningValues = releaseSigningValues.all { !it.isNullOrBlank() }

if (hasReleaseSigningValues && !hasCompleteReleaseSigningValues) {
    throw GradleException("Set all four BMLHS_RELEASE_* signing environment variables to sign a release APK.")
}

android {
    namespace = "com.bmlhs.mobile"
    compileSdk = 35

    if (hasCompleteReleaseSigningValues) {
        signingConfigs {
            create("release") {
                storeFile = file(releaseSigningValues[0]!!)
                storePassword = releaseSigningValues[1]
                keyAlias = releaseSigningValues[2]
                keyPassword = releaseSigningValues[3]
            }
        }
    }

    defaultConfig {
        applicationId = "com.bmlhs.mobile"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "1.0.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        buildConfigField("String", "API_BASE_URL", "\"$apiBaseUrl\"")
        buildConfigField("String", "ADMIN_LOGIN_URL", "\"$adminLoginUrl\"")
        buildConfigField("String", "ADMIN_WEB_SESSION_URL", "\"$adminWebSessionUrl\"")
    }

    buildTypes {
        release {
            if (hasCompleteReleaseSigningValues) {
                signingConfig = signingConfigs.getByName("release")
            }
        }
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2024.12.01")
    implementation(composeBom)
    androidTestImplementation(composeBom)
    implementation("androidx.activity:activity-compose:1.10.0")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.7")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.7")
    debugImplementation("androidx.compose.ui:ui-tooling")
    androidTestImplementation("androidx.test:runner:1.6.2")
    androidTestImplementation("androidx.test.ext:junit:1.2.1")
    androidTestImplementation("com.squareup.okhttp3:mockwebserver:4.12.0")
}
