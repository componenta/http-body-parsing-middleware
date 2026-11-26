# Comparative benchmarks

The workflow measures the same valid public-contract scenarios against pinned source snapshots, on the same runner, PHP version and PSR dependencies. Each subject uses five iterations and multiple revolutions. The benchmark checks observable outcomes on the common valid inputs. Source paths are verified at bootstrap.

This deliberately does not compare unsafe cases where the old and new behaviors differ, and does not imply equivalent security guarantees. In particular, the old file limiter writes in-place and performs opportunistic cleanup, while the candidate uses atomic replacement and explicit maintenance. The body benchmark uses PATCH in both variants, as older POST form handling differs. The benchmark does not claim Composer/runtime compatibility of the baseline with DI 6.

| Repository | Baseline | Candidate |
|---|---|---|
| http-csrf-middleware | `10027095973eda5c86b4b2ef8886f8dae98f0af8` | `cbec1c34065de996f20307ce1103169f77420837` |
| http-cors-middleware | `4abd5e18727c3bb45b577fc82dc156be575c8320` | `c0064ae459dc0706e2e2d798644c64c5d7489290` |
| http-trusted-proxy-middleware | `eb9c9a654093b4f4b8e9c08aa368786e47f17d9b` | `2ce4d7061b9c5e4fc35d86a0e49731a2c20eee07` |
| http-throttle-middleware | `6fcbcc624cadfaf12166f6d025f902532696f0e7` | `4c6ec261969a4f8d1df75250ceb10be38527bfeb` |
| http-body-parsing-middleware | `c054ef93269c1540e963a6a2c4e069f1b398b3b5` | `f3fb7ec2c697dc121917348a215165489e9db463` |

Results are directional measurements on a shared CI runner, not a production latency guarantee. Compare the median/mode and peak memory; inspect variance before interpreting small differences.
