package com.shopkmp.uikit.component.pin

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.Surface
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.unit.dp
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import com.shopkmp.ui.theme.ShopTokens
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.icon.EpsIcon
import com.shopkmp.uikit.component.icon.EpsIconName
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState
import com.shopkmp.uikit.component.text.EpsTextStyle

@Composable
fun EpsPinDots(
    count: Int,
    maxCount: Int = 4,
) {
    // Four slots initially; five/six-digit credentials must never be visually truncated.
    val visibleCount = maxOf(maxCount, count).coerceIn(4, 6)
    Row(
        horizontalArrangement = Arrangement.spacedBy(16.dp),
        modifier = Modifier.semantics {
            contentDescription = "PIN-код"
            stateDescription = "Введено цифр: $count"
        },
    ) {
        repeat(visibleCount) { index ->
            Box(
                Modifier.size(18.dp)
                    .border(2.dp, ShopTokens.Primary, CircleShape)
                    .then(if (index < count) Modifier.background(ShopTokens.Primary, CircleShape) else Modifier),
            )
        }
    }
}

@Composable
fun EpsPinKeypad(state: EpsPinKeypadState) {
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        listOf(
            listOf(1, 2, 3),
            listOf(4, 5, 6),
            listOf(7, 8, 9),
        ).forEach { rowDigits ->
            Row(horizontalArrangement = Arrangement.spacedBy(20.dp)) {
                rowDigits.forEach { digit ->
                    val button = state.digitButtonStates.getValue(digit)
                    PinKey(button, Modifier.testTag("pin.digit.$digit")) {
                        EpsText(EpsTextState(button.text, style = EpsTextStyle.Title))
                    }
                }
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(20.dp), verticalAlignment = Alignment.CenterVertically) {
            if (state.showLockAction) {
                Surface(
                    modifier = Modifier.size(64.dp),
                    shape = CircleShape,
                    color = ShopTokens.Field,
                    border = BorderStroke(1.dp, ShopTokens.Border),
                ) {
                    Box(contentAlignment = Alignment.Center) {
                        EpsIcon(EpsIconName.Lock, "Защищённый вход по PIN-коду", tint = ShopTokens.SecondaryText)
                    }
                }
            } else {
                Spacer(modifier = Modifier.size(64.dp))
            }
            val zero = state.digitButtonStates.getValue(0)
            PinKey(zero, Modifier.testTag("pin.digit.0")) {
                EpsText(EpsTextState(zero.text, style = EpsTextStyle.Title))
            }
            PinKey(state.backspaceButtonState, Modifier.testTag("pin.backspace")) {
                EpsIcon(EpsIconName.Backspace, "Удалить последнюю цифру")
            }
        }
    }
}

@Composable
private fun PinKey(state: EpsButtonState, modifier: Modifier, content: @Composable () -> Unit) {
    Surface(
        onClick = { state.onClick?.invoke() },
        enabled = state.enabled,
        modifier = modifier.size(64.dp),
        shape = CircleShape,
        color = ShopTokens.Field,
        contentColor = if (state.enabled) ShopTokens.Text else ShopTokens.SecondaryText,
        border = BorderStroke(1.dp, ShopTokens.Border),
    ) {
        Box(contentAlignment = Alignment.Center) { content() }
    }
}
