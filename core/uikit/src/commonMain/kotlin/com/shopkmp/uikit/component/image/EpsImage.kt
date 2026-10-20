package com.shopkmp.uikit.component.image

import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import coil3.compose.SubcomposeAsyncImage
import coil3.compose.LocalPlatformContext
import coil3.request.ImageRequest
import coil3.request.crossfade
import coil3.size.Size
import com.shopkmp.uikit.component.imageplaceholder.EpsImagePlaceholder

@Composable
fun EpsImage(
    state: EpsImageState,
    modifier: Modifier = Modifier,
) {
    SubcomposeAsyncImage(
        model = ImageRequest.Builder(LocalPlatformContext.current)
            .data(state.url)
            .size(Size(state.requestedWidth, state.requestedHeight))
            .crossfade(true)
            .build(),
        contentDescription = state.contentDescription,
        modifier = modifier,
        contentScale = ContentScale.Crop,
        loading = { EpsImagePlaceholder(state.placeholder, Modifier.matchParentSize()) },
        error = { EpsImagePlaceholder(state.placeholder.copy(label = "Нет фото"), Modifier.matchParentSize()) },
    )
}
