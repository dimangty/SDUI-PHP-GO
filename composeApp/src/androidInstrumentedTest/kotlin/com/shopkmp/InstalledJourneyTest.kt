package com.shopkmp

import android.os.Bundle
import android.os.SystemClock
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
    private val api = Verifier("http://10.0.2.2:58095")
    private val timeout = 40_000L

    @Test fun realCardJourney() {
        val run = UUID.randomUUID().toString().replace("-", "")
        val email = "android-$run@example.org"
        val password = "Mobile!9$run"
        val buyer = "Android Buyer"
        val productName = "Тестовая лампа"
        val products = api.get("/products/search?q=${URLEncoder.encode(productName, "UTF-8")}&size=20").getJSONArray("items")
        val product = (0 until products.length()).map(products::getJSONObject)
            .single { it.getString("name") == productName && it.getBoolean("isActive") && it.getInt("stock") > 0 }
        val productId = product.getString("id")
        val price = if (product.isNull("discountPrice")) product.getString("price") else product.getString("discountPrice")
        val stock = product.getInt("stock")

        launch()
        node("login.email")
        click("login.signup")
        type("signup.name", buyer, "signup.scroll")
        type("signup.email", email, "signup.scroll")
        type("signup.phone", "+79991234567", "signup.scroll")
        type("signup.password", password, "signup.scroll")
        type("signup.passwordRepeat", password, "signup.scroll")
        click("signup.terms", "signup.scroll")
        click("signup.submit", "signup.scroll")
        node("pin.submit")
        milestone("UI registration reached native PIN setup")

        // Separate real login for assertions. Its tokens never enter app storage/DI.
        api.login(email, password)
        assertEquals(buyer, api.get("/users/me").getString("fullName"))
        val random = SecureRandom()
        // Fresh strong input: six distinct digits and a nonsequential first pair.
        // No DOB is supplied for this account; don't randomly sample weak PINs.
        val pin = generateSequence { List(6) { random.nextInt(10) } }
            .first { it.distinct().size == 6 && (it[1] - it[0] + 10) % 10 in 2..8 }
        pin.forEach { click("pin.digit.$it") }
        click("pin.submit")
        await("native PIN setup reached Home") {
            assertFalse("Generated PIN rejected by server policy", device.hasObject(By.textContains("PIN must not be common")))
            device.hasObject(By.res("home.title"))
        }
        node("home.title")
        reveal("home.profile")
        assertTrue("Home renders the authenticated buyer", device.hasObject(By.text(buyer)))
        assertTrue("Home renders this account's identity", device.hasObject(By.text(email)))
        val pins = api.array("/auth/pin/devices")
        assertEquals("UI PIN setup persisted one device", 1, pins.length())
        milestone("Authenticated Home SDUI and persisted PIN device verified")

        // Also prove password login using the account registered through the UI.
        logout()
        type("login.email", email)
        type("login.password", password)
        click("login.submit")
        node("home.title")
        reveal("home.profile")
        assertTrue("Password login restores authenticated Home", device.hasObject(By.text(buyer)))
        assertTrue("Password login restores this account's identity", device.hasObject(By.text(email)))
        milestone("UI logout and real password login returned to authenticated Home")

        click("nav.catalog")
        node("catalog.title")
        type("catalog.q", productName)
        click("catalog.search", "sdui.vertical")
        reveal("product.$productId.open")
        click("product.$productId.open")
        assertEquals(productName, node("product.title").text)
        reveal("product.price")
        assertEquals("Product renders public API price", "$price ₽", node("product.price").text)
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

        type("checkout.city", "Moscow", "sdui.vertical")
        type("checkout.street", "Mobile Journey", "sdui.vertical")
        type("checkout.house", "5", "sdui.vertical")
        type("checkout.apartment", "1", "sdui.vertical")
        click("checkout.paymentMethod.option.CARD", "sdui.vertical")
        await("CARD selection exposed by platform accessibility") {
            val card = node("checkout.paymentMethod.option.CARD")
            card.isChecked || card.isSelected
        }
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
            "Browser CARD POST rejected: CORS_ORIGIN_DENIED for Origin http://10.0.2.2:58095"
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
        // Scope to the actual order, not the status filter (which also contains PAID).
        assertTrue("Order card displays canonical payment outcome",
            orderCard.text.orEmpty().contains(expectedOrderStatus) || orderCard.findObject(By.text(expectedOrderStatus)) != null)
        screenshot("orders-${expectedOrderStatus.lowercase()}")
        click("order.$orderId.open")
        node("order.title")
        reveal("order.item.$productId.name")
        assertTrue("Order detail renders purchased lamp", node("order.item.$productId.name").text.contains(productName))
        screenshot("order-detail")
        click("nav.profile")
        node("profile.title")
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

    private fun reveal(id: String, scroll: String = "sdui.vertical") {
        // Scroll until the lazy item/action is visible, not a fixed delay or coordinate tap.
        val deadline = SystemClock.elapsedRealtime() + timeout
        while (SystemClock.elapsedRealtime() < deadline) {
            try {
                val target = device.findObject(By.res(id))
                if (target != null && target.visibleBounds.height() > 20) return
                // Network navigation can replace the entire lazy list between gestures.
                // Reacquire its accessibility node rather than retaining an old document.
                node(scroll).scroll(Direction.DOWN, 0.65f)
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
        action.click()
        val resultDeadline = SystemClock.elapsedRealtime() + timeout
        while (SystemClock.elapsedRealtime() < resultDeadline) {
            if (device.hasObject(By.text("Payment completed"))) return true
            if (device.hasObject(By.textContains("CORS_ORIGIN_DENIED"))) return false
            device.wait(Until.hasObject(By.text("Payment completed")), 300)
        }
        throw AssertionError("Timed out waiting for gateway completed state; no payment action retry")
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
