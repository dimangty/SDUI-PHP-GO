package com.shopkmp.product.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
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
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.error.EpsErrorMessage
import com.shopkmp.uikit.component.shop.EpsProductDetails
import com.shopkmp.uikit.component.shop.EpsScreenHeader
import org.koin.compose.viewmodel.koinViewModel

@Composable
fun ProductDetailsScreen(
    productId: String,
    onBack: () -> Unit,
    onAddedToCart: () -> Unit,
) {
    val viewModel = koinViewModel<ProductDetailsViewModel>()
    val currentBack by rememberUpdatedState(onBack)
    val currentAdded by rememberUpdatedState(onAddedToCart)
    LaunchedEffect(productId) { viewModel.load(productId, currentBack, currentAdded) }
    val state by viewModel.stateFlow.collectAsState()
    val boundState = state.content
    if (boundState == null) {
        val error = state.error ?: return
        Column(
            modifier = Modifier.fillMaxSize().padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            EpsErrorMessage(error)
            EpsButton(
                state = EpsButtonState(text = "Назад", onClick = { currentBack() }),
                modifier = Modifier.fillMaxWidth(),
            )
        }
        return
    }
    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        item { EpsScreenHeader(boundState.headerState) }
        item { EpsProductDetails(boundState.detailsState) }
        state.error?.let { error ->
            item { EpsErrorMessage(error) }
        }
        item { EpsButton(boundState.addToCartButtonState, Modifier.fillMaxWidth()) }
    }
}
