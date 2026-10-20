package com.shopkmp.mvvm.common.singleclick

import com.shopkmp.mvvm.common.SingleClickUiEvent
import com.shopkmp.mvvm.common.UiEvent

interface SingleClickService {
    fun isClickAllowed(event: UiEvent): Boolean
}
