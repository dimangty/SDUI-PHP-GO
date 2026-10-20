package com.shopkmp.profile.ui

import com.shopkmp.profile.domain.CustomerProfile
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.shop.EpsProfileActionState
import com.shopkmp.uikit.component.shop.EpsProfileActionsState
import com.shopkmp.uikit.component.shop.EpsProfileContentState
import com.shopkmp.uikit.component.shop.EpsProfileFieldState
import com.shopkmp.uikit.component.shop.EpsProfileHeaderState
import com.shopkmp.uikit.component.shop.EpsScreenHeaderState

internal class ProfileStateBuilder {
    @Suppress("LongParameterList")
    fun build(
        profile: CustomerProfile,
        biometricAvailable: Boolean,
        biometricConfigured: Boolean,
        onEdit: () -> Unit,
        onOrders: () -> Unit,
        onDevices: () -> Unit,
        onChangePin: () -> Unit,
        onBiometric: () -> Unit,
        onLogout: () -> Unit,
    ) = EpsProfileContentState(
        headerState = EpsScreenHeaderState(
            title = "Профиль",
            actionButtonState = secondaryButton("Редактировать", onEdit),
        ),
        profileHeaderState = EpsProfileHeaderState(
            initials = profile.fullName.initials(),
            name = profile.fullName,
            email = profile.email,
        ),
        phoneFieldState = EpsProfileFieldState("Телефон", profile.phone),
        addressFieldState = EpsProfileFieldState("Адрес доставки", profile.defaultAddress),
        orderHistoryButtonState = secondaryButton("Мои заказы", onOrders),
        actionsState = EpsProfileActionsState(
            actions = buildList {
                add(EpsProfileActionState("Сменить PIN-код", EpsCardState(onClick = onChangePin)))
                add(EpsProfileActionState("Мои устройства", EpsCardState(onClick = onDevices)))
                if (biometricAvailable) {
                    add(
                        EpsProfileActionState(
                            if (biometricConfigured) {
                                "Отключить вход по биометрии"
                            } else {
                                "Включить вход по биометрии"
                            },
                            EpsCardState(onClick = onBiometric),
                        ),
                    )
                }
                add(EpsProfileActionState("Уведомления", EpsCardState()))
            },
        ),
        logoutButtonState = secondaryButton("Выйти из аккаунта", onLogout),
    )

    private fun secondaryButton(text: String, onClick: () -> Unit) = EpsButtonState(
        text = text,
        type = EpsButtonType.Secondary,
        onClick = onClick,
    )

    private fun String.initials() = trim().split(" ")
        .filter { it.isNotBlank() }
        .take(2)
        .mapNotNull { it.firstOrNull()?.uppercaseChar()?.toString() }
        .joinToString("")
        .ifBlank { "SK" }
}
