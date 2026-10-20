package com.shopkmp.navigation

import androidx.navigation3.runtime.NavKey
import kotlinx.serialization.Serializable

@Serializable
sealed class NavigationAction : NavKey {
    @Serializable
    data object NavigateToLogin : NavigationAction()

    @Serializable
    data object NavigateToSignUp : NavigationAction()

    @Serializable
    data object NavigateToPin : NavigationAction()

    @Serializable
    data object NavigateToPinLogin : NavigationAction()

    @Serializable
    data object NavigateToChangePin : NavigationAction()

    @Serializable
    data object NavigateToMain : NavigationAction()
}
