rootProject.name = "ShopKMP"
enableFeaturePreview("TYPESAFE_PROJECT_ACCESSORS")

pluginManagement {
    includeBuild("build-logic")
    repositories {
        google {
            mavenContent {
                includeGroupAndSubgroups("androidx")
                includeGroupAndSubgroups("com.android")
                includeGroupAndSubgroups("com.google")
            }
        }
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    repositories {
        google {
            mavenContent {
                includeGroupAndSubgroups("androidx")
                includeGroupAndSubgroups("com.android")
                includeGroupAndSubgroups("com.google")
            }
        }
        mavenCentral()
    }
}

include(":composeApp")

include(":core:common")
include(":core:mvvm")
include(":core:navigation")
include(":core:network")
include(":core:remoteresult")
include(":core:uikit")
include(":core:settings")
include(":core:sdui-model")
include(":core:sdui-network")
include(":core:sdui-actions")
include(":core:sdui-renderer")

include(":feature:main")
include(":feature:home:home-ui")
include(":feature:catalog:catalog-ui")
include(":feature:cart:cart-ui")
include(":feature:product:product-ui")
include(":feature:profile:profile-ui")
include(":feature:orders:orders-ui")
include(":feature:devices:devices-ui")
include(":feature:home:home-data")
include(":feature:home:home-domain")
include(":feature:profile:data")
include(":feature:profile:domain")
include(":feature:auth:auth-data")
include(":feature:auth:auth-domain")
include(":feature:auth:auth-ui")
