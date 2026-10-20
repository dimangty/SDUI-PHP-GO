package com.shopkmp.home.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.shopkmp.home.domain.ShopRepository
import com.shopkmp.uikit.component.shop.EpsHomeContentState
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class HomeState(
    val content: EpsHomeContentState? = null,
    val error: String? = null,
)

class HomeViewModel(
    private val repository: ShopRepository,
) : ViewModel() {
    private val mutableState = MutableStateFlow(HomeState())
    val stateFlow = mutableState.asStateFlow()
    private val stateBuilder = HomeStateBuilder()

    fun load(
        onSearch: (String) -> Unit,
        onCatalog: (String?) -> Unit,
        onProfile: () -> Unit,
        onProduct: (String) -> Unit,
    ) {
        viewModelScope.launch {
            val categories = repository.getCategories()
            val popular = repository.getPopularProducts(4)
            val cart = repository.getCartItems()
            mutableState.value = if (categories == null || popular == null) {
                HomeState(error = "Ошибка загрузки")
            } else {
                HomeState(
                    content = stateBuilder.build(
                        categories = categories,
                        products = popular,
                        cartItemsCount = cart?.first?.sumOf { it.quantity } ?: 0,
                        onSearch = onSearch,
                        onCatalog = onCatalog,
                        onProfile = onProfile,
                        onProduct = onProduct,
                    ),
                )
            }
        }
    }
}
