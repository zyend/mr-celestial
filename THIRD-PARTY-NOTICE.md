# Third-Party Notice

## Swiss Ephemeris

This plugin uses Swiss Ephemeris for astronomical calculations through a WebAssembly build. Swiss Ephemeris uses a dual-licensing model. Review the current Astrodienst Swiss Ephemeris licensing terms and obtain the license appropriate to the production deployment.

Official project information:

- https://www.astro.com/swisseph/

## swisseph-wasm

The browser wrapper/runtime installed by this plugin is pinned to:

- Project: `prolaxu/swisseph-wasm`
- Version: `v0.1.0`
- Project license: GPL-3.0-or-later according to its repository

The plugin's installer downloads these pinned runtime assets:

```text
src/swisseph.js
wasm/swisseph.js
wasm/swisseph.wasm
wasm/swisseph.data
```

v6 uses the wrapper's planetary constants and `calc_ut()` API for the ten planetary bodies, plus `houses()` / Swiss Ephemeris `swe_houses()` for the Rising Sign / Ascendant (`ascmc[0]`).

Review both the wrapper license and Swiss Ephemeris license requirements before production use.

## city-timezones

The plugin uses the city/timezone dataset distributed by:

- Project: `kevinroberts/city-timezones`
- Package version: `1.3.3`
- Declared package license: MIT
- Project: https://github.com/kevinroberts/city-timezones

The plugin downloads the versioned dataset once during installation and compiles a reduced local lookup database containing the fields required for city selection, IANA timezone resolution, latitude, and longitude. Visitor location searches remain local after installation.
