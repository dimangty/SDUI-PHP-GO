plugins {
    alias(libs.plugins.kotlinMultiplatform)
    alias(libs.plugins.androidLibrary)
    alias(libs.plugins.serialization)
    alias(libs.plugins.detekt)
    alias(libs.plugins.kover)
}

kotlin {
    compilerOptions {
        freeCompilerArgs.add("-Xexpect-actual-classes")
    }
    androidTarget()
    listOf(iosX64(), iosArm64(), iosSimulatorArm64()).forEach {
        it.binaries.framework { baseName = "network"; isStatic = true }
    }
    sourceSets {
        androidMain.dependencies {
            implementation(libs.ktor.client.okhttp)
        }
        commonMain.dependencies {
            implementation(project(":core:sdui-model"))
            implementation(project(":core:remoteresult"))
            implementation(project(":core:settings"))
            implementation(libs.ktor.client.core)
            implementation(libs.ktor.client.logging)
            implementation(libs.napier)
            implementation(libs.ktor.client.negotiation)
            implementation(libs.ktor.serialization.kotlinx.json)
            implementation(libs.kotlinx.serialization)
            implementation(libs.kotlinx.coroutines.core)
            implementation(libs.kotlinx.datetime)
            implementation(libs.koin.core)
        }
        iosMain.dependencies {
            implementation(libs.ktor.client.ios)
        }
        commonTest.dependencies {
            implementation(libs.multiplatform.settings.test)
            implementation(libs.kotlin.test)
            implementation(libs.kotlinx.coroutines.test)
            implementation(libs.ktor.client.mock)
            implementation(libs.multiplatform.settings)
        }
    }
}

android {
    namespace = "com.shopkmp.network"
    buildFeatures { buildConfig = true }
    defaultConfig {
        val origins = providers.gradleProperty("SHOPKMP_PAYMENT_ORIGINS")
            .orElse(providers.environmentVariable("SHOPKMP_PAYMENT_ORIGINS")).getOrElse("")
        require(origins.none { it == '"' || it == '\\' || it.isISOControl() })
        buildConfigField("String", "PAYMENT_ORIGINS", "\"$origins\"")
    }
    buildTypes {
        val configuredDebugUrl = providers.gradleProperty("SHOPKMP_DEBUG_SERVER_URL")
            .orElse(providers.environmentVariable("SHOPKMP_DEBUG_SERVER_URL"))
            .getOrElse("http://10.0.2.2:8080")
        val configuredReleaseUrl = providers.gradleProperty("SHOPKMP_RELEASE_SERVER_URL")
            .orElse(providers.environmentVariable("SHOPKMP_RELEASE_SERVER_URL"))
            .orNull
        val releaseRequested = gradle.startParameter.taskNames.any {
            it.contains("release", ignoreCase = true)
        }
        if (releaseRequested && configuredReleaseUrl.isNullOrBlank()) {
            error("SHOPKMP_RELEASE_SERVER_URL is required for release builds")
        }
        debug {
            buildConfigField("String", "SERVER_URL", "\"$configuredDebugUrl\"")
        }
        release {
            buildConfigField("String", "SERVER_URL", "\"${configuredReleaseUrl.orEmpty()}\"")
        }
    }
}
