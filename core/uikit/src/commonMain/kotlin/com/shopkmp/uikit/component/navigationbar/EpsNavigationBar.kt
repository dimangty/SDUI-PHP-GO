package com.shopkmp.uikit.component.navigationbar

import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.graphics.Color
import androidx.compose.foundation.layout.Column
import androidx.compose.ui.unit.dp
import com.shopkmp.ui.theme.ShopTokens
import com.shopkmp.uikit.component.divider.EpsDivider
import com.shopkmp.uikit.component.divider.EpsDividerState
import com.shopkmp.uikit.component.icon.EpsIcon
import com.shopkmp.uikit.component.icon.EpsIconName

@Composable
fun EpsNavigationBar(items: List<EpsNavigationItemState>) {
    Column {
        EpsDivider(EpsDividerState())
        NavigationBar(containerColor = ShopTokens.Background, tonalElevation = 0.dp) {
            items.forEachIndexed { index, item ->
                NavigationBarItem(
                    modifier = if (item.testTag.isEmpty()) Modifier else Modifier.testTag(item.testTag),
                    selected = item.selected,
                    onClick = item.onClick,
                    label = { Text(item.text) },
                    icon = {
                        EpsIcon(
                            item.icon ?: when (index) {
                                0 -> EpsIconName.Home
                                1 -> EpsIconName.Catalog
                                2 -> EpsIconName.Cart
                                else -> EpsIconName.Profile
                            },
                            contentDescription = null,
                        )
                    },
                    colors = NavigationBarItemDefaults.colors(
                        selectedIconColor = ShopTokens.Primary,
                        selectedTextColor = ShopTokens.Primary,
                        unselectedIconColor = ShopTokens.SecondaryText,
                        unselectedTextColor = ShopTokens.SecondaryText,
                        indicatorColor = Color.Transparent,
                    ),
                )
            }
        }
    }
}
