package com.shopkmp.uikit.component.navigationbar

import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag

@Composable
fun EpsNavigationBar(items: List<EpsNavigationItemState>) {
    NavigationBar {
        items.forEach { item ->
            NavigationBarItem(
                modifier = if (item.testTag.isEmpty()) Modifier else Modifier.testTag(item.testTag),
                selected = item.selected,
                onClick = item.onClick,
                label = { Text(item.text) },
                icon = {},
            )
        }
    }
}
