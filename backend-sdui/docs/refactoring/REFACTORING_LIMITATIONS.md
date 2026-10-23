# Remaining boundaries and limitations

* Origin is GitHub, not GitLab. Use the already configured remote, never invent
  or reconfigure a GitLab URL. Compare local/remote SHA after push.
* No PHP/client/edge/CI/deploy change was required. Admin network protection,
  trusted-proxy rate limiting and collectors remain deployment responsibilities.
* W3C trace correlation is supported; no SDK spans/exporter without collector.
  Metrics reset at restart; log sink backpressure remains an operational concern.
  Correlation logs use process-keyed HMAC fingerprints, not raw client values;
  fingerprints change after restart and are not cross-service span IDs.
* Read pooling and non-reusing mutations are deliberately separate. Write TCP/
  TLS setup cost remains in exchange for single-attempt delivery protection.
* Catalog/devices remain sequential to preserve error precedence; existing bounded
  concurrent home aggregation remains. No extra parallelism speedup is claimed.
* Loopback benchmarks are not production load/RSS evidence. No Android/iOS UI
  test ran; current HTTP/schema/golden compatibility was checked.
* Image gate scans HIGH/CRITICAL; lower severities/unknown vulnerabilities and PHP
  issues are not certified absent. govulncheck covers reachable Go vulnerabilities.
* Default tests skip opt-in live PHP. That test also ran explicitly against real
  PHP and the newly built Docker BFF, with race detector enabled.
* One Low final-review issue remains: after a retryable HTTP response followed by
  a headerless network failure, upstream-operation telemetry retains the previous
  response status. HTTP client error mapping and client-visible status are correct,
  but status-class/timeout telemetry can attribute the final network error to that
  last known HTTP status. See final review L1; not a business-data/security failure.

No limitation requires moving business logic or database access into Go.
