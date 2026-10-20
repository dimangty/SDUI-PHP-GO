package com.shopkmp.main

import com.shopkmp.mvvm.BaseViewModel
import com.shopkmp.mvvm.BaseViewModelDependencies
import com.shopkmp.mvvm.resolveBaseViewModelDependencies

class MainViewModel(dependencies: BaseViewModelDependencies = resolveBaseViewModelDependencies()) :
    BaseViewModel<MainUiEvent, MainState>(MainState(), dependencies) {

    override fun processUiEvent(event: MainUiEvent) {
        when (event) {
            MainUiEvent.OnAppUiStarted -> {}
            MainUiEvent.OnAppUiStopped -> {}
        }
    }
}
