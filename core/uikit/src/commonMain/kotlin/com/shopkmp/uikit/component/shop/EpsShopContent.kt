package com.shopkmp.uikit.component.shop

import com.shopkmp.uikit.component.button.defaultbutton.EpsButtonState
import com.shopkmp.uikit.component.card.EpsCardState
import com.shopkmp.uikit.component.chip.EpsChipState
import com.shopkmp.uikit.component.divider.EpsDividerState
import com.shopkmp.uikit.component.textfield.defaulttextfield.EpsTextFieldState

data class EpsHomeContentState(
    val headerState: EpsShopHeaderState,
    val searchState: EpsTextFieldState,
    val categoriesHeaderState: EpsSectionHeaderState,
    val categoryStates: List<EpsChipState>,
    val popularHeaderState: EpsSectionHeaderState,
    val popularProducts: List<EpsProductState>,
)

data class EpsCatalogContentState(
    val headerState: EpsScreenHeaderState,
    val searchState: EpsTextFieldState,
    val categoryStates: List<EpsChipState>,
    val products: List<EpsProductState>,
)

data class EpsCartEmptyState(
    val title: String,
    val subtitle: String,
    val cardState: EpsCardState,
)

data class EpsCartContentState(
    val headerState: EpsScreenHeaderState,
    val items: List<EpsCartItemState>,
    val emptyState: EpsCartEmptyState,
    val dividerState: EpsDividerState,
    val totalState: EpsCartTotalState,
    val cityTextFieldState: EpsTextFieldState,
    val streetTextFieldState: EpsTextFieldState,
    val houseTextFieldState: EpsTextFieldState,
    val apartmentTextFieldState: EpsTextFieldState,
    val paymentSelectorState: EpsPaymentSelectorState,
    val checkoutButtonState: EpsButtonState,
    val checkoutStatusMessage: String?,
)

data class EpsProductDetailsContentState(
    val headerState: EpsScreenHeaderState,
    val detailsState: EpsProductDetailsState,
    val addToCartButtonState: EpsButtonState,
)

data class EpsProfileContentState(
    val headerState: EpsScreenHeaderState,
    val profileHeaderState: EpsProfileHeaderState,
    val phoneFieldState: EpsProfileFieldState,
    val addressFieldState: EpsProfileFieldState,
    val orderHistoryButtonState: EpsButtonState,
    val actionsState: EpsProfileActionsState,
    val logoutButtonState: EpsButtonState,
)

data class EpsOrderHistoryContentState(
    val headerState: EpsScreenHeaderState,
    val orders: List<EpsOrderRowState>,
)

data class EpsDeviceListContentState(
    val headerState: EpsScreenHeaderState,
    val devices: List<EpsDeviceRowState>,
    val emptyState: EpsCartEmptyState,
)

data class EpsEditProfileContentState(
    val headerState: EpsScreenHeaderState,
    val avatarState: EpsEditAvatarState,
    val nameTextFieldState: EpsTextFieldState,
    val phoneTextFieldState: EpsTextFieldState,
    val emailTextFieldState: EpsTextFieldState,
    val addressTextFieldState: EpsTextFieldState,
    val saveButtonState: EpsButtonState,
)
