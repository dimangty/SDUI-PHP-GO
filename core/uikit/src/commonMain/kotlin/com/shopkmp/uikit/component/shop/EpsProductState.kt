package com.shopkmp.uikit.component.shop

import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.imageplaceholder.EpsImagePlaceholderState

data class EpsProductState(
    val name: String,
    val description: String,
    val priceText: String,
    val ratingText: String,
    val imageUrl: String? = null,
    val imageState: EpsImagePlaceholderState,
    val cardState: EpsCardState,
)
