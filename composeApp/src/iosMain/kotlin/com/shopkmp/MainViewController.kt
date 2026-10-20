package com.shopkmp

import androidx.compose.ui.window.ComposeUIViewController
import com.shopkmp.main.MainView
import org.koin.core.context.startKoin

private var isKoinStarted = false

fun MainViewController() = ComposeUIViewController {
    if (!isKoinStarted) {
        configureLogging()
        startKoin {
            modules(appModules)
        }
        isKoinStarted = true
    }

    MainView()
}
