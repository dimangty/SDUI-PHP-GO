package com.shopkmp.cart.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.button.defaultbutton.EpsButton
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.divider.EpsDivider
import com.shopkmp.uikit.component.shop.EpsCartContentState
import com.shopkmp.uikit.component.shop.EpsCartItem
import com.shopkmp.uikit.component.shop.EpsCartTotal
import com.shopkmp.uikit.component.shop.EpsEmptyState
import com.shopkmp.uikit.component.shop.EpsPaymentSelector
import com.shopkmp.uikit.component.shop.EpsScreenHeader
import com.shopkmp.uikit.component.shop.EpsSuccessMessage
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState
import com.shopkmp.uikit.component.text.EpsTextStyle
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextField
import org.koin.compose.viewmodel.koinViewModel

@Composable
fun CartScreen() {
    val viewModel = koinViewModel<CartViewModel>()
    LaunchedEffect(Unit) { viewModel.load() }
    val state by viewModel.stateFlow.collectAsState()
    state.error?.let {
        CartErrorView(it, viewModel::retry)
        return
    }
    val content = state.content
    if (content != null) {
        CartView(content)
    }
}

@Composable
internal fun CartView(state: EpsCartContentState) {
    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item { EpsScreenHeader(state.headerState) }
        if (state.items.isEmpty()) {
            item {
                EpsEmptyState(
                    state.emptyState.title,
                    state.emptyState.subtitle,
                    state.emptyState.cardState,
                )
            }
        } else {
            items(state.items) { EpsCartItem(it) }
            item { EpsDivider(state.dividerState) }
            item { EpsCartTotal(state.totalState) }
            item { EpsTextField(state.cityTextFieldState) }
            item { EpsTextField(state.streetTextFieldState) }
            item { EpsTextField(state.houseTextFieldState) }
            item { EpsTextField(state.apartmentTextFieldState) }
            item { EpsPaymentSelector(state.paymentSelectorState) }
            item { EpsButton(state.checkoutButtonState, Modifier.fillMaxWidth()) }
            state.checkoutStatusMessage?.let { message ->
                item { EpsSuccessMessage(message) }
            }
        }
    }
}

@Composable
private fun CartErrorView(message: String, onRetry: () -> Unit) {
    Box(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            EpsText(EpsTextState(message, style = EpsTextStyle.Error))
            EpsButton(EpsButtonState(text = "Повторить", onClick = onRetry))
        }
    }
}
