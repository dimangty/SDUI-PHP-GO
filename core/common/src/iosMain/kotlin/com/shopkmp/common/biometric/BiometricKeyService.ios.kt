@file:OptIn(
    kotlinx.cinterop.BetaInteropApi::class,
    kotlinx.cinterop.ExperimentalForeignApi::class,
)

package com.shopkmp.common.biometric

import com.shopkmp.common.security.copyBytes
import com.shopkmp.common.security.useCF
import com.shopkmp.common.security.withCFData
import com.shopkmp.common.security.withCFDictionary
import kotlinx.cinterop.CPointer
import kotlinx.cinterop.alloc
import kotlinx.cinterop.addressOf
import kotlinx.cinterop.memScoped
import kotlinx.cinterop.ptr
import kotlinx.cinterop.reinterpret
import kotlinx.cinterop.usePinned
import kotlinx.cinterop.value
import kotlinx.coroutines.suspendCancellableCoroutine
import platform.CoreFoundation.CFDictionaryRef
import platform.CoreFoundation.CFDataRef
import platform.CoreFoundation.CFErrorRefVar
import platform.CoreFoundation.CFRelease
import platform.CoreFoundation.CFStringRef
import platform.CoreFoundation.CFTypeRefVar
import platform.LocalAuthentication.LAContext
import platform.LocalAuthentication.LAPolicyDeviceOwnerAuthenticationWithBiometrics
import platform.Foundation.NSData
import platform.Foundation.base64EncodedStringWithOptions
import platform.Foundation.create
import platform.Security.SecAccessControlCreateWithFlags
import platform.Security.SecAccessControlRef
import platform.Security.SecItemCopyMatching
import platform.Security.SecItemDelete
import platform.Security.SecKeyCopyExternalRepresentation
import platform.Security.SecKeyCopyPublicKey
import platform.Security.SecKeyCreateRandomKey
import platform.Security.SecKeyCreateSignature
import platform.Security.SecKeyRef
import platform.Security.errSecSuccess
import platform.Security.kSecAccessControlBiometryCurrentSet
import platform.Security.kSecAccessControlPrivateKeyUsage
import platform.Security.kSecAttrAccessControl
import platform.Security.kSecAttrApplicationTag
import platform.Security.kSecAttrIsPermanent
import platform.Security.kSecAttrKeyClass
import platform.Security.kSecAttrKeyClassPrivate
import platform.Security.kSecAttrKeySizeInBits
import platform.Security.kSecAttrKeyType
import platform.Security.kSecAttrKeyTypeECSECPrimeRandom
import platform.Security.kSecAttrTokenID
import platform.Security.kSecAttrTokenIDSecureEnclave
import platform.Security.kSecClass
import platform.Security.kSecClassKey
import platform.Security.kSecMatchLimit
import platform.Security.kSecMatchLimitOne
import platform.Security.kSecPrivateKeyAttrs
import platform.Security.kSecReturnRef
import platform.Security.kSecKeyAlgorithmECDSASignatureMessageX962SHA256
import platform.Security.kSecUseAuthenticationContext
import platform.Security.kSecAttrAccessibleWhenUnlockedThisDeviceOnly
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

private const val KEY_TAG = "com.shopkmp.biometric.login.es256"

internal class IosBiometricKeyService(
    private val keyTag: String = KEY_TAG,
) : BiometricKeyService {
    override fun isAvailable(): Boolean = memScoped {
        val error = alloc<kotlinx.cinterop.ObjCObjectVar<platform.Foundation.NSError?>>()
        LAContext().canEvaluatePolicy(
            LAPolicyDeviceOwnerAuthenticationWithBiometrics,
            error.ptr,
        )
    }

    override suspend fun createKey(reason: String): BiometricPublicKey {
        if (!isAvailable()) throw BiometricUnavailableException("На устройстве нет настроенной биометрии")
        deleteKey()
        val accessControl = withSecurityError { error ->
            SecAccessControlCreateWithFlags(
                null,
                kSecAttrAccessibleWhenUnlockedThisDeviceOnly,
                kSecAccessControlBiometryCurrentSet or kSecAccessControlPrivateKeyUsage,
                error,
            ) ?: throw BiometricUnavailableException("Не удалось защитить ключ Secure Enclave")
        }
        val privateKey = accessControl.useCF {
            withSecurityError { error ->
                withBiometricKeyAttributes(keyTag, accessControl) { SecKeyCreateRandomKey(it, error) }
                    ?: throw BiometricUnavailableException("Не удалось создать ключ Secure Enclave")
            }
        }
        return privateKey.useCF {
            // Force a real biometric check before enrolling the public key.
            findPrivateKey(authenticate(reason)).useCF { enrollmentKey ->
                signWithKey(enrollmentKey, "shopkmp-enrollment").useCF { /* proof only */ }
            }
            val publicKey = SecKeyCopyPublicKey(privateKey)
                ?: throw BiometricUnavailableException("Публичный ключ недоступен")
            publicKey.useCF {
                val external = withSecurityError { error ->
                    SecKeyCopyExternalRepresentation(publicKey, error)
                        ?: throw BiometricUnavailableException("Не удалось экспортировать публичный ключ")
                }
                external.useCF { BiometricPublicKey(encodeP256SubjectPublicKeyInfo(it.copyBytes())) }
            }
        }
    }

    override suspend fun sign(challenge: String, reason: String): String {
        val context = authenticate(reason)
        return findPrivateKey(context).useCF { key ->
            signWithKey(key, challenge).useCF { it.copyBytes().encodeBase64() }
        }
    }

    override fun deleteKey() {
        withBiometricKeyQuery(keyTag) { SecItemDelete(it) }
    }

    private suspend fun authenticate(reason: String): LAContext =
        suspendCancellableCoroutine { continuation ->
            val context = LAContext()
            context.localizedCancelTitle = "Отмена"
            context.evaluatePolicy(
                LAPolicyDeviceOwnerAuthenticationWithBiometrics,
                localizedReason = reason,
            ) { success, error ->
                if (success) continuation.resume(context)
                else continuation.resumeWithException(
                    BiometricCancelledException(error?.localizedDescription ?: "Проверка отменена"),
                )
            }
            continuation.invokeOnCancellation { context.invalidate() }
        }

    private fun findPrivateKey(context: LAContext): SecKeyRef = memScoped {
        val result = alloc<CFTypeRefVar>()
        result.value = null
        val status = withBiometricKeyQuery(keyTag, context) { SecItemCopyMatching(it, result.ptr) }
        if (status != errSecSuccess) {
            throw BiometricUnavailableException("Биометрический ключ не найден")
        }
        checkNotNull(result.value).reinterpret()
    }

    private fun signWithKey(key: SecKeyRef, challenge: String): CFDataRef =
        withCFData(challenge.encodeToByteArray()) { data ->
            withSecurityError { error ->
                SecKeyCreateSignature(
                    key,
                    kSecKeyAlgorithmECDSASignatureMessageX962SHA256,
                    data,
                    error,
                ) ?: throw BiometricUnavailableException("Не удалось подписать challenge")
            }
        }
}

internal fun <R> withBiometricKeyAttributes(
    keyTag: String,
    accessControl: SecAccessControlRef,
    block: (CFDictionaryRef) -> R,
): R = withCFData(keyTag.encodeToByteArray()) { tag ->
    withCFDictionary(
        kSecAttrIsPermanent to true,
        kSecAttrApplicationTag to tag,
        kSecAttrAccessControl to accessControl,
    ) { privateAttributes ->
        withCFDictionary(
            kSecAttrKeyType to kSecAttrKeyTypeECSECPrimeRandom!!,
            kSecAttrKeySizeInBits to 256,
            kSecAttrTokenID to kSecAttrTokenIDSecureEnclave!!,
            kSecPrivateKeyAttrs to privateAttributes,
            block = block,
        )
    }
}

internal fun <R> withBiometricKeyQuery(
    keyTag: String,
    context: LAContext? = null,
    block: (CFDictionaryRef) -> R,
): R = withCFData(keyTag.encodeToByteArray()) { tag ->
    val query = mutableListOf<Pair<CFStringRef?, Any>>(
        kSecClass to kSecClassKey!!,
        kSecAttrKeyType to kSecAttrKeyTypeECSECPrimeRandom!!,
        kSecAttrApplicationTag to tag,
    )
    if (context != null) {
        query += listOf(
            kSecAttrKeyClass to kSecAttrKeyClassPrivate!!,
            kSecReturnRef to true,
            kSecMatchLimit to kSecMatchLimitOne!!,
            kSecUseAuthenticationContext to context,
        )
    }
    withCFDictionary(*query.toTypedArray(), block = block)
}

private fun <R> withSecurityError(block: (CPointer<CFErrorRefVar>) -> R): R = memScoped {
    val error = alloc<CFErrorRefVar>()
    error.value = null
    try {
        block(error.ptr)
    } finally {
        error.value?.let { CFRelease(it) }
    }
}

internal fun encodeP256SubjectPublicKeyInfo(x963: ByteArray): String {
    require(x963.size == 65 && x963.first() == 0x04.toByte()) {
        "P-256 public key must be a 65-byte uncompressed X9.63 point"
    }
    // RFC 5480 SubjectPublicKeyInfo: id-ecPublicKey + prime256v1 followed by
    // the uncompressed point exported by SecKeyCopyExternalRepresentation.
    val spkiHeader = byteArrayOf(
        0x30, 0x59, 0x30, 0x13, 0x06, 0x07, 0x2A, 0x86.toByte(),
        0x48, 0xCE.toByte(), 0x3D, 0x02, 0x01, 0x06, 0x08, 0x2A,
        0x86.toByte(), 0x48, 0xCE.toByte(), 0x3D, 0x03, 0x01, 0x07,
        0x03, 0x42, 0x00,
    )
    return (spkiHeader + x963).toNSData().base64EncodedStringWithOptions(0u)
}

private fun ByteArray.toNSData(): NSData =
    usePinned { pinned ->
        NSData.create(bytes = pinned.addressOf(0), length = size.toULong())
    }

private fun ByteArray.encodeBase64(): String =
    toNSData().base64EncodedStringWithOptions(0u)

actual fun createPlatformBiometricKeyService(context: Any?): BiometricKeyService =
    IosBiometricKeyService()
