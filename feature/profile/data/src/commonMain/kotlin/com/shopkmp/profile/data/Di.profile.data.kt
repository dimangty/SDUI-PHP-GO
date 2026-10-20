package com.shopkmp.profile.data

import com.shopkmp.profile.domain.ProfileRepository
import org.koin.core.module.dsl.singleOf
import org.koin.dsl.bind
import org.koin.dsl.module

val moduleProfileData = module {
    singleOf(::BackendProfileRepository) bind ProfileRepository::class
}
