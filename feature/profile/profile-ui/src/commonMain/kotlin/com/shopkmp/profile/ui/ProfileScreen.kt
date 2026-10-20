package com.shopkmp.profile.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.button.defaultbutton.EpsButton
import com.shopkmp.uikit.component.shop.EpsProfileActions
import com.shopkmp.uikit.component.shop.EpsProfileField
import com.shopkmp.uikit.component.shop.EpsProfileHeader
import com.shopkmp.uikit.component.shop.EpsScreenHeader
import org.koin.compose.viewmodel.koinViewModel

@Composable
fun ProfileScreen(
    onEdit: () -> Unit,
    onOrders: () -> Unit,
    onDevices: () -> Unit,
    onChangePin: () -> Unit,
    onLogout: () -> Unit,
) {
    val viewModel = koinViewModel<ProfileViewModel>()
    val currentEdit by rememberUpdatedState(onEdit)
    val currentOrders by rememberUpdatedState(onOrders)
    val currentDevices by rememberUpdatedState(onDevices)
    val currentChangePin by rememberUpdatedState(onChangePin)
    val currentLogout by rememberUpdatedState(onLogout)
    LaunchedEffect(Unit) {
        viewModel.load(
            currentEdit,
            currentOrders,
            currentDevices,
            currentChangePin,
            currentLogout,
        )
    }
    val state by viewModel.stateFlow.collectAsState()
    val boundState = state.state.contentState ?: return
    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        item { EpsScreenHeader(boundState.headerState) }
        item { EpsProfileHeader(boundState.profileHeaderState) }
        item { EpsProfileField(boundState.phoneFieldState) }
        item { EpsProfileField(boundState.addressFieldState) }
        item {
            EpsButton(
                boundState.orderHistoryButtonState,
                Modifier.fillMaxWidth(),
            )
        }
        item { EpsProfileActions(boundState.actionsState) }
        item { EpsButton(boundState.logoutButtonState, Modifier.fillMaxWidth()) }
    }
}
