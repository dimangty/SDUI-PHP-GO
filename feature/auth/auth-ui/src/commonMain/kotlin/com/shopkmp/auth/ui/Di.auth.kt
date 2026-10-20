package com.shopkmp.auth.ui

import com.shopkmp.auth.data.moduleAuthData
import com.shopkmp.auth.domain.moduleAuthDomain
import com.shopkmp.auth.ui.login.LoginScreen
import com.shopkmp.auth.ui.login.LoginViewModel
import com.shopkmp.auth.ui.pin.PinScreen
import com.shopkmp.auth.ui.pin.PinScreenMode
import com.shopkmp.auth.ui.pin.PinViewModel
import com.shopkmp.auth.ui.signup.SignUpScreen
import com.shopkmp.auth.ui.signup.SignUpViewModel
import com.shopkmp.navigation.NavigationAction
import org.koin.core.annotation.KoinExperimentalAPI
import org.koin.core.module.dsl.viewModelOf
import org.koin.dsl.module
import org.koin.dsl.navigation3.navigation

@OptIn(KoinExperimentalAPI::class)
val moduleAuth = module {
    viewModelOf(::LoginViewModel)
    viewModelOf(::PinViewModel)
    viewModelOf(::SignUpViewModel)

    navigation<NavigationAction.NavigateToLogin> { LoginScreen() }
    navigation<NavigationAction.NavigateToPin> { PinScreen(PinScreenMode.Create) }
    navigation<NavigationAction.NavigateToPinLogin> { PinScreen(PinScreenMode.Login) }
    navigation<NavigationAction.NavigateToChangePin> { PinScreen(PinScreenMode.Change) }
    navigation<NavigationAction.NavigateToSignUp> { SignUpScreen() }

    includes(moduleAuthData, moduleAuthDomain)
}
