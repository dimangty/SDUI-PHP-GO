plugins {
    alias(libs.plugins.kotlinMultiplatform)
    alias(libs.plugins.androidLibrary)
    alias(libs.plugins.composeMultiplatform)
    alias(libs.plugins.composeCompiler)
    alias(libs.plugins.kover)
}

kotlin {
    androidTarget()
    listOf(iosX64(), iosArm64(), iosSimulatorArm64()).forEach {
        it.binaries.framework { baseName = "auth-ui"; isStatic = true }
    }
    sourceSets {
        commonMain.dependencies {
            implementation(project(":core:common"))
            implementation(project(":core:mvvm"))
            implementation(project(":core:navigation"))
            implementation(project(":core:uikit"))
            implementation(project(":core:remoteresult"))
            implementation(project(":feature:auth:auth-data"))
            implementation(project(":feature:auth:auth-domain"))
            implementation(libs.bundles.compose)
            implementation(libs.bundles.nav3)
            implementation(libs.bundles.koin.compose)
            implementation(libs.androidx.lifecycle.viewmodelCompose)
        }
        commonTest.dependencies {
            implementation(libs.kotlin.test)
            implementation(libs.kotlinx.coroutines.test)
            implementation(libs.koin.core)
        }
        iosTest.dependencies {
            implementation(libs.compose.ui.test)
        }
    }
}

android {
    namespace = "com.shopkmp.auth.ui"
}
