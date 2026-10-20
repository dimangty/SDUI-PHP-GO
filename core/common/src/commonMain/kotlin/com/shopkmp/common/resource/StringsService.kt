package com.shopkmp.common.resource

import org.jetbrains.compose.resources.StringResource

interface StringsService {
    suspend fun getString(stringRes: StringResource): String
    suspend fun getString(stringRes: StringResource, args: List<String>): String
}
