# Kotlin serialization generates serializers referenced by metadata.
-keepattributes *Annotation*, InnerClasses
-if @kotlinx.serialization.Serializable class **
-keepclassmembers class <1> { static <1>$$serializer INSTANCE; }
-keep,includedescriptorclasses class com.shopkmp.network.**$$serializer { *; }

# Koin resolves constructors through generated definitions and lambdas.
-keep class org.koin.** { *; }
-dontwarn org.koin.**

# Tink references Error Prone's compile-time-only annotations. They are not
# needed at runtime and are intentionally absent from the APK.
-dontwarn com.google.errorprone.annotations.**

# Preserve source/line data for deobfuscated crash reporting while R8 still
# removes and renames release code.
-keepattributes SourceFile,LineNumberTable
