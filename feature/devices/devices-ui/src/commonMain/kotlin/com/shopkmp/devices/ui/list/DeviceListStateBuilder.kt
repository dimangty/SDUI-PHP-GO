package com.shopkmp.devices.ui.list

import com.shopkmp.auth.domain.AuthPinDevice
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.shop.EpsCartEmptyState
import com.shopkmp.uikit.component.shop.EpsDeviceListContentState
import com.shopkmp.uikit.component.shop.EpsDeviceRowState
import com.shopkmp.uikit.component.shop.EpsScreenHeaderState

internal class DeviceListStateBuilder {
    fun build(
        devices: List<AuthPinDevice>,
        onBack: () -> Unit,
        onRevoke: (String) -> Unit,
    ) = EpsDeviceListContentState(
        headerState = EpsScreenHeaderState(
            title = "Мои устройства",
            backButtonState = EpsButtonState(
                text = "Назад",
                type = EpsButtonType.Secondary,
                onClick = onBack,
            ),
        ),
        devices = devices.map { device ->
            EpsDeviceRowState(
                name = device.name,
                subtitle = "Последний вход: ${device.lastUsedText}",
                currentLabel = if (device.isCurrent) "Текущее" else null,
                cardState = EpsCardState(),
                revokeButtonState = EpsButtonState(
                    text = "Отключить PIN",
                    type = EpsButtonType.Secondary,
                    onClick = { onRevoke(device.id) },
                ),
            )
        },
        emptyState = EpsCartEmptyState(
            title = "PIN не настроен",
            subtitle = "Создайте PIN-код, чтобы входить быстрее на этом устройстве.",
            cardState = EpsCardState(),
        ),
    )
}
