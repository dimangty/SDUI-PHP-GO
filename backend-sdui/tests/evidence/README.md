# Real HTTP integration evidence

Generated only by the opt-in `TestLiveYiiEightScreensAndActions` after the whole
journey passes. `manifest.json` records actual HTTP steps/status/body hashes;
screen and business JSON files come from the running services and their SQL
data. No auth/PIN/password/private-key request or token response is saved.
Auth operations retain only route/status/size metadata, without body hashes.
Saved JSON payloads are pretty-printed; manifest hashes describe the original
wire response bytes before formatting, not the pretty-printed file bytes.

The integration suite uses random valid emails through PHP registration/login,
native credential/checkout HTTP boundaries, and real BFF commands. It restores
reserved stock by cancelling its order. Random users, their address, the cancelled
order and idempotency records remain in the disposable Task1 test stack.
