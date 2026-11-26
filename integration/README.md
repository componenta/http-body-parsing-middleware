# Middleware integration fixture

The workflow installs the current Body Parsing revision with the following public repository snapshots:

- `componenta/di` — `e203077d12048936f078d37d1cf3badd661e1716`
- `componenta/http-csrf-middleware` — `4b280eecc25544757f63ead2563dfa3691e1cde3`
- `componenta/http-cors-middleware` — `c0064ae459dc0706e2e2d798644c64c5d7489290`
- `componenta/http-throttle-middleware` — `4c6ec261969a4f8d1df75250ceb10be38527bfeb`
- `componenta/http-trusted-proxy-middleware` — `2ce4d7061b9c5e4fc35d86a0e49731a2c20eee07`

The fixture constructs all five middleware packages via DI 6 and Config 4, then runs the same PSR-15 chain with full and targeted attribute discovery. Session objects emulate trusted request-scoped sessions; the fixture does not replace the separate SessionMiddleware/runtime audit.

The PSR-15 chain is assembled locally because the current Componenta Pipeline, App HTTP and RoadRunner dependency constraints conflict with DI 6's Config 4 requirement. This is not an actual RoadRunner worker test. The fixture is isolated from runtime and regular development dependencies.

The workflow uses pinned snapshots so re-running it is deterministic at the repository revision level. Update the pins when auditing newer middleware or DI revisions.
