plugins {
    alias(libs.plugins.kotlinMultiplatform)
    alias(libs.plugins.androidLibrary)
    alias(libs.plugins.composeMultiplatform)
    alias(libs.plugins.composeCompiler)
    alias(libs.plugins.detekt)
    alias(libs.plugins.kover)
    alias(libs.plugins.serialization)
}

kotlin {
    androidTarget()
    listOf(iosX64(), iosArm64(), iosSimulatorArm64()).forEach {
        it.binaries.framework { baseName = "main"; isStatic = true }
    }
    sourceSets {
        commonMain.dependencies {
            implementation(project(":core:sdui-actions"))
            implementation(project(":core:sdui-network"))
            implementation(project(":core:sdui-renderer"))
            implementation(project(":core:network"))
            implementation(project(":core:common"))
            implementation(project(":core:mvvm"))
            implementation(project(":core:navigation"))
            implementation(project(":core:uikit"))
            implementation(project(":core:remoteresult"))
            implementation(project(":feature:auth:auth-domain"))
            implementation(project(":feature:auth:auth-ui"))
            implementation(project(":feature:home:home-domain"))
            implementation(project(":feature:profile:domain"))
            implementation(project(":feature:home:home-ui"))
            implementation(project(":feature:catalog:catalog-ui"))
            implementation(project(":feature:cart:cart-ui"))
            implementation(project(":feature:product:product-ui"))
            implementation(project(":feature:profile:profile-ui"))
            implementation(project(":feature:orders:orders-ui"))
            implementation(project(":feature:devices:devices-ui"))
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
    }
}

android {
    namespace = "com.shopkmp.main"
}
