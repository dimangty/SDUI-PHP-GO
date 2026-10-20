package com.shopkmp.common

import kotlinx.coroutines.MainScope
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.launchIn
import kotlinx.coroutines.flow.onEach

fun <T> Flow<T>.asCommonFlow(): CommonFlow<T> = CommonFlow(this)

class CommonFlow<T>(private val origin: Flow<T>) : Flow<T> by origin {
    private val scope = MainScope()

    fun watchSingle(block: (T) -> Unit): AutoCloseable {
        val job = onEach(block).launchIn(scope)
        return AutoCloseable { job.cancel() }
    }

    fun watch(block: (T) -> Unit): AutoCloseable {
        val job = onEach(block).launchIn(scope)
        return AutoCloseable { job.cancel() }
    }
}
