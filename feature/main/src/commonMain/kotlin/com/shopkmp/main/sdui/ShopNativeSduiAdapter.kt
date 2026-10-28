package com.shopkmp.main.sdui

import com.shopkmp.auth.domain.AuthService
import com.shopkmp.common.device.DeviceService
import com.shopkmp.common.biometric.BiometricKeyService
import com.shopkmp.home.domain.CheckoutUseCase
import com.shopkmp.navigation.NavigationAction
import com.shopkmp.navigation.navigationservice.AppNavigator
import com.shopkmp.network.BackendSession
import com.shopkmp.network.NetworkSettings
import com.shopkmp.sdui.actions.CheckoutInput
import com.shopkmp.sdui.actions.PaymentUrlPolicy
import com.shopkmp.sdui.model.NativeKind
import com.shopkmp.sdui.model.Command
import com.shopkmp.sdui.model.DeviceKind

class ShopNativeSduiAdapter(
    private val auth: AuthService,
    private val session: BackendSession,
    checkout: CheckoutUseCase,
    private val device: DeviceService,
    private val navigator: AppNavigator,
    settings: NetworkSettings,
    private val biometricKeys: BiometricKeyService,
) : NativeSduiAdapter {
    private val nativeCheckout = NativeCheckoutAdapter(checkout, PaymentUrlPolicy(settings.serverUrl, settings.paymentOrigins), device::openUrl)
    override val accountChanges get() = session.accountIdFlow
    override fun accountId() = session.userId
    override fun deviceId() = session.ensureDeviceId()
    override fun isBiometricAvailable() = auth.isBiometricAvailable()
    override fun hasConfiguredBiometric() = auth.hasConfiguredBiometric()
    override fun authenticationLost() { navigator.replaceAll(NavigationAction.NavigateToLogin) }
    override fun commandApplied(command: Command) {
        if (command is Command.RevokeDevice && command.input.deviceId == session.ensureDeviceId()) {
            when (command.input.deviceKind) {
                DeviceKind.pin -> session.clearPin()
                DeviceKind.biometric -> { session.clearBiometric(); biometricKeys.deleteKey() }
            }
        }
    }
    override suspend fun execute(kind: NativeKind): NativeResult = when (kind) {
        NativeKind.EditProfile -> NativeResult.EditProfile
        NativeKind.Logout -> { auth.logout(); NativeResult.SignedOut }
        NativeKind.Login -> { navigator.replaceAll(NavigationAction.NavigateToLogin); NativeResult.Completed }
        NativeKind.ChangePin -> { navigator.push(NavigationAction.NavigateToChangePin); NativeResult.Completed }
        NativeKind.ManageBiometric -> {
            if (!auth.hasConfiguredBiometric() && !auth.isBiometricAvailable()) NativeResult.Failed("Биометрия недоступна на устройстве")
            else {
                val result = if (auth.hasConfiguredBiometric()) auth.disableBiometric() else auth.enableBiometric()
                if (result.isSuccess) NativeResult.Completed else NativeResult.Failed("Не удалось изменить настройки биометрии")
            }
        }
    }
    override suspend fun checkout(input: CheckoutInput): NativeResult = nativeCheckout.execute(input)
}
