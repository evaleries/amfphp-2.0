# Performance ledger

Measured on PHP 8.3.6 (cli, arm64), same commands before/after each change.
Guard: re-run the two benchmark commands plus the profile snippet below;
a change is kept only if its median beats baseline outside run noise and
`php tools/legacy_test_runner.php` stays green. Neutral = revert.

```bash
php tools/benchmark_gateway.php --iterations=1500 --warmup=150
php tools/benchmark_serialize_large_dataset.php --iterations=400 --warmup=40
php tools/legacy_test_runner.php
```

Note: `benchmark_gateway.php` resets the FilterManager/PluginManager
singletons per iteration, so it measures cold (per-request SAPI) cost.
Worker-mode (warm) cost is profiled by calling
`Amfphp_Core_PluginManager::loadPlugins()` repeatedly in one process.

| Idea | Baseline → Result | Verdict | Why |
|---|---|---|---|
| PluginManager folder-listing cache + config-fingerprint skip | warm `loadPlugins` 24.0µs → 5.2µs/op; cold gateway median 0.0560 → 0.0552–0.0573ms (noise band) | kept | Worker requests no longer rescan plugin folders or re-register filters; cold SAPI path unchanged. Correctness: config change / disable now unload+reload (see PluginManagerTest::testConfig). |
| FilterManager ksort-on-add + empty-filter early return | callFilters empty 0.076µs, 2 filters 0.44µs/op | kept | Negligible per call, ~15 calls/request; sorting once at registration is strictly less work. |
| Serializer `chr($b & 0xFF)`, ServiceRouter spread, Handler substr split | serialize 1000 items median 2.630 → 2.633–2.646ms | kept (neutral) | Pre-existing branch changes; byte-identical output, no regression. Not re-attributed as new wins. |
| Gateway plugin-skip by content type | part of warm loadPlugins win above | kept, corrected | Skip list now mirrors plugin `filterHandler()` predicates on the raw content type; previously exact-match on a normalized type could skip a plugin that would have handled the request. |
| Native `amfext` codec (NativeCodec) | not measurable here | unverified | Extension not loaded in this environment; `tests/parity/parity.php` cross-parity stays SKIP. Fallback to php codec is automatic; do not claim a native win until parity runs with the extension. |

Baseline (pre-fix, 2026-10-25): gateway median ~0.0560ms (3 runs),
serialize-large-dataset median ~2.630ms (3 runs), tests 44 with
1 failure (PluginManagerTest::testConfig). Post-fix: tests 49/49 green.

## Worker warm path (2026-10-25)

Measured on PHP 8.5.11 ZTS (`dunglas/frankenphp:1-php8.5-bookworm`, the
FrankenPHP 1.13.0 build prod uses), opcache on, JIT off. Interleaved A/B:
the unchanged and the patched tree run alternately (A,B then B,A) for 15
rounds (9 for the tool rows), median of per-process medians; "paired" is the
range of per-round B−A deltas. A/A noise on the consumer loop: paired
−0.72..+0.60 µs. The consumer loop is a backend-`AmfController`-like setup
(VoConverter, Monitor, Discovery, Authentication, Json, Get disabled; one
namespaced service folder; 333-byte AMF3 response). In-tree reproduction:

```bash
php -d opcache.enable_cli=1 tools/benchmark_gateway.php --warm --iterations=3000 --warmup=300
php -d opcache.enable_cli=1 tools/benchmark_serialize_large_dataset.php --amf3 --iterations=300 --warmup=30
php -d opcache.enable_cli=1 tools/benchmark_gateway.php --iterations=1500 --warmup=150   # cold: must stay neutral
```

| Idea | Baseline → Result | Verdict | Why |
|---|---|---|---|
| AMF3 string references by hash lookup (`Serializer::writeAmf3String`) | consumer loop 55.92 → 46.80 µs/req (−16.3%, paired −9.9..−8.0); `--amf3` 1000 items 5.45 → 2.15 ms (−60.5%, paired −3.6..−3.3) | kept | `handleReference()` ran `array_search` over every stored string (up to 1025) for each string written: quadratic in unique strings per response, classic mode included. Output bytes identical on both benchmarks; integer-like keys may now reuse the reference of an equal string, which decodes the same. |
| ServiceRouter process-lifetime lookup cache | consumer loop 55.76 → 52.84 µs (−5.2%, paired −4.4..−1.7); `--warm` (5 service folders) 25.3 → 18.8 µs (−25.9%, paired −8.7..−6.2) | kept (worker mode) | `file_exists()` per service folder per request. Keyed by service name, reused only for an identical folder list, successful lookups only, capped at 1024; objects still created per call. Classic mode: one miss per request, unchanged. |
| `loadPlugins()` skip on identical arguments | consumer loop 55.88 → 54.20 µs (−3.0%, paired −2.6..−0.6, faster in 15/15 rounds); `--warm` paired −4.4..−0.1 µs | kept (worker mode) | Rebuilt and `serialize()`d every plugin config per request. Armed only after a successful call where every plugin config has a fingerprint; objects in config compare by identity. |
| All three together | consumer loop 55.96 → 42.48 µs (−24.1%, paired −14.3..−12.8); `--warm` 25.5 → 16.5 µs (−35.3%); cold gateway 56.1 → 56.0 µs (paired −3.3..+2.4, neutral) | kept | Real FrankenPHP worker, 5000 mixed requests: 0 non-200, memory flat at 508 KB, 4 filter callbacks. |
| Variadic `callFilters` with direct callable | consumer loop 57.12 → 56.28 µs (−1.5%, paired −1.5..−0.2; 8.3: −0.7..0.0) | reverted | Borderline, within A/A noise on 8.3; not worth the API churn. |
| Disabled-plugin list without `array_unique` | 56.16 → 55.84 µs (paired −1.6..+1.0) | reverted | Within noise. |
| `array_is_list` fast path in `writeAmf3Array` | 47.04 → 46.36 µs on top of the string map (paired −1.0..+0.4) | reverted | Within noise. |
| Cached `ReflectionMethod` for `checkArgumentCount` | 159 → 108 ns per call | reverted | ≈0.05 µs per request. |
