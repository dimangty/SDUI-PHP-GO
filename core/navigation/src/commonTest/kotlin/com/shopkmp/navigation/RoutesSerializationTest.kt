package com.shopkmp.navigation

import androidx.navigation3.runtime.NavKey
import kotlinx.serialization.PolymorphicSerializer
import kotlinx.serialization.json.Json
import kotlin.test.Test
import kotlin.test.assertEquals

class RoutesSerializationTest {
    @Test fun everyNativeRootRouteIncludingChangePinRestores() {
        val json = Json { serializersModule = RoutesSerializationConfig.serializersModule }
        val serializer = PolymorphicSerializer(NavKey::class)
        listOf(NavigationAction.NavigateToLogin, NavigationAction.NavigateToSignUp,
            NavigationAction.NavigateToPin, NavigationAction.NavigateToPinLogin,
            NavigationAction.NavigateToChangePin, NavigationAction.NavigateToMain).forEach { route ->
            val saved = json.encodeToString(serializer, route)
            assertEquals(route, json.decodeFromString(serializer, saved))
        }
    }
}
