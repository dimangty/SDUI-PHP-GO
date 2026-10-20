package com.shopkmp.navigation.navigationservice

import androidx.compose.runtime.snapshotFlow
import androidx.navigation3.runtime.NavBackStack
import com.shopkmp.navigation.NavigationAction
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.stateIn

class AppNavigatorImpl(
    override val backstack: NavBackStack<NavigationAction>,
    coroutineScope: CoroutineScope
) : AppNavigator {
    override val currentDestination: StateFlow<NavigationAction?> =
        snapshotFlow { backstack.lastOrNull() }
            .stateIn(
                scope = coroutineScope,
                started = SharingStarted.Eagerly,
                initialValue = backstack.lastOrNull()
            )

    private val savedResults = mutableMapOf<String, Any?>()

    override fun push(
        route: NavigationAction,
        singleTop: Boolean
    ) {
        if (singleTop && backstack.lastOrNull() == route) {
            return
        }

        backstack.add(route)
    }

    override fun pop() {
        if (backstack.size > 1)
            backstack.removeLastOrNull()
    }

    override fun popTo(
        route: NavigationAction,
        inclusive: Boolean
    ) {
        val targetIndex = backstack.indexOfLast { it == route }
        if (targetIndex == -1) {
            return
        }

        while (backstack.lastIndex > targetIndex) {
            backstack.removeLast()
        }

        if (inclusive && backstack.isNotEmpty()) {
            backstack.removeLast()
        }
    }

    override fun clear() = backstack.clear()

    override fun replaceAll(route: NavigationAction) {
        clear()
        push(route)
    }

    override fun replaceAll(routes: List<NavigationAction>) {
        clear()
        backstack.addAll(routes)
    }

    @Suppress("UNCHECKED_CAST")
    override fun <T> getCurrentBackStackEntry(key: String): T? {
        return savedResults[key] as? T
    }

    override fun <T> setPreviousBackStackEntry(key: String, value: T) {
        savedResults[key] = value
    }

    override fun <T> clearCurrentBackStackEntry(key: String) {
        savedResults.remove(key)
    }
}
