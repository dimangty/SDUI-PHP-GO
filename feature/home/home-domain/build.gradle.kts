plugins {
    alias(libs.plugins.kotlinMultiplatform)
    alias(libs.plugins.kover)
    alias(libs.plugins.androidLibrary)
    alias(libs.plugins.detekt)

}

kotlin {
    androidTarget()
    listOf(iosX64(), iosArm64(), iosSimulatorArm64()).forEach {
        it.binaries.framework { baseName = "home-domain"; isStatic = true }
    }
    sourceSets {
        commonMain.dependencies {
            implementation(project(":core:common"))
            implementation(libs.koin.core)
        }
        commonTest.dependencies {
            implementation(libs.kotlin.test)
            implementation(libs.kotlinx.coroutines.test)
        }
    }
}

android {
    namespace = "com.shopkmp.home.domain"
}
