plugins {
    alias(libs.plugins.kotlinMultiplatform)
    alias(libs.plugins.androidLibrary)
    alias(libs.plugins.kover)
}

kotlin {
    androidTarget()
    listOf(iosX64(), iosArm64(), iosSimulatorArm64()).forEach {
        it.binaries.framework { baseName = "common"; isStatic = true }
        if (it.name == "iosSimulatorArm64") {
            // Simulator SecTask reads the embedded section, not macOS codesign entitlements.
            it.binaries.getTest(org.jetbrains.kotlin.gradle.plugin.mpp.NativeBuildType.DEBUG).linkerOpts(
                "-sectcreate", "__TEXT", "__entitlements",
                file("src/iosTest/NativeSecurityTests.entitlements").absolutePath,
            )
        }
    }
    sourceSets {
        androidMain.dependencies {
            implementation("androidx.biometric:biometric:1.1.0")
        }
        commonMain.dependencies {
            implementation(project(":core:remoteresult"))
            implementation(libs.kotlinx.coroutines.core)
            implementation(libs.kotlinx.serialization)
            implementation(libs.kotlinx.datetime)
            implementation(libs.koin.core)
            implementation(libs.compose.components.resources)
        }
        commonTest.dependencies {
            implementation(libs.kotlin.test)
            implementation(libs.kotlinx.coroutines.test)
        }
    }
}

android {
    namespace = "com.shopkmp.common"
}
