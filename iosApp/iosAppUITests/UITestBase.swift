import XCTest

class UITestBase: XCTestCase {
    func launchApp() -> XCUIApplication {
        continueAfterFailure = false
        let app = XCUIApplication(bundleIdentifier: "com.shopkmp.ios")
        app.launch()
        return app
    }
}
