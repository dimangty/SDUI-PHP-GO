package com.shopkmp.profile.ui

import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.text.input.KeyboardType
import com.shopkmp.profile.domain.CustomerProfile
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.shop.EpsEditAvatarState
import com.shopkmp.uikit.component.shop.EpsEditProfileContentState
import com.shopkmp.uikit.component.shop.EpsScreenHeaderState
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState

internal class EditProfileStateBuilder {
    @Suppress("LongParameterList")
    fun build(
        profile: CustomerProfile,
        name: String,
        phone: String,
        address: String,
        onBack: () -> Unit,
        onName: (String) -> Unit,
        onPhone: (String) -> Unit,
        onAddress: (String) -> Unit,
        onSave: () -> Unit,
        saving: Boolean = false,
    ) = EpsEditProfileContentState(
        headerState = EpsScreenHeaderState(
            title = "Редактирование",
            backButtonState = EpsButtonState(
                text = "Назад",
                type = EpsButtonType.Secondary,
                onClick = onBack,
            ),
        ),
        avatarState = EpsEditAvatarState(
            initials = name.take(2).uppercase().ifBlank { "SK" },
            actionText = "Изменить фото",
        ),
        nameTextFieldState = EpsTextFieldState(
            value = name,
            label = "Имя и фамилия",
            enabled = !saving,
            onValueChange = onName,
        ),
        phoneTextFieldState = EpsTextFieldState(
            value = phone,
            label = "Телефон",
            enabled = !saving,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
            onValueChange = onPhone,
        ),
        emailTextFieldState = EpsTextFieldState(
            value = profile.email,
            label = "Email (через поддержку)",
            enabled = true,
            readOnly = true,
        ),
        addressTextFieldState = EpsTextFieldState(
            value = address,
            label = "Адрес доставки по умолчанию",
            placeholder = "Город, улица, дом, квартира",
            enabled = !saving,
            onValueChange = onAddress,
        ),
        saveButtonState = EpsButtonState(
            text = "Сохранить изменения",
            enabled = !saving,
            onClick = onSave,
        ),
    )
}
