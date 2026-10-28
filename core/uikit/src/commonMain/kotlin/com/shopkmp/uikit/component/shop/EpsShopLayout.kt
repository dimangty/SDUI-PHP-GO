package com.shopkmp.uikit.component.shop

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.avatar.EpsAvatar
import com.shopkmp.uikit.component.avatar.EpsAvatarState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButton
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.icon.EpsIcon
import com.shopkmp.uikit.component.icon.EpsIconName
import com.shopkmp.uikit.component.spacer.EpsSpacer
import com.shopkmp.uikit.component.spacer.EpsSpacerState
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextField
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState

data class EpsShopHeaderState(
    val title: String,
    val subtitle: String,
    val actionButtonState: EpsButtonState? = null,
)

@Composable
fun EpsShopHeader(
    state: EpsShopHeaderState,
    modifier: Modifier = Modifier,
) {
    Row(modifier = modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        androidx.compose.foundation.layout.Column(modifier = Modifier.weight(1f)) {
            EpsScreenTitle(state.title)
            EpsSecondaryText(state.subtitle)
        }
        state.actionButtonState?.let { EpsButton(it) }
    }
}

data class EpsSectionHeaderState(
    val title: String,
    val subtitle: String? = null,
    val actionButtonState: EpsButtonState? = null,
)

@Composable
fun EpsSectionHeader(
    state: EpsSectionHeaderState,
    modifier: Modifier = Modifier,
) {
    Row(modifier = modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        androidx.compose.foundation.layout.Column(modifier = Modifier.weight(1f)) {
            EpsSectionTitle(state.title)
            state.subtitle?.let { EpsSecondaryText(it) }
        }
        state.actionButtonState?.let { EpsButton(it) }
    }
}

data class EpsScreenHeaderState(
    val title: String,
    val backButtonState: EpsButtonState? = null,
    val actionButtonState: EpsButtonState? = null,
)

@Composable
fun EpsScreenHeader(
    state: EpsScreenHeaderState,
    modifier: Modifier = Modifier,
) {
    Row(modifier = modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        state.backButtonState?.let {
            EpsButton(it)
            EpsSpacer(EpsSpacerState.width(12.dp))
        }
        EpsScreenTitle(
            text = state.title,
            modifier = Modifier.weight(1f),
        )
        state.actionButtonState?.let { EpsButton(it) }
    }
}

data class EpsPaymentSelectorState(
    val title: String,
    val buttonStates: List<EpsButtonState>,
)

@Composable
fun EpsPaymentSelector(
    state: EpsPaymentSelectorState,
    modifier: Modifier = Modifier,
) {
    androidx.compose.foundation.layout.Column(modifier = modifier.fillMaxWidth()) {
        EpsSectionTitle(state.title)
        EpsSpacer(EpsSpacerState.height(8.dp))
        Row {
            state.buttonStates.forEachIndexed { index, buttonState ->
                if (index > 0) {
                    EpsSpacer(EpsSpacerState.width(8.dp))
                }
                EpsButton(buttonState)
            }
        }
    }
}

data class EpsCartTotalState(
    val totalText: String,
)

@Composable
fun EpsCartTotal(
    state: EpsCartTotalState,
    modifier: Modifier = Modifier,
) {
    EpsSectionTitle(
        text = "Итого: ${state.totalText}",
        modifier = modifier,
    )
}

data class EpsProfileActionState(
    val title: String,
    val cardState: EpsCardState,
)

data class EpsProfileActionsState(
    val actions: List<EpsProfileActionState>,
)

@Composable
fun EpsProfileActions(
    state: EpsProfileActionsState,
    modifier: Modifier = Modifier,
) {
    androidx.compose.foundation.layout.Column(modifier = modifier.fillMaxWidth()) {
        state.actions.forEachIndexed { index, action ->
            if (index > 0) {
                EpsSpacer(EpsSpacerState.height(10.dp))
            }
            EpsProfileAction(title = action.title, cardState = action.cardState)
        }
    }
}

data class EpsEditAvatarState(
    val initials: String,
    val actionText: String,
    val onChangePhoto: (() -> Unit)? = null,
)

@Composable
fun EpsEditAvatar(
    state: EpsEditAvatarState,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        EpsAvatar(EpsAvatarState(state.initials), size = 56.dp)
        androidx.compose.foundation.layout.Column(modifier = Modifier.weight(1f)) {
            EpsButton(
                EpsButtonState(
                    text = state.actionText,
                    type = EpsButtonType.Text,
                    enabled = state.onChangePhoto != null,
                    onClick = state.onChangePhoto,
                ),
                Modifier.testTag("editProfile.photo"),
            )
            if (state.onChangePhoto == null) EpsSecondaryText("Изменение фото пока недоступно")
        }
    }
}

@Composable
fun EpsProfileFormFields(
    nameTextFieldState: EpsTextFieldState,
    phoneTextFieldState: EpsTextFieldState,
    emailTextFieldState: EpsTextFieldState,
    addressTextFieldState: EpsTextFieldState,
    modifier: Modifier = Modifier,
    testTagPrefix: String = "",
) {
    androidx.compose.foundation.layout.Column(modifier = modifier.fillMaxWidth()) {
        fun fieldTag(name: String) = if (testTagPrefix.isBlank()) Modifier else Modifier.testTag("$testTagPrefix.$name")
        EpsTextField(nameTextFieldState, fieldTag("name"))
        EpsSpacer(EpsSpacerState.height(12.dp))
        EpsTextField(phoneTextFieldState, fieldTag("phone"))
        EpsSpacer(EpsSpacerState.height(12.dp))
        EpsTextField(emailTextFieldState, fieldTag("email"), trailingIcon = {
            if (emailTextFieldState.readOnly) EpsIcon(EpsIconName.Lock, "Email можно изменить через поддержку")
        })
        EpsSpacer(EpsSpacerState.height(12.dp))
        EpsTextField(addressTextFieldState, fieldTag("address"))
    }
}
