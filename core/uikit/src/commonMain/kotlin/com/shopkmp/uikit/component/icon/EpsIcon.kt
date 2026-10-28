package com.shopkmp.uikit.component.icon

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.size
import androidx.compose.material3.LocalContentColor
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.scale
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp

enum class EpsIconName {
    Home, Catalog, Cart, Profile, Back, Search, Edit, Refresh, Lock, Backspace,
    ChevronRight, Notifications, Check, Device, Warning, Visibility, VisibilityOff,
}

/** Finite local vectors in a 24x24 viewport, following the renderer's Canvas pattern. */
@Composable
fun EpsIcon(
    name: EpsIconName,
    contentDescription: String?,
    modifier: Modifier = Modifier,
    tint: Color = LocalContentColor.current,
) {
    Canvas(
        modifier.size(24.dp).semantics {
            if (contentDescription != null) this.contentDescription = contentDescription
        },
    ) {
        scale(size.width / 24f, size.height / 24f, pivot = Offset.Zero) {
            val path = Path().apply {
                when (name) {
                    EpsIconName.Home -> {
                        moveTo(2f, 11f); lineTo(12f, 2f); lineTo(22f, 11f)
                        moveTo(5f, 10f); lineTo(5f, 22f); lineTo(10f, 22f)
                        lineTo(10f, 15f); lineTo(14f, 15f); lineTo(14f, 22f)
                        lineTo(19f, 22f); lineTo(19f, 10f)
                    }
                    EpsIconName.Catalog -> {
                        addRect(Rect(3f, 3f, 10f, 10f)); addRect(Rect(14f, 3f, 21f, 10f))
                        addRect(Rect(3f, 14f, 10f, 21f)); addRect(Rect(14f, 14f, 21f, 21f))
                    }
                    EpsIconName.Cart -> {
                        moveTo(2f, 3f); lineTo(5f, 3f); lineTo(8f, 17f); lineTo(20f, 17f)
                        moveTo(6f, 7f); lineTo(22f, 7f); lineTo(20f, 14f); lineTo(7f, 14f)
                        addOval(Rect(8f, 20f, 10f, 22f)); addOval(Rect(18f, 20f, 20f, 22f))
                    }
                    EpsIconName.Profile -> {
                        addOval(Rect(8f, 2f, 16f, 10f))
                        moveTo(3f, 22f); cubicTo(3f, 9f, 21f, 9f, 21f, 22f)
                    }
                    EpsIconName.Back -> { moveTo(15f, 4f); lineTo(7f, 12f); lineTo(15f, 20f) }
                    EpsIconName.ChevronRight -> { moveTo(9f, 4f); lineTo(17f, 12f); lineTo(9f, 20f) }
                    EpsIconName.Search -> {
                        addOval(Rect(3f, 3f, 16f, 16f)); moveTo(15f, 15f); lineTo(22f, 22f)
                    }
                    EpsIconName.Edit -> {
                        moveTo(3f, 21f); lineTo(5f, 14f); lineTo(17f, 2f); lineTo(22f, 7f)
                        lineTo(10f, 19f); close()
                        moveTo(14f, 5f); lineTo(19f, 10f)
                    }
                    EpsIconName.Refresh -> {
                        arcTo(Rect(3f, 3f, 21f, 21f), 35f, 300f, true)
                        moveTo(21f, 3f); lineTo(21f, 9f); lineTo(15f, 9f)
                    }
                    EpsIconName.Lock -> {
                        addRect(Rect(5f, 10f, 19f, 22f))
                        moveTo(8f, 10f); lineTo(8f, 6f); cubicTo(8f, 0f, 16f, 0f, 16f, 6f)
                        lineTo(16f, 10f); moveTo(12f, 15f); lineTo(12f, 18f)
                    }
                    EpsIconName.Backspace -> {
                        moveTo(9f, 4f); lineTo(22f, 4f); lineTo(22f, 20f); lineTo(9f, 20f)
                        lineTo(2f, 12f); close()
                        moveTo(12f, 8f); lineTo(18f, 16f); moveTo(18f, 8f); lineTo(12f, 16f)
                    }
                    EpsIconName.Notifications -> {
                        moveTo(4f, 18f); lineTo(6f, 14f); lineTo(6f, 9f)
                        cubicTo(6f, 1f, 18f, 1f, 18f, 9f); lineTo(18f, 14f); lineTo(20f, 18f); close()
                        moveTo(9f, 21f); cubicTo(10f, 23f, 14f, 23f, 15f, 21f)
                        moveTo(12f, 2f); lineTo(12f, 1f)
                    }
                    EpsIconName.Check -> { moveTo(3f, 12f); lineTo(9f, 18f); lineTo(21f, 5f) }
                    EpsIconName.Device -> {
                        addRect(Rect(6f, 2f, 18f, 22f)); moveTo(10f, 18f); lineTo(14f, 18f)
                    }
                    EpsIconName.Warning -> {
                        moveTo(12f, 2f); lineTo(23f, 22f); lineTo(1f, 22f); close()
                        moveTo(12f, 8f); lineTo(12f, 14f); moveTo(12f, 18f); lineTo(12f, 18.1f)
                    }
                    EpsIconName.Visibility, EpsIconName.VisibilityOff -> {
                        moveTo(2f, 12f); cubicTo(7f, 3f, 17f, 3f, 22f, 12f)
                        cubicTo(17f, 21f, 7f, 21f, 2f, 12f); close()
                        addOval(Rect(9f, 9f, 15f, 15f))
                        if (name == EpsIconName.VisibilityOff) { moveTo(3f, 2f); lineTo(21f, 22f) }
                    }
                }
            }
            drawPath(path, tint, style = Stroke(1.8f, cap = StrokeCap.Round, join = StrokeJoin.Round))
        }
    }
}
