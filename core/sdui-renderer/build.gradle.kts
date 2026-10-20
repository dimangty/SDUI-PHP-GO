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
    iosX64(); iosArm64(); iosSimulatorArm64()
    sourceSets {
        commonMain.dependencies {
            api(project(":core:sdui-model"))
            api(project(":core:sdui-actions"))
            implementation(project(":core:uikit"))
            implementation(libs.bundles.compose)
        }
        commonTest.dependencies { implementation(libs.kotlin.test) }
        iosTest.dependencies { implementation(libs.compose.ui.test) }
    }
}
android { namespace = "com.shopkmp.sdui.renderer" }
