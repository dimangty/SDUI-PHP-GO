package com.shopkmp.navigation

import androidx.navigation3.runtime.NavKey
import androidx.savedstate.serialization.decodeFromSavedState
import androidx.savedstate.serialization.encodeToSavedState
import kotlinx.serialization.PolymorphicSerializer
import kotlin.test.Test
import kotlin.test.assertEquals

class NativeRoutesSavedStateTest {
    @Test fun changePinRoundTripsThroughNativeSavedState() {
        val serializer = PolymorphicSerializer(NavKey::class)
        val saved = encodeToSavedState(serializer, NavigationAction.NavigateToChangePin, RoutesSerializationConfig)
        assertEquals(NavigationAction.NavigateToChangePin, decodeFromSavedState(serializer, saved, RoutesSerializationConfig))
    }
}
