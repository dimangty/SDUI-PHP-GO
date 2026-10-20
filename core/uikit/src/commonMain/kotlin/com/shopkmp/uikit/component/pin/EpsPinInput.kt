package com.shopkmp.uikit.component.pin

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.width
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.button.defaultbutton.EpsButton
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState
import com.shopkmp.uikit.component.text.EpsTextStyle

@Composable
fun EpsPinDots(
    count: Int,
    maxCount: Int = 4,
) {
    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        repeat(maxCount) { index ->
            EpsText(
                state = EpsTextState(
                    text = if (index < count) "●" else "○",
                    style = EpsTextStyle.PinDot,
                    color = if (index < count) Color(0xFF2F6FED) else Color(0xFFB0B7C3),
                ),
            )
        }
    }
}

@Composable
fun EpsPinKeypad(state: EpsPinKeypadState) {
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        listOf(
            listOf(1, 2, 3),
            listOf(4, 5, 6),
            listOf(7, 8, 9),
        ).forEach { rowDigits ->
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                rowDigits.forEach { digit ->
                    EpsButton(
                        state = state.digitButtonStates.getValue(digit),
                        modifier = Modifier.width(72.dp).testTag("pin.digit.$digit"),
                    )
                }
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
            if (state.showLockAction) {
                Box(modifier = Modifier.width(72.dp), contentAlignment = Alignment.Center) {
                    EpsText(EpsTextState("🔒", style = EpsTextStyle.Title))
                }
            } else {
                Spacer(modifier = Modifier.width(72.dp))
            }
            EpsButton(
                state = state.digitButtonStates.getValue(0),
                modifier = Modifier.width(72.dp).testTag("pin.digit.0"),
            )
            EpsButton(
                state = state.backspaceButtonState,
                modifier = Modifier.width(72.dp).testTag("pin.backspace"),
            )
        }
    }
}
