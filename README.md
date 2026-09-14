# Moonrise Celestial Signs Calculator v7

WordPress shortcode plugin for Moonrise Crystals.
Sponsored by https://moonrisecrystals.com/

## Shortcodes

Existing shortcode remains supported:

```text
[moon_sign_calculator]
```

A clearer alias is also available:

```text
[celestial_sign_calculator]
```

Optional labels:

```text
[moon_sign_calculator title="Find Your Celestial Sign" button_text="Calculate My Celestial Sign"]
```

## What changed in v1.0.7

Version 1.0.7 adds the **True North Node** and derived **South Node**:

- Sun
- Moon
- Rising / Ascendant
- Mercury
- Venus
- Mars
- Jupiter
- Saturn
- Uranus
- Neptune
- Pluto
- North Node (True)
- South Node

The True North Node is calculated directly by Swiss Ephemeris using `SE_TRUE_NODE`. The South Node is the exact opposite point on the zodiac wheel:

```text
South Node longitude = (True North Node longitude + 180°) mod 360°
```

This preserves the exact degree/minute/second position while moving it into the opposite zodiac sign.

## Calculation flow

1. Visitor selects birth date, exact local birth time, and birth location.
2. Location autocomplete searches the local city database stored on the Moonrise WordPress site.
3. The selected city supplies an IANA timezone, latitude, and longitude.
4. PHP converts the historical local birth time to UTC using PHP's timezone database, including historical daylight-saving rules.
5. Swiss Ephemeris runs as WebAssembly in the visitor's browser.
6. The Sun, Moon, Mercury, Venus, Mars, Jupiter, Saturn, Uranus, Neptune, Pluto, and True North Node are calculated for the exact Julian day.
7. The South Node is derived as the point exactly 180° opposite the True North Node.
8. Swiss Ephemeris calculates the Ascendant from the same Julian day plus the selected city's latitude and longitude.
9. Each tropical longitude is converted to its zodiac sign and degree/minute/second position.
10. Each result links to Moonrise's `/zodiac/{sign}-healing-crystals/` page.

## Self-contained location lookup

No paid geocoding API or per-search external request is used during normal calculator operation.

The v7 local database now retains the source dataset's coordinates in addition to timezone:

```text
city
state/region
country
IANA timezone
latitude
longitude
```

The local database is stored under:

```text
wp-content/uploads/moonrise-moon-sign/city-database/
```

When upgrading from v5/v6, v7 recognizes the old timezone-only database as outdated and rebuilds it with coordinates. If needed, repair it under:

**Settings → Celestial Signs Calculator → Install / Repair Local City Database**

## Swiss Ephemeris WebAssembly

Swiss Ephemeris continues to run in the visitor's browser as WebAssembly, avoiding `exec()`, `proc_open()`, server executables, custom PHP extensions, and Kinsta process restrictions.

The pinned runtime is stored under:

```text
wp-content/uploads/moonrise-moon-sign/wasm-runtime/
```

## Reference diagnostics

The v7 settings-page browser test verifies:

- all ten planetary body calculations
- Moon reference: 1995-07-15 20:30 UTC ≈ 339.2803° Pisces
- Rising reference: 1982-08-20 01:42 UTC, Honolulu coordinates 21.307 / -157.858 ≈ 275.5380° Capricorn
- True North Node calculation through `SE_TRUE_NODE`
- South Node is exactly 180° opposite the True North Node

## Layout

Desktop uses a 50/50 layout:

- calculator form on the left
- celestial result table on the right

At 800px and below, the form and results stack into one column.

The submit button uses the Moonrise purple solid treatment by default and switches to a purple outline/transparent treatment on hover.

## Upgrade from v6

1. Upload the v7 ZIP through **Plugins → Add New → Upload Plugin** and replace the existing plugin.
2. Existing `[moon_sign_calculator]` placements require no changes.
3. Open **Settings → Celestial Signs Calculator** and confirm the WASM runtime is **OK**.
4. Confirm the local location database rebuilt successfully and is **OK**.
5. Run **Test 10 Bodies + Rising + Nodes**.

## Supported birth dates

The form currently accepts dates from January 1, 1800 through the current year.

## Third-party licensing

See `THIRD-PARTY-NOTICE.md` before production deployment.
