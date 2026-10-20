plugins { `kotlin-dsl` }

dependencies {
    implementation("com.android.tools.build:gradle:8.11.2")
    implementation("org.jetbrains.kotlin:kotlin-gradle-plugin:2.3.10")
}

gradlePlugin {
    plugins {
        register("shopKmpAndroidConvention") {
            id = "shopkmp.android-conventions"
            implementationClass = "ShopKmpAndroidConventionPlugin"
        }
    }
}
