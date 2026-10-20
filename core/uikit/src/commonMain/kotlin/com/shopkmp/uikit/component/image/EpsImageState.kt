package com.shopkmp.uikit.component.image

import com.shopkmp.uikit.component.imageplaceholder.EpsImagePlaceholderState

data class EpsImageState(
    val url: String = "",
    val contentDescription: String? = null,
    val placeholder: EpsImagePlaceholderState = EpsImagePlaceholderState(label = "Фото"),
    val requestedWidth: Int = 640,
    val requestedHeight: Int = 480,
)
