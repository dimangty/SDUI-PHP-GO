package com.shopkmp

import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.fragment.app.FragmentActivity
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.testTagsAsResourceId
import com.shopkmp.common.biometric.registerBiometricActivity
import com.shopkmp.main.MainView

class MainActivity : FragmentActivity() {
    @OptIn(androidx.compose.ui.ExperimentalComposeUiApi::class)
    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)

        setContent {
            Box(Modifier.fillMaxSize().semantics { testTagsAsResourceId = true }) {
                MainView()
            }
        }
    }

    override fun onResume() {
        super.onResume()
        registerBiometricActivity(this)
    }

    override fun onPause() {
        registerBiometricActivity(null)
        super.onPause()
    }
}
