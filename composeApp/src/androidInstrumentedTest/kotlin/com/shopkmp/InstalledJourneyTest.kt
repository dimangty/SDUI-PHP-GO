package com.shopkmp

import android.os.Bundle
import android.os.SystemClock
import android.view.accessibility.AccessibilityNodeInfo
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import androidx.test.uiautomator.By
import androidx.test.uiautomator.BySelector
import androidx.test.uiautomator.Direction
import androidx.test.uiautomator.StaleObjectException
import androidx.test.uiautomator.UiDevice
import androidx.test.uiautomator.UiObject2
import androidx.test.uiautomator.Until
import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import java.security.SecureRandom
import java.util.UUID

/** Installed app + shared development edge. All commerce mutations happen through UI.
 * The independent password-authenticated verifier only reads business state. Credentials
 * stay in memory; never dump hierarchy, request/response bodies, or browser screenshots.
 */
@RunWith(AndroidJUnit4::class)
class InstalledJourneyTest {
    private val instrumentation = InstrumentationRegistry.getInstrumentation()
    private val context = instrumentation.targetContext
    private val device = UiDevice.getInstance(instrumentation)
    private val api = Verifier(requireNotNull(InstrumentationRegistry.getArguments().getString("backendServerUrl")))
    private val timeout = 40_000L
    private val allowedScreenshots = setOf(
        "login", "registration", "pin-create-partial-2", "pin-login-partial-3", "home",
        "product", "cart-checkout", "orders-paid", "orders-created", "profile", "profile-actions", "edit-profile",
        "checkout-confirmation", "order-detail",
    )

    @Test fun realCardJourney() {
        val run = UUID.randomUUID().toString().replace("-", "")
        val email = "android-$run@example.org"
        // Public synthetic email IDs must not reveal a password derivation.
        val password = "Mobile!9${UUID.randomUUID()}"
        val buyer = "Android Buyer ${run.take(4)}"
        val productName = "Тестовая лампа"
        val products = api.get("/products/search?q=${URLEncoder.encode(productName, "UTF-8")}&size=20").getJSONArray("items")
        val product = (0 until products.length()).map(products::getJSONObject)
            .single { it.getString("name") == productName && it.getBoolean("isActive") && it.getInt("stock") > 0 }
        val productId = product.getString("id")
        val price = if (product.isNull("discountPrice")) product.getString("price") else product.getString("discountPrice")
        val stock = product.getInt("stock")

        launch()
        node("login.email")
        assertTrue("Login capture has empty inputs", listOf("login.email", "login.password").all { node(it).text.orEmpty().isEmpty() })
        screenshot("login")
        click("login.signup")
        node("signup.name")
        assertTrue("Registration capture has empty inputs", listOf("signup.name", "signup.email", "signup.phone", "signup.password", "signup.passwordRepeat").all { node(it).text.orEmpty().isEmpty() })
        screenshot("registration")
        type("signup.name", buyer, "signup.scroll")
        type("signup.email", email, "signup.scroll")
        type("signup.phone", "+79991234567", "signup.scroll")
        type("signup.password", password, "signup.scroll")
        type("signup.passwordRepeat", password, "signup.scroll")
        click("signup.terms", "signup.scroll")
        click("signup.submit", "signup.scroll")
        node("pin.header")
        milestone("UI registration reached native PIN setup")

        // Separate real login for assertions. Its tokens never enter app storage/DI.
        api.login(email, password)
        assertEquals(buyer, api.get("/users/me").getString("fullName"))
        val pin = configureStrongPin()
        node("home.title")
        assertProfileIdentity(buyer, email)
        val pins = api.array("/auth/pin/devices")
        assertEquals("UI PIN setup persisted one device", 1, pins.length())
        milestone("Authenticated Home SDUI and persisted PIN device verified")

        // Also prove password login using the account registered through the UI.
        logout()
        loginWithPin(pin, buyer, email)
        milestone("UI six-digit PIN login and actual Profile identity verified")
        logout()
        type("login.email", email)
        type("login.password", password)
        click("login.submit")
        node("home.title")
        assertProfileIdentity(buyer, email)
        milestone("UI logout and real password login returned to authenticated Home")
        api.login(email, password) // Refresh the independent observer before commerce.
        screenshot("home")

        click("nav.catalog")
        node("catalog.title")
        type("catalog.q", productName)
        click("catalog.search", "sdui.vertical")
        reveal("product.$productId.open")
        click("product.$productId.open")
        assertEquals(productName, node("product.title").text)
        reveal("product.price")
        assertEquals("Product renders public API price", rubDisplay(price), node("product.price").text)
        screenshot("product")
        click("product.buy", "sdui.vertical")
        await("UI AddToCart persisted exactly one lamp") {
            val items = api.get("/cart").getJSONArray("items")
            items.length() == 1 && items.getJSONObject(0).getString("productId") == productId &&
                items.getJSONObject(0).getInt("quantity") == 1
        }
        click("nav.cart")
        node("cart.title")
        reveal("cart.$productId")
        assertTrue("Cart renders selected lamp", device.hasObject(By.text(productName)))
        assertEquals(price, api.get("/cart").getString("totalAmount"))
        milestone("Catalog search → Product → AddToCart → Cart; canonical quantity/total verified")

        reveal("checkout.address.edit")
        if (!device.hasObject(By.res("checkout.city"))) click("checkout.address.edit")
        type("checkout.city", "Moscow", "sdui.vertical")
        type("checkout.street", "Mobile Journey", "sdui.vertical")
        type("checkout.house", "5", "sdui.vertical")
        type("checkout.apartment", "1", "sdui.vertical")
        reveal("checkout.payment.edit")
        if (!device.hasObject(By.res("checkout.paymentMethod.option.CARD"))) click("checkout.payment.edit")
        click("checkout.paymentMethod.option.CARD", "sdui.vertical")
        await("CARD selection exposed by platform accessibility") {
            val card = node("checkout.paymentMethod.option.CARD")
            card.isChecked || card.isSelected
        }
        click("checkout.address.edit", "sdui.vertical")
        click("checkout.payment.edit", "sdui.vertical")
        reveal("cart.$productId", direction = Direction.UP)
        assertEquals("No order before cart capture", 0, api.get("/orders").getInt("totalItems"))
        screenshot("cart-checkout")
        click("checkout.button", "sdui.vertical")
        assertNotNull("Native checkout confirmation", device.wait(Until.findObject(By.text("Оформить заказ?")), timeout))
        assertEquals("Confirmation must precede order creation", 0, api.get("/orders").getInt("totalItems"))
        screenshot("checkout-confirmation")
        // Platform AlertDialog is a separate accessibility window; use its visible action.
        visible(By.text("Оформить"), "native checkout confirm").click()
        await("Native checkout created one order") { api.get("/orders").getInt("totalItems") == 1 }
        val order = api.get("/orders").getJSONArray("items").getJSONObject(0)
        val orderId = order.getString("id")
        val paymentId = order.getString("paymentId")
        assertEquals("CARD", order.getString("paymentMethod"))
        assertEquals("CREATED", order.getString("status"))
        assertEquals(price, order.getString("totalAmount"))
        val detail = api.get("/orders/$orderId")
        assertEquals(productId, detail.getJSONArray("items").getJSONObject(0).getString("productId"))
        assertEquals(1, detail.getJSONArray("items").getJSONObject(0).getInt("quantity"))
        assertTrue("Native checkout persisted delivery address", detail.getString("deliveryAddress").contains("Mobile Journey"))
        assertEquals(0, api.get("/cart").getJSONArray("items").length())
        assertEquals(stock - 1, api.get("/products/$productId").getInt("stock"))
        milestone("Native checkout confirmed: one CREATED CARD order, exact total, stock -1, empty cart; order=$orderId")

        val paymentFailure = if (completeBrowserPayment()) null else
            "Browser CARD POST rejected: CORS_ORIGIN_DENIED for configured disposable origin"
        if (paymentFailure == null) {
            await("Browser payment captured and order paid") {
                api.get("/payments/$paymentId").getString("status") == "CAPTURED" &&
                    api.get("/orders/$orderId").getString("status") == "PAID"
            }
            milestone("System browser Complete payment → canonical CAPTURED / PAID")
            // Return through the browser's app link; never read/log its capability URL.
            visible(By.text("Return to ShopKMP"), "gateway return action").click()
        } else {
            // Finish inspecting the app, but preserve the payment failure as the test result.
            assertEquals("AUTHORIZED", api.get("/payments/$paymentId").getString("status"))
            assertEquals("CREATED", api.get("/orders/$orderId").getString("status"))
            milestone("BLOCKED: $paymentFailure; canonical AUTHORIZED / CREATED; continuing app inspection")
            launch() // normal Android launcher entry, no deep-link/token injection
        }
        val expectedOrderStatus = if (paymentFailure == null) "PAID" else "CREATED"
        val expectedOrderLabel = if (paymentFailure == null) "Оплачен" else "Создан"
        await("Browser returns to installed app") {
            device.findObject(By.text("Open"))?.click()
            device.currentPackageName == context.packageName
        }
        node("nav.profile")
        click("nav.profile")
        node("profile.title")
        click("profile.orders", "sdui.vertical")
        node("orders.title")
        reveal("order.$orderId.open")
        val orderCard = node("order.$orderId.open")
        // Scope to the actual order, not the status filter with the same display labels.
        assertTrue("Specific order card displays localized payment outcome",
            orderCard.text.orEmpty().contains(expectedOrderLabel) || orderCard.findObject(By.textContains(expectedOrderLabel)) != null)
        screenshot("orders-${expectedOrderStatus.lowercase()}")
        click("order.$orderId.open")
        node("order.title")
        reveal("order.item.$productId.name")
        assertTrue("Order detail renders purchased lamp", node("order.item.$productId.name").text.contains(productName))
        screenshot("order-detail")
        click("nav.profile")
        node("profile.title")
        captureProfileAndEditor(buyer, email)
        click("profile.devices", "sdui.vertical")
        node("devices.title")
        assertFalse("PIN device is not empty", device.hasObject(By.res("devices.empty")))
        visible(By.text(pins.getJSONObject(0).getString("deviceName")), "persisted PIN device name")
        assertEquals(1, api.array("/auth/pin/devices").length())
        milestone("App Orders/$expectedOrderStatus → order detail → Profile → Devices verified")
        logout()
        // A fresh activity launch must still show Login, with no authenticated nav.
        device.pressHome()
        launch()
        node("login.email")
        assertFalse("Logout removes authenticated navigation", device.hasObject(By.res("nav.profile")))
        assertEquals(1, api.get("/orders").getInt("totalItems"))
        assertEquals(expectedOrderStatus, api.get("/orders/$orderId").getString("status"))
        milestone("Final logout stays at Login; order=$orderId product=$productId; payment=${if (paymentFailure == null) "CAPTURED" else "BLOCKED"}")
        assertNull("Full installed journey requires browser payment completion", paymentFailure)
        milestone("PASS Android full installed CARD journey")
    }

    private fun launch() {
        context.startActivity(context.packageManager.getLaunchIntentForPackage(context.packageName))
    }

    private fun configureStrongPin(): List<Int> {
        val random = SecureRandom()
        val pin = generateSequence { List(6) { random.nextInt(10) } }
            .first { it.distinct().size == 6 && (it[1] - it[0] + 10) % 10 in 2..8 }
        pin.forEachIndexed { index, digit ->
            click("pin.digit.$digit")
            if (index == 1) capturePartialPin(2, "pin-create-partial-2")
            if (index == 3) assertNoAutoFour()
        }
        await("six-digit setup PIN complete") { !node("pin.digit.0").isEnabled }
        click("pin.submit")
        await("native PIN setup reached Home") {
            assertFalse("Generated PIN rejected by server policy", device.hasObject(By.textContains("PIN must not be common")))
            device.hasObject(By.res("home.title"))
        }
        return pin
    }

    private fun capturePartialPin(count: Int, name: String) {
        assertFalse("Partial PIN does not expose submit", device.hasObject(By.res("pin.submit")))
        assertFalse("Partial PIN does not navigate", device.hasObject(By.res("home.title")))
        (0..9).forEach { assertTrue("Partial PIN keeps all keys enabled", node("pin.digit.$it").isEnabled) }
        val dots = findNativeNode { it.contentDescription?.toString() == "PIN-код" }
        assertTrue("Actual partial PIN count is exposed without digits", dots?.stateDescription?.toString() == "Введено цифр: $count")
        screenshot(name)
    }

    private fun assertNoAutoFour() {
        node("pin.submit")
        assertTrue("Four digits still await explicit submission", device.hasObject(By.res("pin.header")))
        assertFalse("Four digits never auto-submit", device.hasObject(By.res("home.title")))
    }

    private fun loginWithPin(pin: List<Int>, buyer: String, email: String) {
        click("login.pin")
        node("pin.header")
        pin.forEachIndexed { index, digit ->
            click("pin.digit.$digit")
            if (index == 2) capturePartialPin(3, "pin-login-partial-3")
            if (index == 3) assertNoAutoFour()
        }
        await("six-digit login PIN complete") { !node("pin.digit.0").isEnabled }
        click("pin.submit")
        node("home.title")
        assertProfileIdentity(buyer, email)
    }

    private fun findNativeNode(predicate: (AccessibilityNodeInfo) -> Boolean): AccessibilityNodeInfo? {
        fun visit(node: AccessibilityNodeInfo): AccessibilityNodeInfo? {
            if (predicate(node)) return node
            for (index in 0 until node.childCount) {
                node.getChild(index)?.let { visit(it)?.let { match -> return match } }
            }
            return null
        }
        return instrumentation.uiAutomation.rootInActiveWindow?.let(::visit)
    }

    private fun captureProfileAndEditor(buyer: String, email: String) {
        val before = api.get("/users/me")
        reveal("profile.header", direction = Direction.UP)
        screenshot("profile")
        reveal("profile.logout.button")
        val biometric = node("profile.biometric.button")
        milestone("VISUAL_BIOMETRIC checked=${biometric.isChecked || biometric.isSelected} enabled=${biometric.isEnabled}")
        screenshot("profile-actions")
        click("profile.edit.button")
        await("native editor name loaded") { node("editProfile.name").text == buyer }
        assertTrue("Editor has no host navigation", listOf("nav.home", "nav.catalog", "nav.cart", "nav.profile", "nav.refresh").none { device.hasObject(By.res(it)) })
        assertTrue("Editor email is loaded", node("editProfile.email").text == email)
        val emailNode = findNativeNode { it.viewIdResourceName == "editProfile.email" }
        assertTrue("Email has no native set-text action", emailNode != null && emailNode.actionList.none { it.id == AccessibilityNodeInfo.AccessibilityAction.ACTION_SET_TEXT.id })
        assertFalse("Photo action is honestly unavailable", node("editProfile.photo").isEnabled)
        visible(By.text("Изменение фото пока недоступно"), "photo capability caption")
        screenshot("edit-profile")
        click("editProfile.back")
        node("profile.title")
        val after = api.get("/users/me")
        assertTrue("Cancel did not mutate profile", listOf("fullName", "email", "phone", "defaultAddressId").all { before.optString(it) == after.optString(it) })
        milestone("Native editor readonly email, absent bottom nav and cancel without mutation verified")
    }

    private fun assertProfileIdentity(buyer: String, email: String) {
        click("home.profile")
        node("profile.title")
        reveal("profile.header")
        assertTrue("Profile renders the authenticated buyer", device.hasObject(By.text(buyer)))
        assertTrue("Profile renders this account's identity", device.hasObject(By.text(email)))
        click("nav.home")
        node("home.title")
    }

    private fun rubDisplay(wire: String): String {
        val (whole, fraction) = wire.split('.')
        return whole.reversed().chunked(3).joinToString(" ").reversed() + ",$fraction ₽"
    }

    private fun logout() {
        click("nav.profile")
        node("profile.title")
        click("profile.logout.button", "sdui.vertical")
        node("login.email")
    }

    private fun node(id: String): UiObject2 = visible(By.res(id), id)

    private fun visible(selector: BySelector, label: String): UiObject2 =
        device.wait(Until.findObject(selector), timeout) ?: throw AssertionError("Timed out waiting for $label")

    private fun click(id: String, scroll: String? = null) {
        if (scroll != null) reveal(id, scroll)
        visible(By.res(id).enabled(true), "$id enabled").click()
    }

    private fun type(id: String, value: String, scroll: String? = null) {
        if (scroll != null) reveal(id, scroll)
        node(id).text = value
        // Dismiss only the IME, never navigate Back from the form.
        if (device.hasObject(By.pkg("com.google.android.inputmethod.latin"))) {
            device.pressBack()
            assertTrue("Keyboard dismissed", device.wait(Until.gone(By.pkg("com.google.android.inputmethod.latin")), timeout))
        }
    }

    private fun reveal(id: String, scroll: String = "sdui.vertical", direction: Direction = Direction.DOWN) {
        // Scroll until the lazy item/action is visible, not a fixed delay or coordinate tap.
        val deadline = SystemClock.elapsedRealtime() + timeout
        while (SystemClock.elapsedRealtime() < deadline) {
            try {
                val target = device.findObject(By.res(id))
                if (target != null && target.visibleBounds.height() > 20) return
                // Network navigation can replace the entire lazy list between gestures.
                // Reacquire its accessibility node rather than retaining an old document.
                node(scroll).scroll(direction, 0.65f)
            } catch (_: StaleObjectException) {
                // Only the read/scroll is retried; never repeat a business action.
            }
            device.wait(Until.hasObject(By.res(id)), 300)
        }
        throw AssertionError("Timed out scrolling to $id")
    }

    private fun completeBrowserPayment(): Boolean {
        val complete = By.text("Complete payment")
        val deadline = SystemClock.elapsedRealtime() + timeout
        while (SystemClock.elapsedRealtime() < deadline && !device.hasObject(complete)) {
            // Chrome first-run UI varies by emulator image; only visible, allowlisted actions.
            listOf("Use without an account", "Accept & continue", "No thanks", "Got it").forEach { text ->
                device.findObject(By.text(text))?.click()
            }
            device.wait(Until.hasObject(complete), 500)
        }
        val action = visible(complete, "system browser demo CARD action")
        assertTrue("Payment runs outside app", action.applicationPackage != context.packageName)
        val tapBounds = action.visibleBounds
        milestone("BROWSER_TAP bounds=${tapBounds.left},${tapBounds.top},${tapBounds.width()},${tapBounds.height()}")
        action.click()
        val resultDeadline = SystemClock.elapsedRealtime() + timeout
        browserDiagnostics("after_click")
        while (SystemClock.elapsedRealtime() < resultDeadline) {
            if (device.hasObject(By.text("Payment completed"))) return true
            if (device.hasObject(By.textContains("CORS_ORIGIN_DENIED"))) return false
            device.wait(Until.hasObject(By.text("Payment completed")), 300)
        }
        browserDiagnostics("timeout")
        throw AssertionError("Timed out waiting for gateway completed state; no payment action retry")
    }

    private fun browserDiagnostics(stage: String) {
        milestone("BROWSER stage=$stage chrome=${device.currentPackageName == "com.android.chrome"}")
        val controls = listOf(
            "complete" to By.text("Complete payment"), "completed" to By.text("Payment completed"),
            "return" to By.text("Return to ShopKMP"), "send_anyway" to By.text("Send anyway"),
            "send_anyway_title" to By.text("Send Anyway"), "send" to By.text("Send"), "go_back" to By.text("Go back"),
            "continue" to By.text("Continue"), "no_thanks" to By.text("No thanks"), "got_it" to By.text("Got it"),
            "cors" to By.textContains("CORS_ORIGIN_DENIED"), "forbidden" to By.textContains("DEMO_PAYMENT_FORBIDDEN"),
            "url_bar" to By.res("com.android.chrome:id/url_bar"), "webview" to By.clazz("android.webkit.WebView"),
        )
        controls.forEach { (code, selector) ->
            val node = device.findObject(selector)
            val rect = node?.visibleBounds
            milestone("BROWSER_CONTROL stage=$stage code=$code exists=${node != null} enabled=${node?.isEnabled == true} clickable=${node?.isClickable == true} bounds=${rect?.let { "${it.left},${it.top},${it.width()},${it.height()}" } ?: "0,0,0,0"}")
        }
        device.findObjects(By.pkg("com.android.chrome").clickable(true)).take(20).forEach { node ->
            val resource = node.resourceName?.takeIf { Regex("com\\.android\\.chrome:id/[a-zA-Z0-9_]+").matches(it) } ?: "web_control"
            val rect = node.visibleBounds
            milestone("BROWSER_CLICK stage=$stage resource=$resource enabled=${node.isEnabled} bounds=${rect.left},${rect.top},${rect.width()},${rect.height()}")
        }
    }

    private fun await(label: String, predicate: () -> Boolean) {
        val deadline = SystemClock.elapsedRealtime() + timeout
        while (SystemClock.elapsedRealtime() < deadline) {
            if (predicate()) return
            SystemClock.sleep(300) // bounded condition polling; never a timing-based assertion
        }
        throw AssertionError("Timed out waiting for $label")
    }

    private fun milestone(message: String) {
        instrumentation.sendStatus(0, Bundle().apply { putString("stream", "E2E: $message\n") })
    }

    private fun screenshot(name: String) {
        assertTrue("Screenshot uses the explicit safe export list", name in allowedScreenshots)
        assertEquals("Capture app screens only", context.packageName, device.currentPackageName)
        val directory = File(context.getExternalFilesDir(null), "android-e2e").apply { mkdirs() }
        assertTrue("Nonsecret screenshot captured", device.takeScreenshot(File(directory, "$name.png")))
    }
}

/** No app session access. No business writes or demo capability requests are available here. */
private class Verifier(private val origin: String) {
    private var token = ""

    fun login(email: String, password: String) {
        val response = request("POST", "/auth/login", JSONObject().put("email", email).put("password", password))
        token = try {
            JSONObject(response).getString("accessToken").also { require(it.isNotBlank()) }
        } catch (_: Exception) {
            // org.json parse errors can include the input. Never attach the token response.
            throw AssertionError("Verifier login token response invalid (body withheld)")
        }
    }

    fun get(path: String) = JSONObject(request("GET", path))
    fun array(path: String) = JSONArray(request("GET", path))

    private fun request(method: String, path: String, body: JSONObject? = null): String {
        require(path.startsWith("/") && !path.contains("://"))
        require(method == "GET" || path == "/auth/login")
        val connection = URL("$origin/api/v1$path").openConnection() as HttpURLConnection
        try {
            connection.requestMethod = method
            connection.connectTimeout = 15_000
            connection.readTimeout = 30_000
            connection.setRequestProperty("Content-Type", "application/json")
            if (token.isNotEmpty()) connection.setRequestProperty("Authorization", "Bearer $token")
            if (body != null) {
                connection.doOutput = true
                connection.outputStream.use { it.write(body.toString().toByteArray()) }
            }
            assertEquals("Verifier $method ${path.substringBefore('?')} HTTP status (body withheld)", 200, connection.responseCode)
            return connection.inputStream.bufferedReader().use { it.readText() }
        } finally {
            connection.disconnect()
        }
    }
}
