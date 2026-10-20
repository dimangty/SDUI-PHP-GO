plugins {
    id("shopkmp.android-conventions") apply false
    alias(libs.plugins.kover)
    alias(libs.plugins.androidApplication) apply false
    alias(libs.plugins.androidLibrary) apply false
    alias(libs.plugins.composeMultiplatform) apply false
    alias(libs.plugins.composeCompiler) apply false
    alias(libs.plugins.kotlinMultiplatform) apply false
    alias(libs.plugins.serialization) apply false
    alias(libs.plugins.sqldelight) apply false
    alias(libs.plugins.detekt) apply false
}

dependencies {
    kover(project(":core:sdui-model"))
    kover(project(":core:sdui-network"))
    kover(project(":core:sdui-actions"))
    kover(project(":core:sdui-renderer"))
    kover(project(":core:common"))
    kover(project(":core:mvvm"))
    kover(project(":core:network"))
    kover(project(":core:settings"))
    kover(project(":feature:auth:auth-data"))
    kover(project(":feature:auth:auth-ui"))
    kover(project(":feature:home:home-data"))
    kover(project(":feature:home:home-domain"))
    kover(project(":feature:main"))
    kover(project(":feature:profile:data"))
}

kover {
    reports {
        filters {
            excludes {
                classes(
                    "*.BuildConfig",
                    "*.Di*",
                    "*.*Module*",
                    "*.ComposableSingletons*",
                    "*.*ScreenKt",
                    // Android Keystore/BiometricPrompt adapters require
                    // instrumentation; portable migration, recovery and
                    // fail-closed policy remain in this JVM coverage gate.
                    "com.shopkmp.common.biometric.AndroidBiometricKeyService*",
                    "com.shopkmp.settings.TinkSettings*",
                )
                annotatedBy("androidx.compose.runtime.Composable")
            }
        }
        verify {
            rule("KMP business logic line coverage") {
                // Security-sensitive auth/PIN/startup state machines and
                // repository/network flows remain in the aggregate.
                minBound(70)
            }
        }
    }
}

subprojects {
    apply(plugin = "shopkmp.android-conventions")
    pluginManager.withPlugin("io.gitlab.arturbosch.detekt") {
        dependencies.add("detektPlugins", libs.detekt.formatting)
    }
}
