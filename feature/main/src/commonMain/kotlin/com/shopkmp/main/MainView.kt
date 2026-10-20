package com.shopkmp.main

import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.viewmodel.navigation3.rememberViewModelStoreNavEntryDecorator
import androidx.navigation3.runtime.rememberNavBackStack
import androidx.navigation3.runtime.rememberSaveableStateHolderNavEntryDecorator
import androidx.navigation3.ui.NavDisplay
import com.shopkmp.auth.domain.AuthService
import com.shopkmp.mvvm.BaseActivityScreen
import com.shopkmp.navigation.NavigationAction
import com.shopkmp.navigation.RoutesSerializationConfig
import com.shopkmp.navigation.navigationservice.AppNavigator
import com.shopkmp.uikit.component.main.EpsRootScaffold
import org.koin.compose.koinInject
import org.koin.compose.navigation3.koinEntryProvider
import org.koin.compose.viewmodel.koinViewModel
import org.koin.core.annotation.KoinExperimentalAPI
import org.koin.core.parameter.parametersOf

@OptIn(KoinExperimentalAPI::class)
@Composable
fun MainView() {
    val authService = koinInject<AuthService>()
    // Restore the session asynchronously. An expired refresh token routes the
    // user to Login instead of leaving an empty Main screen.
    var sessionRestored by androidx.compose.runtime.remember { androidx.compose.runtime.mutableStateOf(false) }
    var startDestination by androidx.compose.runtime.remember {
        androidx.compose.runtime.mutableStateOf<NavigationAction>(NavigationAction.NavigateToLogin)
    }
    androidx.compose.runtime.LaunchedEffect(Unit) {
        startDestination = InitialSessionRouter(authService).destination()
        sessionRestored = true
    }
    if (!sessionRestored) {
        // Keep the splash screen visible while the session is being restored.
        com.shopkmp.uikit.component.main.EpsRootScaffold { }
        return
    }
    val backstack = rememberNavBackStack(
        RoutesSerializationConfig,
        startDestination,
    )
    val coroutineScope = rememberCoroutineScope()
    val appNavigator = koinInject<AppNavigator> { parametersOf(backstack, coroutineScope) }
    val viewModel: MainViewModel = koinViewModel()
    val state by viewModel.stateFlow.collectAsState()
    val lifecycleOwner = LocalLifecycleOwner.current

    DisposableEffect(lifecycleOwner, viewModel) {
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> viewModel.onUiEvent(MainUiEvent.OnAppUiStarted)
                Lifecycle.Event.ON_STOP -> viewModel.onUiEvent(MainUiEvent.OnAppUiStopped)
                else -> Unit
            }
        }

        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose { lifecycleOwner.lifecycle.removeObserver(observer) }
    }

    EpsRootScaffold { navModifier ->
        BaseActivityScreen(
            lceState = state.lceState,
            defaultEffectFlow = viewModel.defaultEffectFlow,
            onDefaultUiEvent = viewModel::onDefaultUiEvent,
        ) {
            NavDisplay(
                backStack = appNavigator.backstack,
                entryDecorators = listOf(
                    rememberSaveableStateHolderNavEntryDecorator(),
                    rememberViewModelStoreNavEntryDecorator()
                ),
                entryProvider = koinEntryProvider(),
                onBack = { appNavigator.pop() },
                modifier = navModifier,
                transitionSpec = { fadeIn() togetherWith fadeOut() },
                popTransitionSpec = { fadeIn() togetherWith fadeOut() },
                predictivePopTransitionSpec = { fadeIn() togetherWith fadeOut() },
            )
        }
    }
}
