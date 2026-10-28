package com.shopkmp.profile.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.unit.dp
import com.shopkmp.network.BackendSession
import com.shopkmp.ui.theme.ShopTokens
import com.shopkmp.uikit.component.button.defaultbutton.EpsButton
import com.shopkmp.uikit.component.error.EpsErrorMessage
import com.shopkmp.uikit.component.shop.EpsEditAvatar
import com.shopkmp.uikit.component.shop.EpsProfileFormFields
import com.shopkmp.uikit.component.topbar.EpsTopBar
import com.shopkmp.uikit.component.topbar.EpsTopBarState
import org.koin.compose.koinInject
import org.koin.compose.viewmodel.koinViewModel

@Composable
fun EditProfileScreen(
    onBack: () -> Unit,
    onSaved: () -> Unit,
    accountKey: String = "",
) {
    val viewModel = koinViewModel<EditProfileViewModel>(key = "editProfile:$accountKey")
    val session = koinInject<BackendSession>()
    val currentBack by rememberUpdatedState(onBack)
    val currentSaved by rememberUpdatedState(onSaved)
    DisposableEffect(viewModel, accountKey, session) {
        viewModel.load(
            { currentBack() }, { currentSaved() }, accountKey,
            currentAccount = { session.userId }, accountChanges = session.accountIdFlow,
        )
        onDispose { viewModel.leave() }
    }
    val state by viewModel.stateFlow.collectAsState()
    EditProfileContent(state, onBack = state.onBack)
}

/** Stateless editor presentation; tests supply state without starting application Koin. */
@Composable
fun EditProfileContent(
    state: EditProfileState,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(modifier.fillMaxSize().background(ShopTokens.Background)) {
        EpsTopBar(
            EpsTopBarState(
                title = "Редактирование",
                showBackButton = true,
                onBackClick = onBack,
                titleTestTag = "editProfile.title",
                backTestTag = "editProfile.back",
            ),
        )
        LazyColumn(
            modifier = Modifier.weight(1f).fillMaxWidth().imePadding(),
            contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            state.content?.let { content ->
                item { EpsEditAvatar(content.avatarState) }
                item {
                    EpsProfileFormFields(
                        nameTextFieldState = content.nameTextFieldState,
                        phoneTextFieldState = content.phoneTextFieldState,
                        emailTextFieldState = content.emailTextFieldState,
                        addressTextFieldState = content.addressTextFieldState,
                        testTagPrefix = "editProfile",
                    )
                }
            }
            if (state.loading) item {
                CircularProgressIndicator(modifier = Modifier.testTag("editProfile.loading"), color = ShopTokens.Primary)
            }
            state.error?.let { error ->
                item { EpsErrorMessage(error, Modifier.testTag("editProfile.error")) }
            }
            state.content?.let { content ->
                item {
                    EpsButton(content.saveButtonState, Modifier.fillMaxWidth().testTag("editProfile.save"))
                }
            }
        }
    }
}
