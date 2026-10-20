package com.shopkmp.uikit.preview

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import com.shopkmp.ui.theme.AppTheme
import com.shopkmp.uikit.component.button.defaultbutton.EpsButton
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonType
import com.shopkmp.uikit.component.error.EpsErrorMessage
import com.shopkmp.uikit.component.imageplaceholder.EpsImagePlaceholderState
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.quantity.EpsQuantityStepper
import com.shopkmp.uikit.component.quantity.EpsQuantityStepperState
import com.shopkmp.uikit.component.shop.EpsProductCard
import com.shopkmp.uikit.component.shop.EpsProductState
import com.shopkmp.uikit.component.shop.EpsSuccessMessage
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextField
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState

@Preview(name = "Controls", showBackground = true)
@Composable
private fun ControlsPreview() = AppTheme {
    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        EpsButton(EpsButtonState(text = "Основное действие"))
        EpsButton(EpsButtonState(text = "Вторичное", type = EpsButtonType.Secondary))
        EpsTextField(EpsTextFieldState(value = "phone@example.com", label = "Email"))
        EpsQuantityStepper(EpsQuantityStepperState(value = 2))
    }
}

@Preview(name = "Product", showBackground = true)
@Composable
private fun ProductPreview() = AppTheme {
    EpsProductCard(
        EpsProductState(
            name = "Беспроводные наушники", description = "Bluetooth 5.3", priceText = "4 990 ₽", ratingText = "4.8",
            imageState = EpsImagePlaceholderState(label = "🎧"),
            cardState = EpsCardState(),
        ),
        Modifier.padding(16.dp),
    )
}

@Preview(name = "Feedback", showBackground = true)
@Composable
private fun FeedbackPreview() = AppTheme {
    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        EpsSuccessMessage("Заказ создан")
        EpsErrorMessage("Не удалось загрузить каталог")
    }
}
