package com.shopkmp.common.resource

import org.jetbrains.compose.resources.StringResource

class StringsServiceImpl : StringsService {
    override suspend fun getString(stringRes: StringResource): String {
        return org.jetbrains.compose.resources.getString(stringRes)
    }

    @Suppress("SpreadOperator")
    override suspend fun getString(stringRes: StringResource, args: List<String>): String {
        return org.jetbrains.compose.resources.getString(stringRes, *args.toTypedArray())
    }
}
