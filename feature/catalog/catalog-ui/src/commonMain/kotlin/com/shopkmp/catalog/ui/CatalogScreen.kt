package com.shopkmp.catalog.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.button.defaultbutton.EpsButton
import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.chip.EpsChip
import com.shopkmp.uikit.component.error.EpsErrorMessage
import com.shopkmp.uikit.component.shop.EpsCatalogContentState
import com.shopkmp.uikit.component.shop.EpsProductListItem
import com.shopkmp.uikit.component.shop.EpsScreenHeader
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextField
import org.koin.compose.viewmodel.koinViewModel

@Composable
fun CatalogScreen(
    initialQuery: String,
    initialCategory: String?,
    onProduct: (String) -> Unit,
) {
    val viewModel = koinViewModel<CatalogViewModel>()
    val currentOnProduct by rememberUpdatedState(onProduct)
    LaunchedEffect(initialQuery, initialCategory) {
        viewModel.start(initialQuery, initialCategory, currentOnProduct)
    }
    val state by viewModel.stateFlow.collectAsState()
    state.error?.let {
        CatalogErrorView(it, viewModel::retry)
        return
    }
    val content = state.content
    if (content != null) {
        CatalogView(content, viewModel::loadMore)
    }
}

@Composable
private fun CatalogErrorView(message: String, onRetry: () -> Unit) {
    Box(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            EpsErrorMessage(message)
            EpsButton(EpsButtonState(text = "Повторить", onClick = onRetry))
        }
    }
}

@Composable
private fun CatalogView(state: EpsCatalogContentState, onLoadMore: () -> Unit) {
    val listState = rememberLazyListState()
    val currentOnLoadMore by rememberUpdatedState(onLoadMore)
    val shouldLoadMore by remember {
        derivedStateOf {
            val visibleItems = listState.layoutInfo.visibleItemsInfo
            visibleItems.isNotEmpty() &&
                visibleItems.last().index >= listState.layoutInfo.totalItemsCount - 3
        }
    }
    LaunchedEffect(shouldLoadMore) {
        if (shouldLoadMore) currentOnLoadMore()
    }
    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        state = listState,
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item { EpsScreenHeader(state.headerState) }
        item { EpsTextField(state.searchState) }
        item {
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(state.categoryStates) { EpsChip(it) }
            }
        }
        items(state.products) { EpsProductListItem(it) }
    }
}
