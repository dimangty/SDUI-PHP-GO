package com.shopkmp.devices.ui.list

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.shop.EpsDeviceListContentState
import com.shopkmp.uikit.component.shop.EpsDeviceRow
import com.shopkmp.uikit.component.shop.EpsEmptyState
import com.shopkmp.uikit.component.shop.EpsScreenHeader
import org.koin.compose.viewmodel.koinViewModel

@Composable
fun DeviceListScreen(
    onBack: () -> Unit,
) {
    val viewModel = koinViewModel<DeviceListViewModel>()
    val currentOnBack by rememberUpdatedState(onBack)
    LaunchedEffect(Unit) {
        viewModel.load { currentOnBack() }
    }
    val state by viewModel.stateFlow.collectAsState()
    val boundContentState = state.contentState ?: return

    DeviceListView(boundContentState)
}

@Composable
private fun DeviceListView(state: EpsDeviceListContentState) {
    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item { EpsScreenHeader(state.headerState) }
        if (state.devices.isEmpty()) {
            item {
                EpsEmptyState(
                    title = state.emptyState.title,
                    subtitle = state.emptyState.subtitle,
                    cardState = state.emptyState.cardState,
                )
            }
        } else {
            items(state.devices) { deviceState ->
                EpsDeviceRow(deviceState)
            }
        }
    }
}
