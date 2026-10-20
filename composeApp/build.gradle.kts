plugins {
    alias(libs.plugins.kotlinMultiplatform)
    alias(libs.plugins.androidApplication)
    alias(libs.plugins.composeMultiplatform)
    alias(libs.plugins.composeCompiler)
}

kotlin {
    androidTarget()

    listOf(
        iosX64(),
        iosArm64(),
        iosSimulatorArm64()
    ).forEach { iosTarget ->
        iosTarget.binaries.framework {
            baseName = "ComposeApp"
            isStatic = true
            binaryOption("bundleId", "com.shopkmp")
        }
    }

    sourceSets {
        androidMain.dependencies {
            implementation(libs.compose.uiToolingPreview)
            implementation(libs.androidx.activity.compose)
            implementation(libs.koin.android)
            implementation(libs.sentry.android)
        }
        androidInstrumentedTest.dependencies {
            implementation("androidx.test:runner:1.6.2")
            implementation("androidx.test.ext:junit:1.2.1")
            implementation("androidx.test.uiautomator:uiautomator:2.3.0")
        }
        commonMain.dependencies {
            implementation(project(":core:common"))
            implementation(project(":core:mvvm"))
            implementation(project(":core:navigation"))
            implementation(project(":core:network"))
            implementation(project(":core:uikit"))
            implementation(project(":core:settings"))
            implementation(project(":core:remoteresult"))

            implementation(project(":feature:main"))
            implementation(project(":feature:home:home-data"))
            implementation(project(":feature:home:home-domain"))
            implementation(project(":feature:profile:data"))
            implementation(project(":feature:profile:domain"))

            implementation(libs.bundles.compose)
            implementation(libs.bundles.nav3)
            implementation(libs.bundles.koin.compose)
            implementation(libs.androidx.lifecycle.viewmodelCompose)
            implementation(libs.androidx.lifecycle.runtimeCompose)
            implementation(libs.koin.core)
            implementation(libs.kotlinx.coroutines.core)
            implementation(libs.napier)
            implementation(libs.coil.compose)
        }
        commonTest.dependencies {
            implementation(libs.kotlin.test)
        }
    }
}

android {
    namespace = "com.shopkmp"

    val sentryDsn = providers.gradleProperty("SHOPKMP_SENTRY_DSN")
        .orElse(providers.environmentVariable("SHOPKMP_SENTRY_DSN"))
        .getOrElse("")

    defaultConfig {
        applicationId = "com.shopkmp"
        versionCode = 1
        versionName = "1.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        buildConfigField("String", "SENTRY_DSN", "\"$sentryDsn\"")
    }

    buildFeatures {
        buildConfig = true
        compose = true
    }

    val keystoreFile = providers.environmentVariable("SHOPKMP_KEYSTORE_FILE").orNull
    val keystorePassword = providers.environmentVariable("SHOPKMP_KEYSTORE_PASSWORD").orNull
    val keyAliasValue = providers.environmentVariable("SHOPKMP_KEY_ALIAS").orNull
    val keyPasswordValue = providers.environmentVariable("SHOPKMP_KEY_PASSWORD").orNull
    val signingValues = listOf(keystoreFile, keystorePassword, keyAliasValue, keyPasswordValue)
    if (signingValues.any { !it.isNullOrBlank() } && signingValues.any { it.isNullOrBlank() }) {
        error("SHOPKMP_KEYSTORE_FILE, SHOPKMP_KEYSTORE_PASSWORD, SHOPKMP_KEY_ALIAS and SHOPKMP_KEY_PASSWORD must be configured together")
    }
    val releaseSigning = if (
        !keystoreFile.isNullOrBlank() &&
        !keystorePassword.isNullOrBlank() &&
        !keyAliasValue.isNullOrBlank() &&
        !keyPasswordValue.isNullOrBlank()
    ) {
        signingConfigs.create("release") {
            storeFile = file(keystoreFile)
            storePassword = keystorePassword
            keyAlias = keyAliasValue
            keyPassword = keyPasswordValue
        }
    } else {
        signingConfigs.getByName("debug")
    }

    buildTypes {
        release {
            signingConfig = releaseSigning
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
        }
    }

    packaging {
        resources {
            excludes += "/META-INF/{AL2.0,LGPL2.1}"
        }
    }

}
