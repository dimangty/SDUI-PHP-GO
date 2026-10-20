package com.shopkmp.profile.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.shopkmp.uikit.component.button.defaultbutton.EpsButton
import com.shopkmp.uikit.component.error.EpsErrorMessage
import com.shopkmp.uikit.component.shop.EpsEditAvatar
import com.shopkmp.uikit.component.shop.EpsProfileFormFields
import com.shopkmp.uikit.component.shop.EpsScreenHeader
import org.koin.compose.viewmodel.koinViewModel

@Composable
fun EditProfileScreen(
    onBack: () -> Unit,
    onSaved: () -> Unit,
) {
    val viewModel = koinViewModel<EditProfileViewModel>()
    val currentBack by rememberUpdatedState(onBack)
    val currentSaved by rememberUpdatedState(onSaved)
    LaunchedEffect(Unit) { viewModel.load(currentBack, currentSaved) }
    val state by viewModel.stateFlow.collectAsState()
    val boundState = state.content ?: return
    LazyColumn(
        modifier = Modifier.fillMaxSize().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        item { EpsScreenHeader(boundState.headerState) }
        item { EpsEditAvatar(boundState.avatarState) }
        item {
            EpsProfileFormFields(
                nameTextFieldState = boundState.nameTextFieldState,
                phoneTextFieldState = boundState.phoneTextFieldState,
                emailTextFieldState = boundState.emailTextFieldState,
                addressTextFieldState = boundState.addressTextFieldState,
            )
        }
        state.error?.let { error ->
            item { EpsErrorMessage(error) }
        }
        item { EpsButton(boundState.saveButtonState, Modifier.fillMaxWidth()) }
    }
}
