package com.shopkmp.sdui.renderer

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.size
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.scale
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.shopkmp.sdui.model.IconName

/** Local finite vector registry, no remote resource/class lookup. Coordinates are 24x24. */
@Composable
internal fun SduiIcon(name: IconName, description: String, modifier: Modifier = Modifier) {
    Canvas(modifier.size(24.dp).semantics { contentDescription = description }) {
        scale(size.width / 24f, size.height / 24f, pivot = androidx.compose.ui.geometry.Offset.Zero) {
            val path = Path().apply {
                when (name) {
                    IconName.Back -> { moveTo(15f, 4f); lineTo(7f, 12f); lineTo(15f, 20f) }
                    IconName.Check -> { moveTo(3f, 12f); lineTo(9f, 18f); lineTo(21f, 5f) }
                    IconName.Cart -> { moveTo(2f, 3f); lineTo(5f, 3f); lineTo(8f, 17f); lineTo(20f, 17f); moveTo(6f, 7f); lineTo(22f, 7f); lineTo(20f, 14f); lineTo(7f, 14f) }
                    IconName.Device -> { moveTo(7f, 2f); lineTo(17f, 2f); lineTo(17f, 22f); lineTo(7f, 22f); close(); moveTo(10f, 19f); lineTo(14f, 19f) }
                    IconName.Warning -> { moveTo(12f, 2f); lineTo(23f, 22f); lineTo(1f, 22f); close(); moveTo(12f, 8f); lineTo(12f, 15f); moveTo(12f, 17f); lineTo(12f, 19f) }
                    IconName.Search -> { addOval(androidx.compose.ui.geometry.Rect(3f, 3f, 16f, 16f)); moveTo(15f, 15f); lineTo(22f, 22f) }
                    IconName.Profile -> { addOval(androidx.compose.ui.geometry.Rect(8f, 2f, 16f, 10f)); moveTo(3f, 22f); cubicTo(3f, 9f, 21f, 9f, 21f, 22f) }
                }
            }
            drawPath(path, Color.DarkGray, style = Stroke(1.8f))
        }
    }
}
