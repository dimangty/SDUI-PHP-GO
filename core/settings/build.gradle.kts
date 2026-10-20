plugins {
    alias(libs.plugins.kotlinMultiplatform)
    alias(libs.plugins.androidLibrary)
    alias(libs.plugins.detekt)
    alias(libs.plugins.kover)
}

kotlin {
    androidTarget()
    listOf(iosX64(), iosArm64(), iosSimulatorArm64()).forEach {
        it.binaries.framework { baseName = "settings"; isStatic = true }
        if (it.name == "iosSimulatorArm64") {
            it.binaries.getTest(org.jetbrains.kotlin.gradle.plugin.mpp.NativeBuildType.DEBUG).linkerOpts(
                "-sectcreate", "__TEXT", "__entitlements",
                project(":core:common").file("src/iosTest/NativeSecurityTests.entitlements").absolutePath,
            )
        }
    }
    sourceSets {
        androidMain.dependencies {
            implementation(libs.koin.android)
            implementation(libs.androidx.security.crypto)
            implementation(libs.tink.android)
            implementation(libs.napier)
        }
        commonMain.dependencies {
            implementation(project(":core:common"))
            implementation(libs.multiplatform.settings)
            implementation(libs.koin.core)
        }
        commonTest.dependencies {
            implementation(libs.kotlin.test)
        }
    }
}

android {
    namespace = "com.shopkmp.settings"
}
