plugins {
    alias(libs.plugins.kotlinMultiplatform)
    alias(libs.plugins.androidLibrary)
    alias(libs.plugins.serialization)
    alias(libs.plugins.detekt)
    alias(libs.plugins.kover)
}
kotlin {
    androidTarget()
    iosX64(); iosArm64(); iosSimulatorArm64()
    sourceSets {
        commonMain.dependencies {
            api(project(":core:sdui-model"))
            implementation(libs.ktor.client.core)
        }
        commonTest.dependencies { implementation(libs.kotlin.test) }
    }
}
android { namespace = "com.shopkmp.sdui.actions" }
