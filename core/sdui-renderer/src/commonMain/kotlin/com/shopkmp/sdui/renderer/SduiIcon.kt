package com.shopkmp.sdui.renderer

import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.shopkmp.sdui.model.IconName
import com.shopkmp.uikit.component.icon.EpsIcon
import com.shopkmp.uikit.component.icon.EpsIconName

/** Local finite vector registry, no remote resource/class lookup. Coordinates are 24x24. */
@Composable
internal fun SduiIcon(name: IconName, description: String, modifier: Modifier = Modifier) {
    EpsIcon(when (name) {
        IconName.Search -> EpsIconName.Search; IconName.Cart -> EpsIconName.Cart
        IconName.Profile -> EpsIconName.Profile; IconName.Back -> EpsIconName.Back
        IconName.Check -> EpsIconName.Check; IconName.Device -> EpsIconName.Device
        IconName.Warning -> EpsIconName.Warning
    }, description, modifier)
}
