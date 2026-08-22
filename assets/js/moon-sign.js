(() => {
    'use strict';

    const config = window.mrcMoonSign || {};
    let swissEphemerisPromise = null;

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const debounce = (fn, wait) => {
        let timer;
        return (...args) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => fn(...args), wait);
        };
    };

    const post = async (payload) => {
        const body = new URLSearchParams();
        Object.entries(payload).forEach(([key, value]) => body.append(key, value ?? ''));

        const response = await fetch(config.ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            credentials: 'same-origin',
            body: body.toString(),
        });

        let json;
        try {
            json = await response.json();
        } catch (error) {
            throw new Error(config?.i18n?.genericError || 'Something went wrong. Please try again.');
        }

        if (!response.ok || !json?.success) {
            throw new Error(json?.data?.message || config?.i18n?.genericError || 'Something went wrong. Please try again.');
        }

        return json.data || {};
    };

    const loadSwissEphemeris = async () => {
        if (!config.wasmInstalled || !config.wasmWrapperUrl) {
            throw new Error(config?.i18n?.setupRequired || 'The Celestial Signs calculator is not fully configured yet.');
        }

        if (!swissEphemerisPromise) {
            swissEphemerisPromise = (async () => {
                const module = await import(config.wasmWrapperUrl);
                if (!module?.default) {
                    throw new Error('Swiss Ephemeris module did not export its runtime class.');
                }

                const swe = new module.default();
                await swe.initSwissEph();
                return swe;
            })().catch((error) => {
                swissEphemerisPromise = null;
                throw error;
            });
        }

        return swissEphemerisPromise;
    };

    const julianDayAtUtc = (swe, utcIso) => {
        const date = new Date(utcIso);
        if (Number.isNaN(date.getTime())) {
            throw new Error('Invalid UTC birth timestamp.');
        }

        const decimalHour = date.getUTCHours()
            + (date.getUTCMinutes() / 60)
            + (date.getUTCSeconds() / 3600);

        return swe.julday(
            date.getUTCFullYear(),
            date.getUTCMonth() + 1,
            date.getUTCDate(),
            decimalHour
        );
    };

    const celestialLongitudesAtUtc = async (utcIso, latitude, longitude) => {
        const bodies = Array.isArray(config.bodies) ? config.bodies : [];
        if (!bodies.length) {
            throw new Error('Celestial body configuration is incomplete.');
        }

        const geoLat = Number(latitude);
        const geoLon = Number(longitude);
        if (!Number.isFinite(geoLat) || !Number.isFinite(geoLon) || geoLat < -90 || geoLat > 90 || geoLon < -180 || geoLon > 180) {
            throw new Error('The selected birth location is missing valid coordinates.');
        }

        const swe = await loadSwissEphemeris();
        const julianDay = julianDayAtUtc(swe, utcIso);
        const results = [];

        for (const body of bodies) {
            let bodyLongitude;

            if (body.type === 'ascendant') {
                if (typeof swe.houses !== 'function') {
                    throw new Error('Swiss Ephemeris does not expose house/Ascendant calculations.');
                }

                // swe_houses returns the Ascendant in ascmc[0]. The house-system
                // choice does not change the Ascendant itself; Placidus is used as
                // the conventional chart default.
                const houses = swe.houses(julianDay, geoLat, geoLon, 'P');
                bodyLongitude = Number(houses?.ascmc?.[0]);
            } else {
                const planetId = Number(swe?.[body.constant]);
                if (!Number.isFinite(planetId)) {
                    throw new Error(`Swiss Ephemeris is missing ${body.constant}.`);
                }

                const position = swe.calc_ut(julianDay, planetId, swe.SEFLG_SWIEPH);
                bodyLongitude = Number(position?.[0]);
            }

            if (!Number.isFinite(bodyLongitude)) {
                throw new Error(swe.getLastError?.() || `Swiss Ephemeris did not return a ${body.name} longitude.`);
            }

            results.push({
                ...body,
                longitude: ((bodyLongitude % 360) + 360) % 360,
            });
        }

        return results;
    };

    const signFromLongitude = (longitude) => {
        const signs = Array.isArray(config.signs) ? config.signs : [];
        if (signs.length !== 12) {
            throw new Error('Zodiac sign configuration is incomplete.');
        }

        const index = Math.floor(longitude / 30) % 12;
        const sign = signs[index];
        const withinSign = longitude - (index * 30);

        const degrees = Math.floor(withinSign);
        const minuteFloat = (withinSign - degrees) * 60;
        const minutes = Math.floor(minuteFloat);
        const seconds = Math.min(59, Math.floor(((minuteFloat - minutes) * 60) + 1e-7));

        return {
            ...sign,
            longitude,
            degrees,
            minutes,
            seconds,
            position: `${degrees}° ${String(minutes).padStart(2, '0')}′ ${String(seconds).padStart(2, '0')}″ ${sign.name}`,
        };
    };

    const renderResults = (resultBox, bodies, prepared) => {
        const location = escapeHtml(prepared.location);

        const rows = bodies.map((body) => {
            const zodiac = signFromLongitude(body.longitude);
            const bodyName = escapeHtml(body.name);
            const sentenceName = escapeHtml(body.sentence_name || body.name);
            const sign = escapeHtml(zodiac.name);
            const glyph = escapeHtml(zodiac.glyph);
            const position = escapeHtml(zodiac.position);
            const url = escapeHtml(zodiac.url);

            return `
                <div class="mrc-moon-sign__result-row" role="row">
                    <div class="mrc-moon-sign__body" role="rowheader">${bodyName}</div>
                    <div class="mrc-moon-sign__zodiac" role="cell">
                        <span class="mrc-moon-sign__zodiac-glyph" aria-hidden="true">${glyph}</span>
                        <a class="mrc-moon-sign__zodiac-name" href="${url}">${sign}</a>
                    </div>
                    <div class="mrc-moon-sign__result-detail" role="cell">
                        <p>${sentenceName} was at <strong>${position}</strong> at the time of your birth.</p>
                        <a class="mrc-moon-sign__learn" href="${url}">Explore ${sign} healing crystals →</a>
                    </div>
                </div>`;
        }).join('');

        resultBox.innerHTML = `
            <div class="mrc-moon-sign__results-card">
                <div class="mrc-moon-sign__results-heading">
                    <h3>Your Celestial Signs</h3>
                    <p>Calculated for ${location}.</p>
                </div>
                <div class="mrc-moon-sign__results-table" role="table" aria-label="Celestial Signs results">
                    ${rows}
                </div>
            </div>`;
    };

    const initCalculator = (root) => {
        const form = root.querySelector('.mrc-moon-sign__form');
        const locationInput = root.querySelector('.mrc-moon-sign__location');
        const timezoneInput = root.querySelector('.mrc-moon-sign__timezone');
        const latitudeInput = root.querySelector('.mrc-moon-sign__latitude');
        const longitudeInput = root.querySelector('.mrc-moon-sign__longitude');
        const suggestions = root.querySelector('.mrc-moon-sign__suggestions');
        const errorBox = root.querySelector('.mrc-moon-sign__error');
        const resultBox = root.querySelector('.mrc-moon-sign__result');
        const submit = root.querySelector('.mrc-moon-sign__submit');

        if (!form || !locationInput || !timezoneInput || !latitudeInput || !longitudeInput || !suggestions || !errorBox || !resultBox || !submit) {
            return;
        }

        let selectedLabel = '';
        let requestSequence = 0;

        const showError = (message) => {
            errorBox.textContent = message;
            errorBox.hidden = false;
        };

        const clearError = () => {
            errorBox.textContent = '';
            errorBox.hidden = true;
        };

        const closeSuggestions = () => {
            suggestions.hidden = true;
            suggestions.innerHTML = '';
            locationInput.setAttribute('aria-expanded', 'false');
        };

        const selectLocation = (place) => {
            locationInput.value = place.label;
            timezoneInput.value = place.timezone;
            latitudeInput.value = Number(place.latitude);
            longitudeInput.value = Number(place.longitude);
            selectedLabel = place.label;
            closeSuggestions();
            clearError();
        };

        const renderSuggestions = (places) => {
            suggestions.innerHTML = '';

            if (!places.length) {
                const empty = document.createElement('div');
                empty.className = 'mrc-moon-sign__suggestion mrc-moon-sign__suggestion--empty';
                empty.textContent = config?.i18n?.noLocations || 'No matching locations found.';
                suggestions.appendChild(empty);
            } else {
                places.forEach((place) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'mrc-moon-sign__suggestion';
                    button.setAttribute('role', 'option');
                    button.textContent = place.label;
                    button.addEventListener('click', () => selectLocation(place));
                    suggestions.appendChild(button);
                });
            }

            suggestions.hidden = false;
            locationInput.setAttribute('aria-expanded', 'true');
        };

        const searchLocations = debounce(async () => {
            const query = locationInput.value.trim();
            const sequence = ++requestSequence;

            timezoneInput.value = '';
            latitudeInput.value = '';
            longitudeInput.value = '';
            selectedLabel = '';

            if (query.length < 2) {
                closeSuggestions();
                return;
            }

            if (config.citiesInstalled === false) {
                closeSuggestions();
                showError(config?.i18n?.setupRequired || 'The Celestial Signs calculator is not fully configured yet.');
                return;
            }

            suggestions.innerHTML = `<div class="mrc-moon-sign__suggestion mrc-moon-sign__suggestion--empty">${escapeHtml(config?.i18n?.searching || 'Searching…')}</div>`;
            suggestions.hidden = false;
            locationInput.setAttribute('aria-expanded', 'true');

            try {
                const data = await post({
                    action: 'mrc_moon_location_search',
                    nonce: config.nonce,
                    query,
                });

                if (sequence !== requestSequence) return;
                renderSuggestions(Array.isArray(data.results) ? data.results : []);
            } catch (error) {
                if (sequence !== requestSequence) return;
                closeSuggestions();
                showError(error.message || config?.i18n?.locationError || 'Location search is temporarily unavailable.');
            }
        }, 350);

        locationInput.addEventListener('input', () => {
            if (locationInput.value !== selectedLabel) {
                timezoneInput.value = '';
                latitudeInput.value = '';
                longitudeInput.value = '';
            }
            searchLocations();
        });

        locationInput.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeSuggestions();
            }
        });

        document.addEventListener('click', (event) => {
            if (!root.contains(event.target)) {
                closeSuggestions();
            }
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            clearError();
            resultBox.hidden = true;

            if (!form.reportValidity()) {
                return;
            }

            if (!timezoneInput.value || !latitudeInput.value || !longitudeInput.value || locationInput.value !== selectedLabel) {
                showError(config?.i18n?.locationRequired || 'Please choose a birth location from the suggestions.');
                locationInput.focus();
                return;
            }

            if (!config.wasmInstalled) {
                let message = config?.i18n?.setupRequired || 'The Celestial Signs calculator is not fully configured yet.';
                if (config.settingsUrl) {
                    message += ' Open Settings → Celestial Signs Calculator to install the WASM runtime.';
                }
                showError(message);
                return;
            }

            const originalText = submit.textContent;
            submit.disabled = true;
            submit.textContent = config?.i18n?.calculating || 'Calculating…';

            try {
                const formData = new FormData(form);

                // The server performs historical timezone/DST conversion only.
                const prepared = await post({
                    action: 'mrc_moon_prepare_time',
                    nonce: config.nonce,
                    date: formData.get('birth_date'),
                    time: formData.get('birth_time'),
                    timezone: timezoneInput.value,
                    location: locationInput.value,
                    latitude: latitudeInput.value,
                    longitude: longitudeInput.value,
                });

                submit.textContent = config?.i18n?.loadingEngine || 'Loading Swiss Ephemeris…';

                // All astronomical calculations run locally in the browser.
                const bodies = await celestialLongitudesAtUtc(prepared.utcIso, prepared.latitude, prepared.longitude);
                renderResults(resultBox, bodies, prepared);

                resultBox.hidden = false;
                resultBox.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            } catch (error) {
                console.error('Celestial Signs Calculator:', error);
                const message = /Swiss|WebAssembly|WASM|fetch|module/i.test(String(error?.message || ''))
                    ? (config?.i18n?.engineError || 'The Celestial Signs calculator engine could not load. Please try again.')
                    : (error.message || config?.i18n?.genericError || 'Something went wrong. Please try again.');
                showError(message);
            } finally {
                submit.disabled = false;
                submit.textContent = originalText;
            }
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.mrc-moon-sign').forEach(initCalculator);
    });
})();
