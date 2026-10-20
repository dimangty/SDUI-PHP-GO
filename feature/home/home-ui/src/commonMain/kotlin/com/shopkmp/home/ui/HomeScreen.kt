package com.shopkmp.home.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.button.defaultbutton.EpsButton
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.chip.EpsChip
import com.shopkmp.uikit.component.shop.EpsHomeContentState
import com.shopkmp.uikit.component.shop.EpsProductCard
import com.shopkmp.uikit.component.shop.EpsSectionHeader
import com.shopkmp.uikit.component.shop.EpsShopHeader
import com.shopkmp.uikit.component.spacer.EpsSpacer
import com.shopkmp.uikit.component.spacer.EpsSpacerState
import com.shopkmp.uikit.component.text.EpsText
import com.shopkmp.uikit.component.text.EpsTextState
import com.shopkmp.uikit.component.text.EpsTextStyle
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextField
import org.koin.compose.viewmodel.koinViewModel

@Composable
fun HomeScreen(
    onSearch: (String) -> Unit,
    onCatalog: (String?) -> Unit,
    onProfile: () -> Unit,
    onProduct: (String) -> Unit,
) {
    val viewModel = koinViewModel<HomeViewModel>()
    val currentSearch by rememberUpdatedState(onSearch)
    val currentCatalog by rememberUpdatedState(onCatalog)
    val currentProfile by rememberUpdatedState(onProfile)
    val currentProduct by rememberUpdatedState(onProduct)
    LaunchedEffect(Unit) {
        viewModel.load(currentSearch, currentCatalog, currentProfile, currentProduct)
    }
    val state by viewModel.stateFlow.collectAsState()
    state.error?.let {
        HomeErrorView(it) {
            viewModel.load(currentSearch, currentCatalog, currentProfile, currentProduct)
        }
        return
    }
    val content = state.content
    if (content != null) {
        HomeView(content)
    }
}

@Composable
private fun HomeView(contentState: EpsHomeContentState) {
    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        item { EpsShopHeader(contentState.headerState) }
        item { EpsTextField(contentState.searchState) }
        item {
            EpsSectionHeader(contentState.categoriesHeaderState)
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(contentState.categoryStates) { EpsChip(it) }
            }
        }
        item { EpsSectionHeader(contentState.popularHeaderState) }
        items(contentState.popularProducts.chunked(2)) { rowProducts ->
            Row(
                horizontalArrangement = Arrangement.spacedBy(12.dp),
                modifier = Modifier.fillMaxWidth(),
            ) {
                rowProducts.forEach { product ->
                    EpsProductCard(product, Modifier.weight(1f))
                }
                if (rowProducts.size == 1) {
                    EpsSpacer(EpsSpacerState.width(0.dp), Modifier.weight(1f))
                }
            }
        }
    }
}

@Composable
private fun HomeErrorView(message: String, onRetry: () -> Unit) {
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
