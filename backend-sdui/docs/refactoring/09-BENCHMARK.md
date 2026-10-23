# Before/after benchmark evidence

## Method and limits

Apple M4 Pro, darwin/arm64, GOMAXPROCS14; three2-second runs per benchmark.
Serial real loopback HTTP; ClientRead decodes a small cart; HomeHTTP uses real
inbound HTTP and two concurrent upstream reads with one category/product. Server
counters measure accepted TCP connections and delivered requests. No production
mock/fallback or cache was introduced. All fixture data is test-only.

```sh
go test ./internal/bff ./internal/yii -run '^$' \
  -bench 'Benchmark(HomeHTTP|HomeHTTPObserved|ClientRead)$' \
  -benchmem -benchtime=2s -count=3
```

Original app measured before changes on Go1.26.0. Original client also measured
on Go1.27.3 before its refactor, enabling same-toolchain comparison. After uses
Go1.27.3; BFF totals include toolchain changes. First baseline Home run overlapped
compilation and was noisy; retain it and report median-run summaries. No statistical
significance, production percentiles or saturation throughput is claimed.

## Median summaries

Quantiles below are medians of run quantiles, not merged histograms. Throughput
is1e9/median ns/op: a derived serial-workload rate, not a production load test.

| Metric | Client before (same Go1.27.3) | Client after | Home before (Go1.26) | Home after | Home after with telemetry |
|---|---:|---:|---:|---:|---:|
| ns/op |208180|79671|437344|251070|263505|
| p50 µs |195.8|74.00|386.2|221.7|232.0|
| p95 µs |266.2|107.0|663.4|435.4|473.6|
| p99 µs |392.3|186.9|906.1|548.3|580.0|
| serial operations/s |4804|12552|2287|3983|3795|
| allocations/op |160|107|570|474|540|
| B/op |22047|9477|91562|65076|69392|
| delivered upstream requests/op |1|1|2|2|2|
| TCP connections/run |N|1|2N|2|2|

Reuse exceeds99.99% client and99.97% home in these runs, without removing a
single data request. Observed-home includes counters and JSON slog encoding to
io.Discard, excluding real stdout collector/disk latency.

## Raw run vectors

| Workload | ns/op runs1/2/3 | alloc/op | B/op runs1/2/3 |
|---|---|---|---|
| Original Client Go1.26 |208217 /208468 /212846|167|22105 /22140 /22222|
| Original Client Go1.27 |207311 /208180 /208365|160|22160 /21985 /22047|
| Original Home Go1.26 |770528 /437344 /433094|569 /570 /571|89798 /91562 /93127|
| After Client |79187 /79671 /80978|107|9452 /9498 /9477|
| After Home |250254 /258137 /251070|473 /474 /474|63486 /65076 /65377|
| After Home observed |262122 /263505 /271521|540 /540 /540|69251 /69392 /69936|

Original same-Go client p50:193.6/196.4/195.8µs; p95:266.2/264/271;
p99:403.4/372.6/392.3. After client p50:74.00/73.71/74.83;
p95:105.6/107.0/108.8; p99:173.6/193.8/186.9.
Original home p50:386.2/392.5/384.8; p95:2190/663.4/644.3;
p99:6855/906.1/869.4. After home p50:221.7/226.2/221.7;
p95:431.3/451.2/435.4; p99:541.4/593.2/548.3.
Observed home p50:232.0/231.5/236.1; p95:472.7/473.6/492.6;
p99:578.9/580.0/607.5. After vectors are from the final post-review code,
including synchronous budget enforcement and HMAC correlation in observed runs.

Client end-of-run HeapInuse before (same Go):3,497,984 /2,826,240 /3,301,376bytes;
after:3,317,760 /4,218,880 /3,719,168bytes. Snapshots include harness/HTTP state
and depend on GC/sample count. **No steady-state memory reduction claimed**;
pool intentionally retains buffers/connections. Bytes/op and allocs/op are lower.

Conclusion limited to this workload: pooling removes TCP churn and measured
latency/allocation overhead. Mutations remain single-use. Home fan-out remains2–3;
catalog/devices stay sequential to preserve error precedence. Deployed p50/p95/
p99/RSS/throughput require a real production load model, which was not tested.
