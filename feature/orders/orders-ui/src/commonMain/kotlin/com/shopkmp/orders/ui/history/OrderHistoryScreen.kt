package com.shopkmp.orders.ui.history

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.shop.EpsOrderRow
import com.shopkmp.uikit.component.shop.EpsOrderHistoryContentState
import com.shopkmp.uikit.component.shop.EpsScreenHeader
import org.koin.compose.viewmodel.koinViewModel

@Composable
fun OrderHistoryScreen(
    onBack: () -> Unit,
) {
    val viewModel = koinViewModel<OrderHistoryViewModel>()
    val currentOnBack by rememberUpdatedState(onBack)
    LaunchedEffect(Unit) {
        viewModel.load { currentOnBack() }
    }
    val state by viewModel.stateFlow.collectAsState()
    val boundContentState = state.contentState ?: return

    OrderHistoryView(boundContentState, viewModel::loadMore)
}

@Composable
private fun OrderHistoryView(
    state: EpsOrderHistoryContentState,
    onLoadMore: () -> Unit,
) {
    val listState = rememberLazyListState()
    val currentOnLoadMore by rememberUpdatedState(onLoadMore)
    val shouldLoadMore by remember {
        derivedStateOf {
            val visibleItems = listState.layoutInfo.visibleItemsInfo
            visibleItems.isNotEmpty() && visibleItems.last().index >= listState.layoutInfo.totalItemsCount - 3
        }
    }
    LaunchedEffect(shouldLoadMore) {
        if (shouldLoadMore) {
            currentOnLoadMore()
        }
    }
    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        state = listState,
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item { EpsScreenHeader(state.headerState) }
        items(state.orders) { orderState ->
            EpsOrderRow(orderState)
        }
    }
}
