package com.shopkmp.orders.ui.history

import kotlin.test.Test
import kotlin.test.assertEquals

class OrderHistoryStateBuilderTest {
    @Test
    fun backButtonUsesFeatureBoundaryCallback() {
        var calls = 0
        val state = OrderHistoryStateBuilder().build(emptyList()) { calls++ }
        state.headerState.backButtonState?.onClick?.invoke()
        assertEquals(1, calls)
    }
}
