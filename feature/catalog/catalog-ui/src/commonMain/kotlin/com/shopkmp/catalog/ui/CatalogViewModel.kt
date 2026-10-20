package com.shopkmp.catalog.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.shopkmp.home.domain.ShopProduct
import com.shopkmp.home.domain.ShopRepository
import com.shopkmp.uikit.component.shop.EpsCatalogContentState
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class CatalogState(
    val content: EpsCatalogContentState? = null,
    val error: String? = null,
    val loadingMore: Boolean = false,
)

class CatalogViewModel(
    private val repository: ShopRepository,
) : ViewModel() {
    private val mutableState = MutableStateFlow(CatalogState())
    val stateFlow = mutableState.asStateFlow()
    private val builder = CatalogStateBuilder()
    private var categories: List<String> = emptyList()
    private var products: List<ShopProduct> = emptyList()
    private var query = ""
    private var category: String? = null
    private var page = 0
    private var totalPages = 0
    private var onProduct: (String) -> Unit = {}
    private var refreshJob: Job? = null
    private var refreshGeneration = 0

    fun start(initialQuery: String, initialCategory: String?, onProduct: (String) -> Unit) {
        this.onProduct = onProduct
        if (query == initialQuery && category == initialCategory && mutableState.value.content != null) return
        query = initialQuery
        category = initialCategory
        refresh()
    }

    fun retry() = refresh()

    fun loadMore() {
        if (mutableState.value.loadingMore || page >= totalPages - 1) return
        val generation = refreshGeneration
        val requestedPage = page + 1
        val requestedQuery = query
        val requestedCategory = category
        viewModelScope.launch {
            mutableState.value = mutableState.value.copy(loadingMore = true)
            val result = repository.getProducts(
                requestedQuery,
                requestedCategory,
                requestedPage,
            )
            if (generation != refreshGeneration ||
                requestedQuery != query ||
                requestedCategory != category ||
                requestedPage != page + 1
            ) {
                return@launch
            }
            if (result == null) {
                mutableState.value = mutableState.value.copy(
                    error = "Ошибка загрузки",
                    loadingMore = false,
                )
                return@launch
            }
            page = requestedPage
            products += result.first
            totalPages = result.second
            publish()
        }
    }

    private fun onQueryChanged(value: String) {
        query = value
        publish()
        scheduleRefresh(delayMillis = 400)
    }

    private fun onCategoryChanged(value: String?) {
        category = value
        refresh()
    }

    private fun refresh() {
        scheduleRefresh(delayMillis = 0)
    }

    private fun scheduleRefresh(delayMillis: Long) {
        val generation = ++refreshGeneration
        refreshJob?.cancel()
        refreshJob = viewModelScope.launch {
            if (delayMillis > 0) delay(delayMillis)
            if (categories.isEmpty()) categories = repository.getCategories().orEmpty()
            val result = repository.getProducts(query, category, page = 0)
            if (generation != refreshGeneration) return@launch
            if (result == null) {
                mutableState.value = CatalogState(error = "Ошибка загрузки")
                return@launch
            }
            products = result.first
            page = 0
            totalPages = result.second
            publish()
        }
    }

    private fun publish() {
        mutableState.value = CatalogState(
            content = builder.build(
                categories = categories,
                products = products,
                query = query,
                selectedCategory = category,
                onQueryChanged = ::onQueryChanged,
                onCategoryChanged = ::onCategoryChanged,
                onProduct = onProduct,
            ),
        )
    }
}
