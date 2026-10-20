package com.shopkmp.common.device

import android.content.Context
import android.content.Intent
import android.net.Uri
import org.koin.core.context.GlobalContext

actual class DeviceService {
    actual fun openUrl(url: String) {
        val uri = runCatching { Uri.parse(url) }.getOrNull() ?: return
        if (uri.scheme != "https" && uri.scheme != "http") return
        val context = GlobalContext.get().get<Context>()
        val intent = Intent(Intent.ACTION_VIEW, uri).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        runCatching { context.startActivity(intent) }
    }

    actual fun isIOS(): Boolean = false

    actual val osType: OsType = OsType.Android
}
