// Simulator test isolation only. No token/PIN reads or business API operations.
// A prior run's real PIN is bound to its globally unique device UUID. Clear just
// that nonsecret local identifier so the app generates its normal fresh UUID.
import Foundation
import Security

let query: [String: Any] = [
    kSecClass as String: kSecClassGenericPassword,
    kSecAttrService as String: "shopkmp",
    kSecAttrAccount as String: "backend_device_id",
]
let status = SecItemDelete(query as CFDictionary)
guard status == errSecSuccess || status == errSecItemNotFound else {
    print("Installation identity reset failed: Security status \(status)")
    exit(1)
}
print("Reset only nonsecret shopkmp/backend_device_id; session tokens preserved")
