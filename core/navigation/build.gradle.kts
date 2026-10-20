plugins {
    alias(libs.plugins.kotlinMultiplatform)
    alias(libs.plugins.androidLibrary)
    alias(libs.plugins.serialization)
}

kotlin {
    androidTarget()
    listOf(iosX64(), iosArm64(), iosSimulatorArm64()).forEach {
        it.binaries.framework { baseName = "navigation"; isStatic = true }
    }
    sourceSets {
        commonMain.dependencies {
            implementation(libs.bundles.nav3)
            implementation(libs.koin.core)
            implementation(libs.kotlinx.serialization)
            implementation(libs.kotlinx.coroutines.core)
        }
        commonTest.dependencies { implementation(libs.kotlin.test) }
    }
}

android {
    namespace = "com.shopkmp.navigation"
}
