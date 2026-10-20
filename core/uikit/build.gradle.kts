plugins {
    alias(libs.plugins.kotlinMultiplatform)
    alias(libs.plugins.androidLibrary)
    alias(libs.plugins.composeMultiplatform)
    alias(libs.plugins.composeCompiler)
    alias(libs.plugins.detekt)

}

compose.resources {
    publicResClass = true
}

kotlin {
    androidTarget()
    listOf(iosX64(), iosArm64(), iosSimulatorArm64()).forEach {
        it.binaries.framework { baseName = "uikit"; isStatic = true }
    }
    sourceSets {
        commonMain.dependencies {
            implementation(libs.bundles.compose)
            implementation(libs.coil.compose)
            implementation(libs.coil.compose.network)
            implementation(libs.koin.core)
        }
    }
}

android {
    namespace = "com.shopkmp.uikit"
}
