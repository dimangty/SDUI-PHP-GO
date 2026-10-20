# ADR 0002: client secret storage lifecycle

- Status: accepted
- Date: 2026-07-26

Android stores session secrets with Tink AEAD. Ciphertexts are kept in private
SharedPreferences and authenticated with the logical setting key; the wrapping
key is non-exportable in Android Keystore. A one-release migration reader
decrypts the former `EncryptedSharedPreferences` values, immediately
re-encrypts them with Tink and deletes the legacy file. The deprecated AndroidX
dependency may be removed after that compatibility window.

iOS uses the project-owned `SecurityKeychainSettings` adapter directly on
Security.framework (`kSecClassGenericPassword`) with
`kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`. No experimental
multiplatform-settings Keychain implementation remains. Feature code depends
only on `AppSettings`, and iOS compilation is part of the verification gate.
