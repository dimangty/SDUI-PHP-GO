package com.shopkmp.common.biometric

import android.app.Activity
import android.content.Context
import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.security.keystore.StrongBoxUnavailableException
import android.util.Base64
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.fragment.app.FragmentActivity
import java.lang.ref.WeakReference
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.PrivateKey
import java.security.Signature
import java.security.spec.ECGenParameterSpec
import java.util.concurrent.Executor
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext

private const val KEY_ALIAS = "shopkmp.biometric.login.es256"

/** The app calls this from its foreground Activity lifecycle. */
fun registerBiometricActivity(activity: Activity?) {
    AndroidBiometricActivityHolder.activity = WeakReference(activity)
}

private object AndroidBiometricActivityHolder {
    var activity: WeakReference<Activity?> = WeakReference(null)
}

private class AndroidBiometricKeyService(
    private val context: Context,
) : BiometricKeyService {
    override fun isAvailable(): Boolean =
        BiometricManager.from(context).canAuthenticate(BiometricManager.Authenticators.BIOMETRIC_STRONG) ==
            BiometricManager.BIOMETRIC_SUCCESS

    override suspend fun createKey(reason: String): BiometricPublicKey {
        if (!isAvailable()) throw BiometricUnavailableException("На устройстве нет настроенной биометрии")
        deleteKey()
        val pair = try {
            generateKeyPair(useStrongBox = Build.VERSION.SDK_INT >= Build.VERSION_CODES.P)
        } catch (_: StrongBoxUnavailableException) {
            generateKeyPair(useStrongBox = false)
        }
        // Prove that the newly created private key is actually unlocked by the
        // system biometric prompt before its public half reaches the backend.
        authenticateAndSign("shopkmp-enrollment".encodeToByteArray(), reason)
        return BiometricPublicKey(
            spkiBase64 = Base64.encodeToString(pair.public.encoded, Base64.NO_WRAP),
        )
    }

    override suspend fun sign(challenge: String, reason: String): String =
        Base64.encodeToString(
            authenticateAndSign(challenge.encodeToByteArray(), reason),
            Base64.NO_WRAP,
        )

    override fun deleteKey() {
        val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        if (keyStore.containsAlias(KEY_ALIAS)) keyStore.deleteEntry(KEY_ALIAS)
    }

    private fun generateKeyPair(useStrongBox: Boolean): java.security.KeyPair {
        val builder = KeyGenParameterSpec.Builder(KEY_ALIAS, KeyProperties.PURPOSE_SIGN)
            .setAlgorithmParameterSpec(ECGenParameterSpec("secp256r1"))
            .setDigests(KeyProperties.DIGEST_SHA256)
            .setUserAuthenticationRequired(true)
            .setInvalidatedByBiometricEnrollment(true)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            builder.setUserAuthenticationParameters(0, KeyProperties.AUTH_BIOMETRIC_STRONG)
        } else {
            @Suppress("DEPRECATION")
            builder.setUserAuthenticationValidityDurationSeconds(-1)
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            builder.setIsStrongBoxBacked(useStrongBox)
        }
        return KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, "AndroidKeyStore").run {
            initialize(builder.build())
            generateKeyPair()
        }
    }

    private suspend fun authenticateAndSign(payload: ByteArray, reason: String): ByteArray =
        withContext(Dispatchers.Main) {
            val activity = AndroidBiometricActivityHolder.activity.get() as? FragmentActivity
                ?: throw BiometricUnavailableException("Экран приложения не активен")
            val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
            val privateKey = keyStore.getKey(KEY_ALIAS, null) as? PrivateKey
                ?: throw BiometricUnavailableException("Биометрический ключ не найден")
            val signature = Signature.getInstance("SHA256withECDSA").apply { initSign(privateKey) }
            suspendCancellableCoroutine { continuation ->
                val executor = Executor { command -> activity.runOnUiThread(command) }
                val prompt = BiometricPrompt(
                    activity,
                    executor,
                    object : BiometricPrompt.AuthenticationCallback() {
                        override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                            try {
                                val unlocked = result.cryptoObject?.signature
                                    ?: throw BiometricUnavailableException("Системный ключ не разблокирован")
                                unlocked.update(payload)
                                continuation.resume(unlocked.sign())
                            } catch (error: Throwable) {
                                continuation.resumeWithException(error)
                            }
                        }

                        override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                            continuation.resumeWithException(BiometricCancelledException(errString.toString()))
                        }
                    },
                )
                continuation.invokeOnCancellation { prompt.cancelAuthentication() }
                prompt.authenticate(
                    BiometricPrompt.PromptInfo.Builder()
                        .setTitle("ShopKMP")
                        .setSubtitle(reason)
                        .setAllowedAuthenticators(BiometricManager.Authenticators.BIOMETRIC_STRONG)
                        .setNegativeButtonText("Отмена")
                        .build(),
                    BiometricPrompt.CryptoObject(signature),
                )
            }
        }

    private fun String.encodeToByteArray(): ByteArray = toByteArray(Charsets.UTF_8)
}

actual fun createPlatformBiometricKeyService(context: Any?): BiometricKeyService =
    AndroidBiometricKeyService(context as Context)
