package com.shopkmp.navigation

import androidx.navigation3.runtime.NavKey
import androidx.savedstate.serialization.SavedStateConfiguration
import kotlinx.serialization.modules.SerializersModule
import kotlinx.serialization.modules.polymorphic
import kotlinx.serialization.modules.subclass

val RoutesSerializationConfig = SavedStateConfiguration {
    serializersModule = SerializersModule {
        polymorphic(NavKey::class) {
            subclass(NavigationAction.NavigateToLogin::class)
            subclass(NavigationAction.NavigateToSignUp::class)
            subclass(NavigationAction.NavigateToPin::class)
            subclass(NavigationAction.NavigateToPinLogin::class)
            subclass(NavigationAction.NavigateToChangePin::class)
            subclass(NavigationAction.NavigateToMain::class)
        }
    }
}
