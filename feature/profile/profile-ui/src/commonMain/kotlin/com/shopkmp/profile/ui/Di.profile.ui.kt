package com.shopkmp.profile.ui

import org.koin.core.module.dsl.viewModelOf
import org.koin.dsl.module

val moduleProfileUi = module {
    viewModelOf(::ProfileViewModel)
    viewModelOf(::EditProfileViewModel)
}
