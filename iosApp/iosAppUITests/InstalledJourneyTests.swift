import XCTest
import Foundation

final class InstalledJourneyTests: UITestBase {
    private let api = JourneyVerifier()
    private var app: XCUIApplication!
    private let timeout: TimeInterval = 40

    func testRealCardJourney() {
        do {
            try runJourney()
        } catch let JourneyFailure.check(code) {
            XCTFail("E2E-CHECK: \(code)")
        } catch {
            XCTFail("E2E-CHECK: unexpected_error_body_withheld")
        }
    }

    private func runJourney() throws {
        let nonce = UUID().uuidString.replacingOccurrences(of: "-", with: "").lowercased()
        let email = "ios-\(nonce)@example.org"
        let password = "Mobile!9\(nonce)"
        let buyer = "iOS Buyer"
        let productName = "Тестовая лампа"
        let query = productName.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed)!
        let candidates = try api.get("/products/search?q=\(query)&size=20").objects("items")
        let matches = try candidates.filter { try $0.string("name") == productName && $0.bool("isActive") && $0.int("stock") > 0 }
        try check(matches.count == 1, "public_product_unique")
        let product = matches[0]
        let productId = try product.string("id")
        let price = try product.optionalString("discountPrice") ?? product.string("price")
        let stock = try product.int("stock")
        milestone("public_product_discovered")

        app = launchApp()
        // Reruns recover a prior interrupted test only through the installed UI.
        if !element("login.email").waitForExistence(timeout: 5) {
            if element("nav.profile").exists { try logout() }
            else if element("pin.secondary").exists { try tap("pin.secondary") }
        }
        _ = try node("login.email")
        try register(buyer: buyer, email: email, password: password)
        milestone("registered_native_pin_visible")
        try api.login(email: email, password: password)
        try check(try api.get("/users/me").string("fullName") == buyer, "registered_profile_identity")
        try configureStrongPin()
        try assertHome(buyer: buyer, email: email)
        let devices = try api.array("/auth/pin/devices")
        try check(devices.count == 1, "one_persisted_pin_device")
        let deviceName = try devices[0].string("deviceName")
        milestone("pin_home_identity_verified")

        try logout()
        try type("login.email", email)
        try type("login.password", password)
        try tap("login.submit")
        try assertHome(buyer: buyer, email: email)
        milestone("password_login_home_verified")

        try tap("nav.catalog")
        _ = try node("catalog.title")
        try type("catalog.q", productName, scroll: "sdui.vertical")
        try tap("catalog.search", scroll: "sdui.vertical")
        try tap("product.\(productId).open", scroll: "sdui.vertical")
        try check(try text("product.title") == productName, "product_title")
        try reveal("product.price")
        try check(try text("product.price") == "\(price) ₽", "product_exact_price")
        try screenshot("product")
        try tap("product.buy", scroll: "sdui.vertical")
        try wait("cart_add_persisted") {
            let items = try self.api.get("/cart").objects("items")
            return try items.count == 1 && items[0].string("productId") == productId && items[0].int("quantity") == 1
        }
        try tap("nav.cart")
        _ = try node("cart.title")
        try reveal("cart.\(productId)")
        try check(app.staticTexts[productName].firstMatch.exists, "cart_product_visible")
        try check(try api.get("/cart").string("totalAmount") == price, "cart_exact_total")
        milestone("catalog_product_cart_verified")

        try fillCheckout()
        try tap("checkout.button", scroll: "sdui.vertical")
        try visibleText("Оформить заказ?")
        try check(try api.get("/orders").int("totalItems") == 0, "no_order_before_confirmation")
        try screenshot("checkout-confirmation")
        milestone("confirmation_zero_orders_verified")
        try visibleAction("Оформить").tap()
        try wait("one_native_order_created") { try self.api.get("/orders").int("totalItems") == 1 }
        let orders = try api.get("/orders").objects("items")
        try check(orders.count == 1, "one_native_order")
        let order = orders[0]
        let orderId = try order.string("id")
        let paymentId = try order.string("paymentId")
        try check(try order.string("status") == "CREATED", "native_order_created")
        try assertCanonicalOrder(orderId: orderId, productId: productId, price: price, stock: stock)
        milestone("native_checkout_canonical_verified")

        try completeSafariPayment(paymentId: paymentId, orderId: orderId)
        try wait("captured_paid") {
            try self.api.get("/payments/\(paymentId)").string("status") == "CAPTURED" &&
                self.api.get("/orders/\(orderId)").string("status") == "PAID"
        }
        let payment = try api.get("/payments/\(paymentId)")
        try check(try payment.string("id") == paymentId, "captured_payment_identity")
        try check(try payment.string("orderId") == orderId, "captured_payment_order")
        try check(try payment.string("method") == "CARD", "captured_payment_method")
        try check(try payment.string("amount") == price, "captured_payment_exact_amount")
        try check(try !payment.string("transactionId").isEmpty, "captured_payment_transaction")
        milestone("safari_captured_paid_verified")
        try returnFromSafari()
        try inspectPaidOrder(orderId: orderId, productId: productId, productName: productName, deviceName: deviceName)
        milestone("paid_order_details_devices_verified")
        try logout()
        milestone("logout_ui_login_verified")
        app.terminate()
        app = launchApp()
        try check(element("login.email").waitForExistence(timeout: timeout), "relaunch_login")
        try check(!element("nav.profile").exists, "logout_no_authenticated_navigation")
        try check(try api.get("/orders").int("totalItems") == 1, "final_one_order")
        try check(try api.get("/orders/\(orderId)").string("status") == "PAID", "final_paid_order")
        try assertCanonicalOrder(orderId: orderId, productId: productId, price: price, stock: stock)
        milestone("logout_relaunch_login_verified")
        // IDs are nonsecret canonical business identifiers; never expose capability URLs.
        print("E2E-ORDER: \(orderId) product=\(productId) total=\(price) payment=CAPTURED order=PAID")
        milestone("full_journey_pass")
    }

    private func register(buyer: String, email: String, password: String) throws {
        try tap("login.signup")
        try type("signup.name", buyer, scroll: "signup.scroll")
        try type("signup.email", email, scroll: "signup.scroll")
        try type("signup.phone", "+79991234567", scroll: "signup.scroll")
        try type("signup.password", password, scroll: "signup.scroll")
        try type("signup.passwordRepeat", password, scroll: "signup.scroll")
        try tap("signup.terms", scroll: "signup.scroll")
        try tap("signup.submit", scroll: "signup.scroll")
        _ = try node("pin.submit")
    }

    private func configureStrongPin() throws {
        var digits: [Int]
        repeat {
            digits = Array(0...9).shuffled().prefix(6).map { $0 }
        } while !(2...8).contains((digits[1] - digits[0] + 10) % 10)
        for digit in digits { try tap("pin.digit.\(digit)") }
        try wait("six_digit_native_pin_filled") { !self.element("pin.digit.0").isEnabled }
        try tap("pin.submit")
        if !element("home.title").waitForExistence(timeout: timeout) {
            probePin()
            print("E2E-PIN: persisted_count=\(try api.array("/auth/pin/devices").count)")
            throw JourneyFailure.check("pin_home_transition")
        }
    }

    private func probePin() {
        for label in ["PIN already set", "PIN must not be common", "PIN must contain", "deviceName"] {
            let match = app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH %@", label)).firstMatch
            print("E2E-PIN: \(label.replacingOccurrences(of: " ", with: "_")) exists=\(match.exists)")
        }
    }

    private func probeBrowser(_ safari: XCUIApplication) {
        for label in ["Payment completed", "CORS_ORIGIN_DENIED", "Payment failed", "Return to ShopKMP", "Complete payment"] {
            let match = safari.descendants(matching: .any).matching(NSPredicate(format: "label == %@", label)).firstMatch
            print("E2E-BROWSER: \(label.replacingOccurrences(of: " ", with: "_")) exists=\(match.exists) type=\(match.exists ? match.elementType.rawValue : 0)")
        }
    }

    private func assertHome(buyer: String, email: String) throws {
        _ = try node("home.title")
        try reveal("home.profile")
        try check(app.staticTexts[buyer].firstMatch.exists, "home_buyer_identity")
        try check(app.staticTexts[email].firstMatch.exists, "home_account_identity")
    }

    private func fillCheckout() throws {
        for (id, value) in [("city", "Moscow"), ("street", "Mobile Journey"), ("house", "5"), ("apartment", "1")] {
            try type("checkout.\(id)", value, scroll: "sdui.vertical")
        }
        try tap("checkout.paymentMethod.option.CARD", scroll: "sdui.vertical")
        let card = try node("checkout.paymentMethod.option.CARD")
        // CMP selection is exposed as the native selected trait/value, queried on-device.
        print("E2E-SELECT: card_type=\(card.elementType.rawValue) selected=\(card.isSelected) value_one=\((card.value as? String) == "1")")
        try check(card.isSelected || (card.value as? String) == "1", "card_native_selection")
    }

    private func assertCanonicalOrder(orderId: String, productId: String, price: String, stock: Int) throws {
        let detail = try api.get("/orders/\(orderId)")
        try check(try detail.string("id") == orderId, "canonical_order_identity")
        try check(try detail.string("paymentMethod") == "CARD", "canonical_card_method")
        try check(try detail.string("totalAmount") == price, "canonical_exact_total")
        let items = try detail.objects("items")
        try check(items.count == 1, "canonical_one_order_item")
        try check(try items[0].string("productId") == productId, "canonical_product")
        try check(try items[0].int("quantity") == 1, "canonical_quantity")
        try check(try items[0].string("priceSnapshot") == price, "canonical_exact_price_snapshot")
        let address = try detail.string("deliveryAddress")
        try check(address == "Moscow, Mobile Journey, 5, 1", "canonical_exact_address")
        try check(try api.get("/cart").objects("items").isEmpty, "canonical_empty_cart")
        try check(try api.get("/cart").string("totalAmount") == "0.00", "canonical_empty_cart_total")
        try check(try api.get("/products/\(productId)").int("stock") == stock - 1, "canonical_stock_decrement")
    }

    private func completeSafariPayment(paymentId: String, orderId: String) throws {
        let safari = XCUIApplication(bundleIdentifier: "com.apple.mobilesafari")
        try wait("external_safari_foreground") {
            if safari.state == .runningForeground { return true }
            // Only genuine visible external-browser consent buttons are handled.
            let springboard = XCUIApplication(bundleIdentifier: "com.apple.springboard")
            for title in ["Open", "Открыть", "Allow", "Разрешить"] {
                let button = springboard.alerts.buttons[title].firstMatch
                if button.exists { button.tap() }
            }
            return false
        }
        // First-launch Safari UI, if actually present. Never inspect the URL bar.
        for title in ["Continue", "Продолжить", "Not Now", "Не сейчас"] {
            let button = safari.buttons[title].firstMatch
            if button.exists { button.tap() }
        }
        let complete = safari.webViews.buttons["Complete payment"].firstMatch
        try check(complete.waitForExistence(timeout: timeout), "safari_complete_action_visible")
        try check(safari.state == .runningForeground && app.state != .runningForeground, "payment_external_browser")
        try wait("safari_complete_action_ready") { complete.isHittable && complete.isEnabled }
        complete.tap() // Exactly one payment submission, never automatically retried.
        let completed = safari.webViews.staticTexts["Payment completed"].firstMatch
        var sentNativePrompt = false
        let completedExpectation = XCTNSPredicateExpectation(predicate: NSPredicate { _, _ in
            for title in ["Send", "Send Anyway", "Отправить", "Все равно отправить"] {
                let prompt = safari.buttons[title].firstMatch
                if prompt.exists && prompt.isHittable {
                    prompt.tap()
                    sentNativePrompt = true
                }
            }
            return completed.exists
        }, object: nil)
        let found = XCTWaiter.wait(for: [completedExpectation], timeout: timeout) == .completed
        if sentNativePrompt { milestone("native_send_prompt_handled") }
        if !found {
            probeBrowser(safari)
            print("E2E-BROWSER-UI: alerts=\(safari.alerts.count) sheets=\(safari.sheets.count) webviews=\(safari.webViews.count)")
            let paymentState = try api.get("/payments/\(paymentId)").string("status")
            let orderState = try api.get("/orders/\(orderId)").string("status")
            if ["AUTHORIZED", "CAPTURED", "FAILED"].contains(paymentState) && ["CREATED", "PAID", "CANCELLED"].contains(orderState) {
                print("E2E-PAYMENT: payment=\(paymentState) order=\(orderState)")
            }
        }
        try check(found, "safari_payment_completed")
    }

    private func returnFromSafari() throws {
        let safari = XCUIApplication(bundleIdentifier: "com.apple.mobilesafari")
        let action = safari.webViews.links["Return to ShopKMP"].firstMatch
        try check(action.waitForExistence(timeout: timeout), "safari_return_link_visible")
        action.tap()
        try wait("return_link_app_foreground") {
            for owner in [safari, XCUIApplication(bundleIdentifier: "com.apple.springboard")] {
                for title in ["Open", "Открыть"] {
                    let button = owner.buttons[title].firstMatch
                    if button.exists && button.isHittable {
                        button.tap()
                        self.milestone("native_open_prompt_handled")
                    }
                }
            }
            return self.app.state == .runningForeground
        }
        _ = try node("nav.profile")
    }

    private func inspectPaidOrder(orderId: String, productId: String, productName: String, deviceName: String) throws {
        try tap("nav.profile")
        _ = try node("profile.title")
        try tap("profile.orders", scroll: "sdui.vertical")
        _ = try node("orders.title")
        let id = "order.\(orderId).open"
        try reveal(id)
        let card = try node(id)
        try check(card.label.contains("PAID") || card.descendants(matching: .staticText)["PAID"].firstMatch.exists, "paid_specific_order_card")
        try screenshot("orders-paid")
        try tap(id)
        _ = try node("order.title")
        try reveal("order.item.\(productId).name")
        try check(try text("order.item.\(productId).name").contains(productName), "paid_detail_product")
        try screenshot("order-detail")
        try tap("nav.profile")
        _ = try node("profile.title")
        try tap("profile.devices", scroll: "sdui.vertical")
        _ = try node("devices.title")
        try check(!element("devices.empty").exists, "devices_nonempty")
        try check(app.staticTexts[deviceName].firstMatch.waitForExistence(timeout: timeout), "persisted_device_visible")
        try check(try api.array("/auth/pin/devices").count == 1, "final_one_pin_device")
    }

    private func logout() throws {
        try tap("nav.profile")
        _ = try node("profile.title")
        try tap("profile.logout.button", scroll: "sdui.vertical")
        _ = try node("login.email")
    }

    private func element(_ id: String) -> XCUIElement {
        app.descendants(matching: .any).matching(identifier: id).firstMatch
    }

    private func node(_ id: String) throws -> XCUIElement {
        let result = element(id)
        try check(result.waitForExistence(timeout: timeout), "visible_\(id)")
        return result
    }

    private func tap(_ id: String, scroll: String? = nil) throws {
        if let scroll = scroll { try reveal(id, scroll: scroll) }
        let target = try node(id)
        try wait("enabled_\(id)") { target.isEnabled && target.isHittable }
        target.tap()
    }

    private func type(_ id: String, _ value: String, scroll: String? = nil) throws {
        if let scroll = scroll { try reveal(id, scroll: scroll) }
        let target = try node(id)
        // Query actual native type; do not dump labels/values or credential hierarchy.
        print("E2E-TYPE: \(id) native=\(target.elementType.rawValue)")
        target.tap()
        // XCTest logs typeText values internally. The runner withholds all raw
        // activities and destroys the temporary xcresult after safe extraction.
        app.typeText(value)
        if app.keyboards.firstMatch.exists {
            let key = app.keyboards.buttons.matching(NSPredicate(format: "identifier IN %@ OR label IN %@", ["Return", "Done", "return", "Готово", "Ввод"], ["Return", "Done", "return", "Готово", "Ввод"])).firstMatch
            if key.exists { key.tap() }
        }
    }

    private func reveal(_ id: String, scroll: String = "sdui.vertical") throws {
        let deadline = Date().addingTimeInterval(timeout)
        while Date() < deadline {
            let target = element(id)
            if target.exists && target.isHittable { return }
            let container = try node(scroll)
            container.swipeUp()
        }
        throw JourneyFailure.check("scroll_\(id)")
    }

    private func text(_ id: String) throws -> String {
        let target = try node(id)
        return target.label
    }

    private func visibleText(_ title: String) throws {
        try check(app.staticTexts[title].firstMatch.waitForExistence(timeout: timeout), "native_confirmation_visible")
    }

    private func visibleAction(_ title: String) throws -> XCUIElement {
        let result = app.buttons[title].firstMatch
        try check(result.waitForExistence(timeout: timeout), "native_confirmation_action")
        return result
    }

    private func wait(_ code: String, condition: @escaping () throws -> Bool) throws {
        var failure: Error?
        let predicate = NSPredicate { _, _ in
            do { return try condition() }
            catch { failure = error; return true }
        }
        let expectation = XCTNSPredicateExpectation(predicate: predicate, object: nil)
        let result = XCTWaiter.wait(for: [expectation], timeout: timeout)
        if let failure = failure { throw failure }
        try check(result == .completed, code)
    }

    private func check(_ value: Bool, _ code: String) throws {
        if !value { throw JourneyFailure.check(code) }
    }

    private func milestone(_ code: String) {
        XCTContext.runActivity(named: "E2E-MILESTONE: \(code)") { _ in }
        print("E2E-MILESTONE: \(code)")
    }

    private func screenshot(_ name: String) throws {
        try check(app.state == .runningForeground, "screenshot_app_foreground")
        let attachment = XCTAttachment(screenshot: app.screenshot())
        attachment.name = "e2e-\(name)"
        attachment.lifetime = .keepAlways
        add(attachment)
    }
}

private enum JourneyFailure: Error {
    case check(String)
}

private struct JourneyObject {
    let value: [String: Any]
    func string(_ key: String) throws -> String {
        guard let result = value[key] as? String else { throw JourneyFailure.check("api_string_\(key)") }
        return result
    }
    func optionalString(_ key: String) -> String? { value[key] as? String }
    func int(_ key: String) throws -> Int {
        guard let result = value[key] as? Int else { throw JourneyFailure.check("api_integer_\(key)") }
        return result
    }
    func bool(_ key: String) throws -> Bool {
        guard let result = value[key] as? Bool else { throw JourneyFailure.check("api_boolean_\(key)") }
        return result
    }
    func objects(_ key: String) throws -> [JourneyObject] {
        guard let result = value[key] as? [[String: Any]] else { throw JourneyFailure.check("api_array_\(key)") }
        return result.map { JourneyObject(value: $0) }
    }
}

/** Independent real password authentication. No app tokens, storage or DI access.
 * Business requests are GET-only; no payment capability access or mutation helper.
 */
private final class JourneyVerifier {
    private var token = ""
    private let session = URLSession(configuration: .ephemeral)

    func login(email: String, password: String) throws {
        let response = try request("/auth/login", login: ["email": email, "password": password])
        guard let object = response as? [String: Any], let access = object["accessToken"] as? String, !access.isEmpty else {
            throw JourneyFailure.check("verifier_login_body_withheld")
        }
        token = access
    }

    func get(_ path: String) throws -> JourneyObject {
        guard let object = try request(path) as? [String: Any] else { throw JourneyFailure.check("api_object_body_withheld") }
        return JourneyObject(value: object)
    }

    func array(_ path: String) throws -> [JourneyObject] {
        guard let objects = try request(path) as? [[String: Any]] else { throw JourneyFailure.check("api_array_body_withheld") }
        return objects.map { JourneyObject(value: $0) }
    }

    private func request(_ path: String, login: [String: String]? = nil) throws -> Any {
        guard path.hasPrefix("/"), !path.contains("://"), login == nil || path == "/auth/login",
              let url = URL(string: "http://127.0.0.1:58095/api/v1\(path)") else { throw JourneyFailure.check("api_path") }
        var request = URLRequest(url: url, timeoutInterval: 15)
        request.httpMethod = login == nil ? "GET" : "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        if !token.isEmpty { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        if let login = login { request.httpBody = try JSONSerialization.data(withJSONObject: login) }
        let done = DispatchSemaphore(value: 0)
        var received: Data?
        var status = 0
        var failed = false
        let task = session.dataTask(with: request) { data, response, error in
            received = data
            status = (response as? HTTPURLResponse)?.statusCode ?? 0
            failed = error != nil
            done.signal()
        }
        task.resume()
        guard done.wait(timeout: .now() + 20) == .success else { task.cancel(); throw JourneyFailure.check("api_timeout") }
        guard !failed, status == 200, let data = received else { throw JourneyFailure.check("api_http_\(status)_body_withheld") }
        do { return try JSONSerialization.jsonObject(with: data) }
        catch { throw JourneyFailure.check("api_json_body_withheld") }
    }
}
