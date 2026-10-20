package com.shopkmp.navigation.navigationservice

import androidx.navigation3.runtime.NavBackStack
import com.shopkmp.navigation.NavigationAction
import kotlinx.coroutines.flow.StateFlow

interface AppNavigator {
    val backstack: NavBackStack<NavigationAction>
    val currentDestination: StateFlow<NavigationAction?>

    fun push(route: NavigationAction, singleTop: Boolean = true)
    fun pop()
    fun popTo(route: NavigationAction, inclusive: Boolean)
    fun clear()
    fun replaceAll(route: NavigationAction)
    fun replaceAll(routes: List<NavigationAction>)

    fun <T> setPreviousBackStackEntry(key: String, value: T)
    fun <T> getCurrentBackStackEntry(key: String): T?
    fun <T> clearCurrentBackStackEntry(key: String)
}
