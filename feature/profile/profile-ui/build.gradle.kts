plugins {
    alias(libs.plugins.kotlinMultiplatform)
    alias(libs.plugins.androidLibrary)
    alias(libs.plugins.composeMultiplatform)
    alias(libs.plugins.composeCompiler)
    alias(libs.plugins.detekt)
    alias(libs.plugins.kover)
}

kotlin {
    androidTarget()
    listOf(iosX64(), iosArm64(), iosSimulatorArm64()).forEach {
        it.binaries.framework { baseName = "profile-ui"; isStatic = true }
    }
    sourceSets {
        commonMain.dependencies {
            implementation(project(":core:uikit"))
            implementation(project(":core:mvvm"))
            implementation(project(":feature:auth:auth-domain"))
            implementation(project(":feature:profile:domain"))
            implementation(libs.bundles.compose)
            implementation(libs.bundles.koin.compose)
            implementation(libs.androidx.lifecycle.viewmodelCompose)
            implementation(libs.kotlinx.coroutines.core)
        }
        commonTest.dependencies { implementation(libs.kotlin.test) }
    }
}

android { namespace = "com.shopkmp.profile.ui" }
