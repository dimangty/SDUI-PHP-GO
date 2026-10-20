package com.shopkmp.devices.ui.list

import kotlin.test.Test
import kotlin.test.assertEquals

class DeviceListStateBuilderTest {
    @Test
    fun backButtonUsesFeatureBoundaryCallback() {
        var calls = 0
        val state = DeviceListStateBuilder().build(emptyList(), { calls++ }) {}
        state.headerState.backButtonState?.onClick?.invoke()
        assertEquals(1, calls)
    }
}
