package com.shopkmp.uikit.component.screen

import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.shopkmp.uikit.component.navigationbar.EpsNavigationBar
import com.shopkmp.uikit.component.navigationbar.EpsNavigationItemState
import com.shopkmp.uikit.component.scaffold.EpsScaffold

@Composable
fun EpsNavigationScreen(
    screenSurfaceState: EpsScreenSurfaceState,
    navigationItems: List<EpsNavigationItemState>,
    content: @Composable () -> Unit,
) {
    EpsScaffold(
        modifier = Modifier.fillMaxSize(),
        bottomBar = { EpsNavigationBar(items = navigationItems) },
    ) { innerPadding ->
        EpsScreenSurface(
            state = screenSurfaceState,
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding),
        ) {
            content()
        }
    }
}
