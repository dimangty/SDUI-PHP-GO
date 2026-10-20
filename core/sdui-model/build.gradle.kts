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
        commonMain.dependencies { api(libs.kotlinx.serialization) }
        commonTest.dependencies { implementation(libs.kotlin.test) }
        commonTest { kotlin.srcDir(layout.buildDirectory.dir("generated/fixtures")) }
    }
}
android { namespace = "com.shopkmp.sdui.model" }
val generateFixtures by tasks.registering {
    val fixtures = rootProject.layout.projectDirectory.dir("backend-sdui/contracts/fixtures")
    inputs.dir(fixtures)
    val output = layout.buildDirectory.dir("generated/fixtures")
    outputs.dir(output)
    doLast {
        val target = output.get().file("ContractFixtures.kt").asFile
        target.parentFile.mkdirs()
        fun quoted(value: String) = "\"" + value.replace("\\", "\\\\").replace("\"", "\\\"")
            .replace("$", "\\$").replace("\n", "\\n").replace("\r", "\\r") + "\""
        target.writeText("package com.shopkmp.sdui.model\ninternal val contractFixtures = mapOf(\n" +
            fixtures.asFile.listFiles()!!.filter { it.extension == "json" }.sortedBy { it.name }
                .joinToString(",\n") { quoted(it.nameWithoutExtension) + " to " + quoted(it.readText()) } + "\n)\n")
    }
}
tasks.matching { it.name.contains("TestKotlin") || it.name.contains("UnitTestKotlin") }
    .configureEach { dependsOn(generateFixtures) }
