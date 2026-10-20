package com.shopkmp.mvvm

import com.shopkmp.mvvm.common.connectivity.ConnectivityServiceImpl
import com.shopkmp.mvvm.common.topbar.TopBarStateManager
import com.shopkmp.uikit.component.topbar.EpsTopBarState
import kotlinx.coroutines.test.runTest
import kotlin.test.Test
import kotlin.test.assertFalse
import kotlin.test.assertTrue

class MvvmStateManagersTest {
    @Test
    fun connectivityCallbackReportsAvailable() = runTest {
        var available = false
        var unavailable = false

        ConnectivityServiceImpl().checkInternetAvailable(
            onAvailable = { available = true },
            onNotAvailable = { unavailable = true },
        )

        assertTrue(available)
        assertFalse(unavailable)
    }

    @Test
    fun topBarStateCanBeInitializedAndUpdated() = runTest {
        val manager = TopBarStateManager()

        manager.initTopBar { EpsTopBarState(showBackButton = false) }
        manager.updateTopBar { copy(showBackButton = true) }

        assertTrue(manager.topBarState.value.showBackButton)
    }
}
