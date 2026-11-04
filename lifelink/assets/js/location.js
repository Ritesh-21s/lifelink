/**
 * LifeLink – Location Component
 * Reusable JS module for collecting GPS / manual / geocoded location on any form.
 *
 * Usage: include this script, then call:
 *   LifeLinkLocation.init(containerSelector, options)
 *
 * Options:
 *   required     {bool}   – if true, form submission blocked until coords set
 *   autoDetect   {bool}   – if true, attempt GPS on load
 *   onResolved   {fn}     – callback({lat,lng,city,state,country,source})
 *   appUrl       {string} – base URL for API calls (default window.APP_URL)
 */
(function (window) {
    'use strict';

    const API = {
        reverse: (lat, lng) =>
            `${window.APP_URL || ''}/api/reverse_geocode.php?lat=${lat}&lng=${lng}`,
        forward: (q) =>
            `${window.APP_URL || ''}/api/geocode_search.php?q=${encodeURIComponent(q)}`,
    };

    /** Haversine distance in km (mirrors server-side formula) */
    function haversine(lat1, lng1, lat2, lng2) {
        const R   = 6371;
        const d1  = (lat2 - lat1) * Math.PI / 180;
        const d2  = (lng2 - lng1) * Math.PI / 180;
        const a   = Math.sin(d1/2)**2
                  + Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(d2/2)**2;
        return R * 2 * Math.asin(Math.min(1, Math.sqrt(a)));
    }

    const LifeLinkLocation = {

        /**
         * Inject the location block HTML into container and wire up all logic.
         * @param {string|Element} container  CSS selector or DOM element
         * @param {object}         opts       Options (see top)
         */
        init(container, opts = {}) {
            const wrap = typeof container === 'string'
                ? document.querySelector(container)
                : container;
            if (!wrap) return;

            const cfg = Object.assign({
                required: true,
                autoDetect: false,
                onResolved: null,
                mapContainer: null,   // optional: CSS selector for leaflet map
                showMap: true,
            }, opts);

            wrap.innerHTML = this._html();
            this._wire(wrap, cfg);
        },

        // ── HTML template ──────────────────────────────────────────────────
        _html() {
            return `
<div class="loc-block" role="group" aria-label="Location">
  <div class="loc-header">
    <span class="loc-icon">📍</span>
    <strong>Your Location</strong>
    <span class="loc-badge" id="loc-badge" style="display:none"></span>
  </div>

  <div class="loc-consent-row">
    <label class="loc-consent-label">
      <input type="checkbox" id="loc_consent" name="location_consent" value="1">
      Use my location to match me with nearby blood requests.
      <a href="${window.APP_URL || ''}/pages/privacy.php" target="_blank" class="loc-privacy-link">Privacy note</a>
    </label>
  </div>

  <div id="loc-body" style="display:none">
    <button type="button" class="btn btn-outline-primary btn-sm" id="loc-detect-btn">
      <span id="loc-detect-icon">📡</span> Use My Current Location
    </button>
    <div class="loc-status" id="loc-status" aria-live="polite"></div>

    <div class="form-row mt-3" id="loc-fields">
      <div class="form-group">
        <label for="loc_city">City / Area <span class="required">*</span></label>
        <input type="text" id="loc_city"    name="city"    placeholder="e.g. Anna Nagar" autocomplete="address-level2" maxlength="100">
      </div>
      <div class="form-group">
        <label for="loc_state">State <span class="required">*</span></label>
        <input type="text" id="loc_state"   name="state"   placeholder="e.g. Tamil Nadu"  autocomplete="address-level1" maxlength="100">
      </div>
    </div>
    <div class="form-group mt-2">
      <label for="loc_country">Country <span class="required">*</span></label>
      <input type="text" id="loc_country"  name="country" placeholder="e.g. India"       autocomplete="country-name"   maxlength="100" value="India">
    </div>

    <input type="hidden" id="loc_lat"    name="lat"             value="">
    <input type="hidden" id="loc_lng"    name="lng"             value="">
    <input type="hidden" id="loc_source" name="location_source" value="">

    <div id="loc-map-wrap" style="display:none;margin-top:12px;border-radius:8px;overflow:hidden">
      <div id="loc-map" style="height:200px;width:100%"></div>
      <p class="loc-map-hint">Drag the pin to correct your location.</p>
    </div>
  </div>
</div>`;
        },

        // ── Wire all event listeners ───────────────────────────────────────
        _wire(wrap, cfg) {
            const consent = wrap.querySelector('#loc_consent');
            const body    = wrap.querySelector('#loc-body');
            const btn     = wrap.querySelector('#loc-detect-btn');
            const status  = wrap.querySelector('#loc-status');
            const cityI   = wrap.querySelector('#loc_city');
            const stateI  = wrap.querySelector('#loc_state');
            const countryI= wrap.querySelector('#loc_country');
            const latI    = wrap.querySelector('#loc_lat');
            const lngI    = wrap.querySelector('#loc_lng');
            const srcI    = wrap.querySelector('#loc_source');
            const badge   = wrap.querySelector('#loc-badge');
            const mapWrap = wrap.querySelector('#loc-map-wrap');

            let leafMap = null, leafMarker = null;

            // Show/hide body when consent toggled
            consent.addEventListener('change', () => {
                body.style.display = consent.checked ? '' : 'none';
                if (consent.checked && cfg.autoDetect && !latI.value && !lngI.value) {
                    this._detectGPS(wrap, cfg);
                }
                if (!consent.checked) {
                    latI.value = lngI.value = srcI.value = '';
                    this._setBadge(badge, null);
                }
            });

            // GPS detect button
            btn.addEventListener('click', () => {
                this._detectGPS(wrap, cfg);
            });

            // Manual text entry → mark as manual, attempt forward geocode
            let geoTimer = null;
            const onManualInput = () => {
                srcI.value = 'manual';
                this._setBadge(badge, 'manual');
                clearTimeout(geoTimer);
                geoTimer = setTimeout(() => {
                    const q = [cityI.value, stateI.value, countryI.value]
                              .filter(Boolean).join(', ');
                    if (q.length > 4) this._forwardGeocode(q, wrap, cfg, leafMap, leafMarker,
                        (m) => { leafMap = m.map; leafMarker = m.marker; });
                }, 800);
            };
            [cityI, stateI, countryI].forEach(el =>
                el.addEventListener('input', () => {
                    el.dataset.userEdited = '1';
                    onManualInput();
                }));

            // Leaflet pin dragging
            if (cfg.showMap) {
                wrap.querySelector('#loc_lat')._onMapReady = function(map, marker) {
                    leafMap = map; leafMarker = marker;
                };
            }

            // Block form submission if required + no coords
            if (cfg.required) {
                const form = wrap.closest('form');
                if (form) {
                    form.addEventListener('submit', (e) => {
                        if (!consent.checked) {
                            e.preventDefault();
                            this._setStatus(status, 'error',
                                'Please tick the location consent checkbox to continue.');
                            consent.scrollIntoView({behavior:'smooth', block:'center'});
                            return;
                        }
                        if (!latI.value || !lngI.value) {
                            // Allow if city at least filled
                            if (!cityI.value.trim()) {
                                e.preventDefault();
                                this._setStatus(status, 'error',
                                    'Please allow location access or enter your city manually.');
                                btn.scrollIntoView({behavior:'smooth', block:'center'});
                            }
                        }
                    });
                }
            }
        },

        // ── GPS detection ──────────────────────────────────────────────────
        _detectGPS(wrap, cfg) {
            const btn    = wrap.querySelector('#loc-detect-btn');
            const status = wrap.querySelector('#loc-status');
            const icon   = wrap.querySelector('#loc-detect-icon');
            const badge  = wrap.querySelector('#loc-badge');

            if (!navigator.geolocation) {
                this._setStatus(status, 'error',
                    'Geolocation is not supported by your browser. Please type your city manually.');
                return;
            }

            btn.disabled = true;
            icon.textContent = '⏳';
            this._setStatus(status, 'info', 'Detecting your location…');

            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    this._fillCoords(wrap, lat, lng, 'gps', cfg);
                    icon.textContent = '📡';
                    btn.disabled = false;
                    this._reverseGeocode(lat, lng, wrap, cfg);
                    this._setBadge(badge, 'gps');
                },
                (err) => {
                    btn.disabled = false;
                    icon.textContent = '📡';
                    let msg = '';
                    switch (err.code) {
                        case 1: // PERMISSION_DENIED
                            msg = 'Location blocked. Please type your city below, or allow location in your browser settings (🔒 icon in address bar).';
                            break;
                        case 2: // POSITION_UNAVAILABLE
                            msg = 'Location unavailable. Please type your city manually.';
                            break;
                        case 3: // TIMEOUT
                            msg = 'Location timed out. Please type your city manually.';
                            break;
                        default:
                            msg = 'Location error. Please type your city manually.';
                    }
                    this._setStatus(status, 'warn', msg);
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
            );
        },

        // ── Reverse geocode a GPS fix ──────────────────────────────────────
        _reverseGeocode(lat, lng, wrap, cfg) {
            const status = wrap.querySelector('#loc-status');
            this._setStatus(status, 'info', 'Looking up your address…');

            fetch(API.reverse(lat, lng))
                .then(r => r.ok ? r.json() : null)
                .then(data => {
                    if (!data || data.error) {
                        this._setStatus(status, 'warn',
                            'Could not look up your address. You can edit the fields below.');
                        return;
                    }
                    this._fillAddress(wrap, data.city, data.state, data.country, 'gps');
                    this._setStatus(status, 'ok', 'Location detected automatically.');
                    this._showMap(wrap, lat, lng, cfg);
                    if (cfg.onResolved) cfg.onResolved({
                        lat, lng,
                        city:    data.city,
                        state:   data.state,
                        country: data.country,
                        source:  'gps',
                    });
                })
                .catch(() => {
                    this._setStatus(status, 'warn',
                        'Address lookup failed. You can edit the city/state fields below.');
                });
        },

        // ── Forward geocode typed text ─────────────────────────────────────
        _forwardGeocode(q, wrap, cfg, leafMap, leafMarker, onMap) {
            const status = wrap.querySelector('#loc-status');
            const latI   = wrap.querySelector('#loc_lat');
            const lngI   = wrap.querySelector('#loc_lng');

            fetch(API.forward(q))
                .then(r => r.ok ? r.json() : [])
                .then(results => {
                    if (!results || results.error || results.length === 0) return;
                    const best = results[0];
                    latI.value = best.lat;
                    lngI.value = best.lng;
                    wrap.querySelector('#loc_source').value = 'geocoded';
                    this._setStatus(status, 'ok',
                        `Resolved to: ${best.display_name || q}. Drag the pin to correct.`);
                    this._showMap(wrap, best.lat, best.lng, cfg);
                    const badge = wrap.querySelector('#loc-badge');
                    this._setBadge(badge, 'geocoded');
                    if (cfg.onResolved) cfg.onResolved({
                        lat: best.lat, lng: best.lng,
                        city: best.city, state: best.state, country: best.country,
                        source: 'geocoded',
                    });
                })
                .catch(() => { /* silently skip - geocoder down, user typed data is still saved */ });
        },

        // ── Fill hidden coord fields ───────────────────────────────────────
        _fillCoords(wrap, lat, lng, source, cfg) {
            wrap.querySelector('#loc_lat').value    = lat;
            wrap.querySelector('#loc_lng').value    = lng;
            wrap.querySelector('#loc_source').value = source;
        },

        // ── Fill address text fields ───────────────────────────────────────
        _fillAddress(wrap, city, state, country, source) {
            const cityI    = wrap.querySelector('#loc_city');
            const stateI   = wrap.querySelector('#loc_state');
            const countryI = wrap.querySelector('#loc_country');
            if (city    && !cityI.dataset.userEdited)    cityI.value    = city;
            if (state   && !stateI.dataset.userEdited)   stateI.value   = state;
            if (country && !countryI.dataset.userEdited) countryI.value = country;
        },

        // ── Show/update Leaflet map with draggable pin ─────────────────────
        _showMap(wrap, lat, lng, cfg) {
            if (!cfg.showMap) return;
            const mapWrap = wrap.querySelector('#loc-map-wrap');
            const mapDiv  = wrap.querySelector('#loc-map');
            if (!mapDiv) return;

            // Lazy-load Leaflet CSS + JS
            if (!window.L) {
                if (!document.getElementById('leaflet-css')) {
                    const link = document.createElement('link');
                    link.id  = 'leaflet-css';
                    link.rel = 'stylesheet';
                    link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                    document.head.appendChild(link);
                }
                if (!document.getElementById('leaflet-js')) {
                    const script = document.createElement('script');
                    script.id  = 'leaflet-js';
                    script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                    script.onload = () => this._initMap(mapWrap, mapDiv, wrap, lat, lng, cfg);
                    document.head.appendChild(script);
                }
                return;
            }
            this._initMap(mapWrap, mapDiv, wrap, lat, lng, cfg);
        },

        _initMap(mapWrap, mapDiv, wrap, lat, lng, cfg) {
            mapWrap.style.display = '';

            if (mapDiv._leafletMap) {
                mapDiv._leafletMap.setView([lat, lng], 14);
                mapDiv._leafletMarker.setLatLng([lat, lng]);
                return;
            }

            const map = window.L.map(mapDiv).setView([lat, lng], 14);
            window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 18,
            }).addTo(map);

            const icon = window.L.divIcon({
                className: '',
                html: '<div style="font-size:28px;line-height:1">📍</div>',
                iconAnchor: [14, 28],
            });
            const marker = window.L.marker([lat, lng], { draggable: true, icon }).addTo(map);

            marker.on('dragend', () => {
                const pos   = marker.getLatLng();
                const latI  = wrap.querySelector('#loc_lat');
                const lngI  = wrap.querySelector('#loc_lng');
                latI.value  = pos.lat.toFixed(6);
                lngI.value  = pos.lng.toFixed(6);
                wrap.querySelector('#loc_source').value = 'manual';
                // Re-reverse geocode after drag
                this._reverseGeocode(pos.lat, pos.lng, wrap, cfg);
            });

            mapDiv._leafletMap    = map;
            mapDiv._leafletMarker = marker;
        },

        // ── Status helper ──────────────────────────────────────────────────
        _setStatus(el, type, msg) {
            const classes = {
                ok:   'loc-status-ok',
                error:'loc-status-error',
                warn: 'loc-status-warn',
                info: 'loc-status-info',
            };
            el.className = 'loc-status ' + (classes[type] || '');
            el.textContent = msg;
            el.style.display = msg ? '' : 'none';
        },

        // ── Badge helper ───────────────────────────────────────────────────
        _setBadge(el, source) {
            if (!source) { el.style.display = 'none'; return; }
            const labels = {
                gps:      '📡 GPS detected',
                manual:   '✏️ Entered manually',
                geocoded: '🔍 Geocoded',
            };
            el.textContent  = labels[source] || '';
            el.style.display = '';
        },
    };

    window.LifeLinkLocation = LifeLinkLocation;
})(window);

