package com.shopkmp.uikit.component.fullscreenerror

data class EpsFullscreenErrorState(
    val errorType: FullScreenErrorType = FullScreenErrorType.OneButtonError,
    val title: String = "",
    val subtitle: String = "",
    val buttonTitle: String = "",
    val firstButtonTitle: String = "",
    val secondButtonTitle: String = "",
    val reconnectButtonTitle: String = "",
    val reconnectButtonEnabled: Boolean = true,
    val bottomText: String = "",
    val bottomLink: String = "",
    val buttonAction: (() -> Unit)? = null,
    val firstButtonAction: (() -> Unit)? = null,
    val secondButtonAction: (() -> Unit)? = null,
    val middleButtonAction: (() -> Unit)? = null,
    val linkTextAction: (() -> Unit)? = null,
) {
    companion object {
        fun createOneButtonError(
            title: String,
            subtitle: String,
            buttonTitle: String,
            buttonAction: () -> Unit,
        ) = EpsFullscreenErrorState(
            errorType = FullScreenErrorType.OneButtonError,
            title = title,
            subtitle = subtitle,
            buttonTitle = buttonTitle,
            buttonAction = buttonAction,
        )

        fun createTwoButtonsError(
            title: String,
            subtitle: String,
            firstButtonTitle: String,
            secondButtonTitle: String,
            firstButtonAction: () -> Unit,
            secondButtonAction: () -> Unit,
        ) = EpsFullscreenErrorState(
            errorType = FullScreenErrorType.TwoButtonsError,
            title = title,
            subtitle = subtitle,
            firstButtonTitle = firstButtonTitle,
            secondButtonTitle = secondButtonTitle,
            firstButtonAction = firstButtonAction,
            secondButtonAction = secondButtonAction,
        )

        fun createNoInternet(
            title: String,
            subtitle: String,
            reconnectButtonTitle: String,
            reconnectButtonEnabled: Boolean,
            bottomText: String,
            bottomLink: String,
            middleButtonAction: () -> Unit,
            linkTextAction: () -> Unit,
        ) = EpsFullscreenErrorState(
            errorType = FullScreenErrorType.NoInternetError,
            title = title,
            subtitle = subtitle,
            reconnectButtonTitle = reconnectButtonTitle,
            reconnectButtonEnabled = reconnectButtonEnabled,
            bottomText = bottomText,
            bottomLink = bottomLink,
            middleButtonAction = middleButtonAction,
            linkTextAction = linkTextAction,
        )
    }

    fun updateSubtitle(newSubtitle: String) = copy(subtitle = newSubtitle)
    fun updateMiddleButtonEnabled(enabled: Boolean) = copy(reconnectButtonEnabled = enabled)
}
