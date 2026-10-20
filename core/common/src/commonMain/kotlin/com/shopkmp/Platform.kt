package com.shopkmp

interface Platform {
    val name: String
}

expect fun getPlatform(): Platform