(() => {
  'use strict';
  const container = document.getElementById('driver-route-map');
  if (!container) return;
  const isDriverMap = container.closest('.route-planner')?.dataset.driverMap === 'true';
  const status = document.getElementById('route-map-status');
  const say = (message, error = false) => {
    status.textContent = message;
    status.classList.toggle('is-error', error);
    document.getElementById('route-show').hidden = !error;
  };
  if (!window.L || !window.JeepneyRouting) {
    say('The map could not load. Check your connection and reload the page.', true);
    return;
  }
  const form = document.getElementById('route-plan-form');
  const inputs = { start: document.getElementById('route-start'), end: document.getElementById('route-end') };
  const panels = { start: document.getElementById('route-start-matches'), end: document.getElementById('route-end-matches') };
  const locateButton = document.getElementById('route-locate');
  const fitButton = document.getElementById('route-fit');
  const result = document.getElementById('route-result');
  const stepsList = document.getElementById('route-steps');
  // Keep the interactive map within Batangas province and prevent panning
  // into neighboring provinces. The small buffer keeps coastal routes usable.
  const batangasBounds = L.latLngBounds([13.45, 120.45], [14.15, 121.35]);
  const map = L.map(container, {
    maxBounds: batangasBounds,
    maxBoundsViscosity: 1.0,
    minZoom: 11,
  }).setView([13.774, 121.035], 13);
  const markers = { start: null, end: null };
  const selected = { start: null, end: null };
  const controllers = { start: null, end: null };
  const searchVersions = { start: 0, end: 0 };
  const timers = { start: null, end: null };
  const cache = new Map();
  const layers = L.featureGroup().addTo(map);
  // Keep route geometry for fitting the map, but do not show it before a route is chosen.
  const network = L.featureGroup();
  const stopLayer = L.featureGroup().addTo(map);
  const points = new Map();
  const locations = JSON.parse(document.getElementById('map-location-data').textContent);
  const jeepneyMarkerImage = '<img class="jeepney-marker-image" src="assets/jeepney-map-marker.png" alt="">';
  const serviceSelect = document.getElementById('route-service');
  const driverRouteSelection = document.getElementById('map-driver-route');
  const assignedAreaSelection = document.getElementById('map-pickup-location');
  const assignedPreviewStatus = document.getElementById('assigned-route-preview-status');
  let connectorRoutes = [];
  let assignedAreaRoads = new Map();
  const driverRouteKeyInput = document.getElementById('driver-route-key');
  const savedDriverRouteKey = container.closest('.route-planner')?.dataset.driverRouteKey || '';
  const clearButton = document.getElementById('route-clear');
  const journeyStorageKey = container.closest('.route-planner')?.dataset.journeyStorageKey || 'alaEhJourney:public';
  const driverMapData = container.closest('.route-planner')?.dataset;
  const allowedSeatColors = new Set(['green', 'yellow', 'orange', 'red']);
  const seatColor = (value) => allowedSeatColors.has(value) ? value : 'unknown';
  let driverSeatColor = seatColor(driverMapData?.seatColor);
  const currentDriverId = Number(driverMapData?.driverId || 0);
  const tripStops = document.getElementById('route-trip-stops');
  const pickupAreas = document.getElementById('route-pickup-areas');
  const dropoffAreas = document.getElementById('route-dropoff-areas');
  const stopCount = document.getElementById('route-stop-count');
  const locationsStatus = document.getElementById('map-locations-status');
  const terminalSearch = document.getElementById('map-terminal-search');
  const areaFilter = document.getElementById('map-area-filter');
  const nearestTerminalButton = document.getElementById('map-nearest-terminal');
  const nearestStatus = document.getElementById('map-nearest-status');
  let visibleStops = null;
  let routeData = null;
  let locationVersion = 0;
  let tileWarning = false;
  let requestQueue = Promise.resolve();
  let lastSearch = 0;
  let dataRequest = null;
  let routeVersion = 0;
  let journeySaved = false;

  function setJourneySaved(saved) {
    journeySaved = saved;
    clearButton.disabled = saved;
    clearButton.title = saved ? 'Your journey is saved until trip status is Out.' : '';
  }

  function persistJourney() {
    if (!selected.start || !selected.end) return;
    try {
      localStorage.setItem(journeyStorageKey, JSON.stringify({
        start: { name: selected.start.name, coordinates: selected.start.coordinates },
        end: { name: selected.end.name, coordinates: selected.end.coordinates },
      }));
      setJourneySaved(true);
    } catch (_) { /* Storage may be disabled. */ }
  }

  function restoreJourney() {
    try {
      const saved = JSON.parse(localStorage.getItem(journeyStorageKey) || 'null');
      const valid = (place) => place && typeof place.name === 'string' && Array.isArray(place.coordinates)
        && place.coordinates.length === 2 && place.coordinates.every(Number.isFinite);
      if (valid(saved?.start) && valid(saved?.end)) {
        setJourneySaved(true);
        setPlace('start', saved.start);
        setPlace('end', saved.end);
      }
    } catch (_) { /* Ignore malformed or unavailable storage. */ }
  }

  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  }).on('tileerror', () => {
    if (!tileWarning) { tileWarning = true; say('Some map tiles could not load. Check your internet connection.', true); }
  }).addTo(map);

  const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  const distanceText = (meters) => meters >= 1000 ? `${(meters / 1000).toFixed(1)} km` : `${Math.round(meters)} m`;
  const samePoint = (a, b) => JeepneyRouting.distance(a, b) < 80;

  // Town centres from OSM. These are places, not claims of terminal locations.
  const places = [
    { name: 'Bauan, Batangas', aliases: ['Bauan', 'Bauan Batangas'], coordinates: [121.0077783, 13.7899158] },
    { name: 'Mabini, Batangas', aliases: ['Mabini', 'Mabini Batangas'], coordinates: [120.9407878, 13.7480896] },
    { name: 'Batangas City proper', aliases: ['Batangas', 'Batangas City', 'Batangas City proper'], coordinates: [121.0591276, 13.755232] },
    { name: 'Batangas City Grand Terminal', aliases: ['Grand Terminal', 'Batangas Grand Terminal', 'Batangas City Grand Terminal'], coordinates: [121.0612976, 13.7895245] },
  ];

  function clearRoute() {
    routeVersion += 1;
    layers.clearLayers();
    result.hidden = true;
    stepsList.replaceChildren();
    fitButton.disabled = true;
    tripStops.replaceChildren();
    pickupAreas.replaceChildren();
    dropoffAreas.replaceChildren();
    stopCount.textContent = '';
    document.querySelectorAll('.route-progress li').forEach((step, index) => step.classList.toggle('is-active', index === 0));
    visibleStops = null;
    serviceSelect.value = '';
    renderPoints();
  }
  function cancelSearch(kind) {
    searchVersions[kind] += 1;
    clearTimeout(timers[kind]);
    controllers[kind]?.abort();
    controllers[kind] = null;
    panels[kind].hidden = true;
    panels[kind].replaceChildren();
    inputs[kind].removeAttribute('aria-busy');
  }
  function setPlace(kind, place) {
    cancelSearch(kind);
    if (kind === 'start') { locationVersion += 1; locateButton.disabled = false; }
    selected[kind] = place;
    if (selected.start && selected.end) persistJourney();
    inputs[kind].value = place.name;
    inputs[kind].setCustomValidity('');
    const point = L.latLng(place.coordinates[1], place.coordinates[0]);
    if (markers[kind]) markers[kind].setLatLng(point);
    else {
      markers[kind] = L.marker(point, {
        draggable: true, autoPan: true, zIndexOffset: 1000,
        title: kind === 'start' ? 'Starting location — drag to adjust' : 'Destination — drag to adjust',
        icon: L.divIcon({ className: `route-map-pin route-map-pin-${kind}`, html: kind === 'start' ? 'A' : 'B', iconSize: [32, 32], iconAnchor: [16, 16] }),
      }).addTo(map);
      markers[kind].on('dragend', () => {
        const moved = markers[kind].getLatLng().wrap();
        setPlace(kind, { name: kind === 'start' ? 'Selected starting location' : 'Selected destination', coordinates: [moved.lng, moved.lat] });
      });
    }
    const label = document.createElement('span');
    label.textContent = place.name;
    markers[kind].unbindTooltip().bindTooltip(label);
    showRoute();
  }
  async function loadRoutes() {
    if (routeData) return routeData;
    if (!dataRequest) {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 12000);
      dataRequest = Promise.all([
        fetch('assets/jeepney-routes.json', { signal: controller.signal })
          .then((response) => { if (!response.ok) throw new Error('Could not load the jeepney routes.'); return response.json(); }),
        fetch('assets/diversion-mabini-road.json', { signal: controller.signal })
          .then((response) => response.ok ? response.json() : null).catch(() => null),
        fetch('assets/mabini-area-roads.json', { signal: controller.signal })
          .then((response) => response.ok ? response.json() : null).catch(() => null),
      ])
        .then(([data, diversionRoad, mabiniRoads]) => {
          routeData = JeepneyRouting.assignedServiceData(data, diversionRoad, mabiniRoads);
          connectorRoutes = routeData.connectorRoutes;
          assignedAreaRoads = routeData.assignedAreaRoads;
          return routeData;
        })
        .catch((error) => { dataRequest = null; throw error; })
        .finally(() => clearTimeout(timeout));
    }
    return dataRequest;
  }
  async function showRoute() {
    clearRoute();
    if (!selected.start || !selected.end) {
      say('Enter both locations to show your jeepney route automatically.');
      return;
    }
    const start = selected.start;
    const end = selected.end;
    const version = routeVersion;
    if (JeepneyRouting.distance(start.coordinates, end.coordinates) < 10) {
      say('Choose a destination different from your starting location.', true);
      return;
    }
    say('Finding your mapped jeepney route…');
    try {
      const data = await loadRoutes();
      if (version !== routeVersion || selected.start !== start || selected.end !== end) return;
      const match = JeepneyRouting.match(data.routes, start.coordinates, end.coordinates);
      if (!match) {
        say('No mapped jeepney route connects these locations. Try Mabini, Bauan, or Batangas City.', true);
        return;
      }
      renderJourney(match);
      [[start.coordinates, match.coordinates[0]], [end.coordinates, match.coordinates.at(-1)]].forEach(([point, anchor]) => {
        if (JeepneyRouting.distance(point, anchor) > 20) L.polyline([point, anchor].map(([lng, lat]) => [lat, lng]), { color: '#64748b', weight: 2, dashArray: '5 7' }).addTo(layers);
      });
      document.getElementById('route-access-note').textContent = `Start: ${distanceText(match.startOffset)} from the mapped road. Destination: ${distanceText(match.endOffset)} from the mapped road.`;
      map.fitBounds(layers.getBounds(), { padding: [35, 35], maxZoom: 16 });
      say('Route ready. Your journey is saved until trip status is set to Out.');
    } catch (error) {
      if (version !== routeVersion || selected.start !== start || selected.end !== end) return;
      say('The jeepney route data could not load. Check your connection and choose Try again.', true);
    }
  }

  function pointFor(stop) {
    return points.get(stop.id) || [...points.values()].find((point) => JeepneyRouting.distance(point.coordinates, stop.coordinates) < 5);
  }
  function focusPoint(point) {
    document.getElementById(point.type === 'terminal' ? 'route-terminals' : 'route-boarding').checked = true;
    stopLayer.addLayer(point.marker);
    container.scrollIntoView({ behavior: 'smooth', block: 'center' });
    map.setView([point.coordinates[1], point.coordinates[0]], 17);
    point.marker.openPopup();
  }
  function findNearestTerminal() {
    if (!navigator.geolocation) {
      nearestStatus.textContent = 'Location access is not available in this browser.';
      return;
    }
    nearestStatus.textContent = 'Finding terminals near your current location…';
    navigator.geolocation.getCurrentPosition((position) => {
      const current = [position.coords.longitude, position.coords.latitude];
      const nearest = [...points.values()]
        .filter((point) => point.marker)
        .map((point) => ({ point, distance: JeepneyRouting.distance(current, point.coordinates) }))
        .sort((a, b) => a.distance - b.distance)[0];
      if (!nearest) {
        nearestStatus.textContent = 'No verified terminal pins are available yet.';
        return;
      }
      nearestStatus.textContent = `Nearest mapped terminal: ${nearest.point.name} (${distanceText(nearest.distance)} away).`;
      focusPoint(nearest.point);
    }, () => {
      nearestStatus.textContent = 'Location access was denied. Allow location access to find the nearest terminal.';
    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
  }
  function renderPoints() {
    stopLayer.clearLayers();
    let count = 0;
    const query = normalize(terminalSearch?.value || '');
    const area = areaFilter?.value || 'all';
    points.forEach((point) => {
      if (visibleStops && !visibleStops.has(point.id)) return;
      if (!document.getElementById(point.type === 'terminal' ? 'route-terminals' : 'route-boarding').checked) return;
      const searchable = normalize([point.name, point.area, point.landmark].filter(Boolean).join(' '));
      if (query && !searchable.includes(query)) return;
      if (area !== 'all' && !normalize(point.area || '').includes(normalize(area))) return;
      point.marker.addTo(stopLayer);
      count += 1;
    });
    const pending = locations.filter((location) => !location.coordinates).length;
    locationsStatus.textContent = `${count} mapped locations shown${pending ? `  -  ${pending} listed locations still need an exact pin` : ''}.`;
  }
  function renderJourney(match) {
    map.removeLayer(network);
    serviceSelect.value = String(match.route.id);
    const routePoints = match.coordinates.map(([lng, lat]) => [lat, lng]);
    L.polyline(routePoints, { color: '#7c2d12', weight: 5, opacity: 0.8, lineCap: 'round', lineJoin: 'round', interactive: false }).addTo(layers);
    L.polyline(routePoints, { color: '#ff6b00', weight: 3, opacity: 1, lineCap: 'round', lineJoin: 'round' }).addTo(layers);
    if (isDriverMap && !driverRouteSelection && routePoints.length && !match.isGeneralRoute) {
      const jeepneyIcon = L.divIcon({
        className: 'route-driver-marker',
        html: `<span class="seat-color-${driverSeatColor}">${jeepneyMarkerImage}</span>`,
        iconSize: [64, 36], iconAnchor: [32, 18], tooltipAnchor: [0, -25],
      });
      L.marker(routePoints[0], { icon: jeepneyIcon, zIndexOffset: 1400, title: 'Your jeepney - trip starting point' })
        .bindTooltip('Your jeepney', { permanent: false, direction: 'top' })
        .addTo(layers);
      document.querySelector('.route-legend-driver')?.classList.add(`seat-color-${driverSeatColor}`);
    }
    const stops = JeepneyRouting.stopsAlong(match, locations);
    visibleStops = new Set(stops.map((stop) => pointFor(stop)?.id).filter(Boolean));
    renderPoints();
    document.getElementById('route-matched-name').textContent = match.route.name;
    document.getElementById('route-distance').textContent = distanceText(match.distance);
    document.getElementById('route-source').href = match.route.source;
    match.roads.forEach((road) => { const item = document.createElement('li'); item.textContent = road; stepsList.append(item); });
    function appendStop(list, stop, index, label) {
      const item = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button';
      const number = document.createElement('span');
      number.className = 'passenger-area-number';
      number.textContent = String(index + 1);
      const copy = document.createElement('span');
      const name = document.createElement('strong');
      name.textContent = stop.name;
      const note = document.createElement('small');
      note.textContent = stop.access || label;
      copy.append(name, note);
      button.append(number, copy);
      button.addEventListener('click', () => { const point = pointFor(stop); if (point) focusPoint(point); });
      item.append(button);
      list.append(item);
    }
    stops.forEach((stop, index) => {
      const item = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = stop.name;
      button.addEventListener('click', () => { const point = pointFor(stop); if (point) focusPoint(point); });
      item.append(button);
      if (stop.access) { const note = document.createElement('small'); note.textContent = stop.access; item.append(note); }
      tripStops.append(item);
      if (index < stops.length - 1 || stops.length === 1) appendStop(pickupAreas, stop, index, index === 0 ? 'Starting area' : 'Possible pick-up point');
      if (index > 0 || stops.length === 1) appendStop(dropoffAreas, stop, index, index === stops.length - 1 ? 'Destination area' : 'Possible drop-off point');
    });
    if (!stops.length) {
      [tripStops, pickupAreas, dropoffAreas].forEach((list) => { const item = document.createElement('li'); item.textContent = 'No recorded passenger areas on this section yet.'; list.append(item); });
    }
    stopCount.textContent = `${stops.length} ${stops.length === 1 ? 'area' : 'areas'}`;
    document.querySelectorAll('.route-progress li').forEach((step, index) => step.classList.toggle('is-active', index <= 1));
    result.hidden = false;
    fitButton.disabled = false;
  }
  function renderRouteEndpoints(journey, area, destination) {
    const general = journey.isGeneralRoute === true;
    const startName = general
      ? 'General route start — not your assigned area or live location'
      : area.name + ' — assigned starting point (not live GPS)';
    [
      { coordinates: journey.coordinates[0], kind: 'start', symbol: 'bus', name: startName },
      { coordinates: journey.coordinates.at(-1), kind: 'end', symbol: 'flag', name: destination + ' — route destination' },
    ].forEach((endpoint) => {
      const label = document.createElement('span');
      label.textContent = endpoint.name;
      L.marker([endpoint.coordinates[1], endpoint.coordinates[0]], {
        draggable: false, title: endpoint.name, alt: endpoint.name, zIndexOffset: 1400,
        icon: L.divIcon({
          className: 'route-endpoint route-endpoint-' + endpoint.kind
            + (general ? ' is-general-route' : endpoint.kind === 'start' ? ' seat-color-' + driverSeatColor : ''),
          html: endpoint.kind === 'start' && !general
            ? jeepneyMarkerImage
            : '<svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#' + endpoint.symbol + '"></use></svg>',
          iconSize: endpoint.kind === 'start' && !general ? [60, 34] : [40, 40],
          iconAnchor: endpoint.kind === 'start' && !general ? [30, 17] : [20, 20],
          tooltipAnchor: [0, -24],
        }),
      }).bindTooltip(label, { direction: 'top' }).addTo(layers);
    });
  }
  function previewAssignedJourney(id) {
    const route = routeData?.routes.find((item) => String(item.id) === String(id));
    if (!route || !assignedAreaSelection?.value) return false;
    const area = locations.find((place) => place.locationKey === assignedAreaSelection.value);
    clearRoute();
    map.removeLayer(network);
    serviceSelect.value = String(id);
    Object.keys(markers).forEach((key) => {
      if (markers[key]) map.removeLayer(markers[key]);
      markers[key] = null;
    });
    const note = (message) => { if (assignedPreviewStatus) assignedPreviewStatus.textContent = message; };
    const journey = JeepneyRouting.assignedAreaPreview(route, area, connectorRoutes, assignedAreaRoads);
    if (!journey.isGeneralRoute) journey.route.stops = [{ ...area, along: 0 }];
    renderJourney(journey);
    // Keep the approved start and other verified terminal pins visible.
    visibleStops = null;
    renderPoints();
    const destination = route.id === 12000002 ? 'Mabini' : 'Batangas City';
    renderRouteEndpoints(journey, area, destination);
    if (journey.isGeneralRoute) {
      const reason = area?.coordinates
        ? 'The road connection from your assigned area still needs confirmation.'
        : 'The exact starting pin for ' + (area?.name || 'your assigned area') + ' still needs confirmation.';
      const message = 'Orange line: ' + route.name + ' (general route). ' + reason + ' This line does not show your exact starting point or live GPS location.';
      document.getElementById('route-access-note').textContent = message;
      map.fitBounds(layers.getBounds(), { padding: [35, 35], maxZoom: 16 });
      note(message);
      return true;
    }
    document.getElementById('route-access-note').textContent = 'Route from your assigned starting point; not live GPS.';
    map.fitBounds(layers.getBounds(), { padding: [35, 35], maxZoom: 16 });
    const boardingNote = area.locationKey === 'mabini-iconails'
      ? ' The mapped Iconails branch is on I. Calangi St; confirm the stated Circumferential Road boarding point locally.'
      : ' Landmark pin only; confirm the exact roadside boarding side locally.';
    note('Route preview: ' + area.name + ' → ' + destination + '  -  ' + distanceText(journey.distance) + '. Save area to publish your selection.' + boardingNote);
    return true;
  }
  function exploreService(id) {
    if (driverRouteSelection && previewAssignedJourney(id)) return;
    if (assignedPreviewStatus) assignedPreviewStatus.textContent = '';
    document.getElementById('route-clear').click();
    const route = routeData?.routes.find((item) => item.id === Number(id));
    if (!route) {
      if (network.getLayers().length) map.fitBounds(network.getBounds(), { padding: [30, 30] });
      say('Explore the pins or enter both locations to plan your journey.');
      return;
    }
    const length = route.coordinates.slice(1).reduce((sum, point, i) => sum + JeepneyRouting.distance(route.coordinates[i], point), 0);
    renderJourney({ route, coordinates: route.coordinates, distance: length, roads: route.roadNames.filter((road, i, roads) => i === 0 || road !== roads[i - 1]) });
    if (driverRouteSelection) {
      renderRouteEndpoints({ coordinates: route.coordinates, isGeneralRoute: true }, null,
        route.id === 12000002 ? 'Mabini' : 'Batangas City');
    }
    document.getElementById('route-access-note').textContent = 'Showing the full mapped service and its recorded stops in travel order.';
    map.fitBounds(layers.getBounds(), { padding: [35, 35] });
    say('Tap a stop to see its landmark and routes. Enter your locations to plan a shorter trip.');
  }
  function openDirections(point) {
    const destination = `${point.coordinates[1]},${point.coordinates[0]}`;
    const open = (origin = '') => {
      const params = new URLSearchParams({ api: '1', destination, travelmode: 'driving' });
      if (origin) params.set('origin', origin);
      window.open(`https://www.google.com/maps/dir/?${params.toString()}`, '_blank', 'noopener,noreferrer');
    };
    if (!navigator.geolocation) { open(); return; }
    navigator.geolocation.getCurrentPosition(
      (position) => open(`${position.coords.latitude},${position.coords.longitude}`),
      () => open(),
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 },
    );
  }
  function updateDriverList(point, list) {
    list.replaceChildren();
    const assignments = point.driverAssignments || [];
    if (!assignments.length) {
      const empty = document.createElement('p');
      empty.textContent = 'No drivers are currently marked available at this area.';
      list.append(empty);
      return;
    }
    assignments.forEach((assignment) => {
      const item = document.createElement('div');
      item.className = 'pickup-driver-item';
      const seatIcon = document.createElement('span');
      seatIcon.className = `pickup-driver-seat-icon seat-color-${seatColor(assignment.seat_color)}`;
      seatIcon.setAttribute('aria-label', assignment.percent_occupied === null
        ? 'Seats not reported' : `${assignment.percent_occupied}% occupied`);
      seatIcon.innerHTML = jeepneyMarkerImage;
      const name = document.createElement('strong');
      name.textContent = assignment.full_name;
      const status = document.createElement('span');
      status.className = `pickup-driver-status pickup-driver-${assignment.availability}`;
      const labels = { available: 'Available', waiting: 'Waiting for Passengers', on_trip: 'On Trip', off_duty: 'Off Duty' };
      status.textContent = labels[assignment.availability] || assignment.availability;
      item.append(seatIcon, name, status);
      const details = document.createElement('small');
      const updated = assignment.updated_at ? new Date(assignment.updated_at.replace(' ', 'T')).toLocaleString() : 'Unknown';
      details.textContent = (assignment.route_name || 'Route not set') + '  -  Start: ' + (assignment.location_name || point.name) + '  -  Updated: ' + updated;
      details.textContent += '  -  Seats: ' + (assignment.percent_occupied === null ? 'not reported' : `${assignment.percent_occupied}% occupied`);
      item.append(details);
      list.append(item);
    });
  }
  function createPointMarker(point) {
    const terminal = point.type === 'terminal';
    const areaClass = normalize(point.area || '').includes('mabini') ? 'is-mabini' : 'is-batangas';
    const icon = L.divIcon({ className: `map-stop-pin ${terminal ? 'is-terminal' : 'is-boarding'} ${areaClass}`, html: `<svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#${terminal ? 'building' : 'bus'}"></use></svg>`, iconSize: terminal ? [36, 36] : [28, 28], iconAnchor: terminal ? [18, 18] : [14, 14] });
    point.marker = L.marker([point.coordinates[1], point.coordinates[0]], { icon, title: point.name, alt: point.name, zIndexOffset: terminal ? 500 : 0 });
    const popup = document.createElement('div');
    popup.className = 'map-stop-popup';
    const type = document.createElement('span');
    type.className = 'form-kicker';
    type.textContent = terminal ? 'TERMINAL' : point.type === 'landmark' ? 'LANDMARK' : 'BOARDING POINT';
    const title = document.createElement('h3'); title.textContent = point.name;
    const landmark = document.createElement('p'); landmark.textContent = [point.area, point.landmark].filter(Boolean).join('  -  ');
    popup.append(type, title, landmark);
    if (['mabini-tourism-triangle', 'mabini-seven-eleven', 'mabini-iconails', 'diversion-kwatogs'].includes(point.locationKey)) {
      const pinNote = document.createElement('p');
      pinNote.textContent = point.locationKey === 'mabini-iconails'
        ? 'Landmark pin: this Iconails branch is mapped on I. Calangi St. Confirm the stated Circumferential Road boarding side locally.'
        : 'Landmark pin; confirm the exact roadside boarding side locally.';
      popup.append(pinNote);
    }
    const driverHeading = document.createElement('strong'); driverHeading.textContent = 'Drivers assigned here'; popup.append(driverHeading);
    const driverList = document.createElement('div'); driverList.className = 'pickup-driver-list'; updateDriverList(point, driverList); popup.append(driverList);
    if (point.access) { const access = document.createElement('p'); access.textContent = point.access; popup.append(access); }
    const heading = document.createElement('strong'); heading.textContent = 'Mapped trips through this stop'; popup.append(heading);
    const services = routeData.routes.filter((route) => point.routeIds.includes(route.id));
    services.forEach((route) => {
      const button = document.createElement('button'); button.type = 'button'; button.className = 'map-popup-service'; button.textContent = `BW-02  -  ${route.name}`;
      button.addEventListener('click', () => { map.closePopup(); exploreService(route.id); }); popup.append(button);
    });
    if (!services.length) { const note = document.createElement('p'); note.textContent = 'Route details have not been added for this location yet.'; popup.append(note); }
    const actions = document.createElement('div'); actions.className = 'map-popup-actions';
    if (!isDriverMap) {
      const directions = document.createElement('button'); directions.type = 'button'; directions.textContent = 'Get directions';
      directions.addEventListener('click', () => { map.closePopup(); openDirections(point); }); actions.append(directions);
    } else {
      [['start', 'Start here'], ['end', 'Go here']].forEach(([kind, label]) => {
        const button = document.createElement('button'); button.type = 'button'; button.textContent = label;
        button.addEventListener('click', () => { map.closePopup(); setPlace(kind, point); }); actions.append(button);
      });
    }
    popup.append(actions);
    if (point.source) { const source = document.createElement('a'); source.href = point.source; source.target = '_blank'; source.rel = 'noopener noreferrer'; source.textContent = 'Map source'; popup.append(source); }
    point.marker.bindPopup(popup, { maxWidth: 300 });
    const tooltip = document.createElement('span'); tooltip.textContent = point.name; point.marker.bindTooltip(tooltip);
  }
  async function refreshPickupAssignments(updatePopups = true) {
    const response = await fetch(`api/pickup-areas.php?ts=${Date.now()}`, { cache: 'no-store' });
    if (!response.ok) throw new Error('Pickup assignments unavailable.');
    const data = await response.json();
    const assignments = new Map();
    (Array.isArray(data.assignments) ? data.assignments : []).forEach((assignment) => {
      if (currentDriverId && Number(assignment.driver_id) === currentDriverId) {
        driverSeatColor = seatColor(assignment.seat_color);
        document.querySelectorAll('.route-endpoint-start:not(.is-general-route), .route-driver-marker > span, .route-legend-driver').forEach((icon) => {
          [...icon.classList].filter((name) => name.startsWith('seat-color-')).forEach((name) => icon.classList.remove(name));
          icon.classList.add(`seat-color-${driverSeatColor}`);
        });
      }
      const list = assignments.get(assignment.location_key) || [];
      list.push(assignment);
      assignments.set(assignment.location_key, list);
    });
    points.forEach((point) => {
      point.driverAssignments = assignments.get(point.locationKey) || [];
      if (!updatePopups || !point.marker) return;
      const content = point.marker.getPopup()?.getContent();
      const list = content?.querySelector('.pickup-driver-list');
      if (list) updateDriverList(point, list);
    });
  }
  async function initializeStops() {
    try {
      const data = await loadRoutes();
      locations.filter((place) => place.coordinates).forEach((place) => points.set(place.id, { ...place, routeIds: [...place.routeIds], driverAssignments: [] }));
      data.routes.forEach((route) => {
        const option = document.createElement('option'); option.value = String(route.id); option.textContent = route.name; serviceSelect.append(option);
        L.polyline(route.coordinates.map(([lng, lat]) => [lat, lng]), { color: '#5b21b6', weight: 3, opacity: 0.78, lineCap: 'round', lineJoin: 'round', interactive: false }).addTo(network);
        (route.stops || []).forEach((stop) => {
          const existing = pointFor(stop);
          const stopArea = route.name.includes('Mabini') ? 'Mabini' : 'Batangas City';
          if (existing) { if (!existing.routeIds.includes(route.id)) existing.routeIds.push(route.id); existing.access ||= stop.access; existing.area ||= stopArea; }
          // Do not turn locally sampled route stops into public pins. They
          // remain in the route line until an administrator verifies them.
        });
      });
      if (savedDriverRouteKey && [...serviceSelect.options].some((option) => option.value === savedDriverRouteKey)) {
        serviceSelect.value = savedDriverRouteKey;
        if (driverRouteKeyInput) driverRouteKeyInput.value = savedDriverRouteKey;
        if (driverRouteSelection) driverRouteSelection.value = savedDriverRouteKey;
      }
      // Assignment feed availability must not prevent the route from rendering.
      await refreshPickupAssignments(false).catch(() => {});
      points.forEach(createPointMarker);
      renderPoints();
      if (savedDriverRouteKey) exploreService(savedDriverRouteKey);
      document.querySelectorAll('[data-map-location]').forEach((button) => {
        button.disabled = false;
        button.addEventListener('click', () => { const point = points.get(button.dataset.mapLocation); if (point) focusPoint(point); });
      });
      serviceSelect.disabled = false;
      const viewAllButton = document.getElementById('route-view-all');
      if (viewAllButton) viewAllButton.disabled = false;
      window.setInterval(() => refreshPickupAssignments(true).catch(() => {}), 30000);
    } catch (error) {
      locationsStatus.textContent = 'Map locations could not load. Reload the page to try again.';
    }
  }

  function showMatches(kind, matches) {
    const panel = panels[kind];
    panel.replaceChildren();
    panel.hidden = false;
    if (!matches.length) {
      const message = document.createElement('p');
      message.textContent = 'No matching place found. Try a town or a more specific landmark in Batangas.';
      panel.append(message);
      say('A location was not found. Check its spelling or choose a nearby landmark.', true);
      return;
    }
    matches.forEach((place) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = place.name;
      button.addEventListener('click', () => setPlace(kind, place));
      panel.append(button);
    });
    say('Choose the matching place below the location field.');
  }
  function photonSearch(query, signal) {
    const key = normalize(query);
    if (cache.has(key)) return Promise.resolve(cache.get(key));
    // Keep searches modest and serial; do not send a request for every keystroke.
    const task = requestQueue.catch(() => {}).then(async () => {
      const wait = Math.max(0, 1000 - (Date.now() - lastSearch));
      if (wait) await new Promise((resolve) => setTimeout(resolve, wait));
      if (signal.aborted) throw new DOMException('Cancelled', 'AbortError');
      lastSearch = Date.now();
      const params = new URLSearchParams({ q: query, limit: '5', lang: 'en', bbox: '120.45,13.45,121.65,14.3', lat: '13.78', lon: '121.03' });
      const response = await fetch(`https://photon.komoot.io/api/?${params}`, { signal });
      if (!response.ok) throw new Error('Place search is unavailable. Try again shortly.');
      const data = await response.json();
      const matches = (Array.isArray(data.features) ? data.features : []).filter((feature) => {
        const c = feature.geometry?.coordinates;
        return feature.geometry?.type === 'Point' && Array.isArray(c) && c.length >= 2 && c.every(Number.isFinite) && c[0] >= 120.45 && c[0] <= 121.65 && c[1] >= 13.45 && c[1] <= 14.3;
      }).map((feature) => {
        const p = feature.properties || {};
        return { name: [...new Set([p.name, p.street, p.city || p.district, p.state].filter(Boolean))].join(', '), coordinates: feature.geometry.coordinates };
      }).filter((place) => place.name);
      const unique = matches.filter((place, index) => !matches.slice(0, index).some((other) => normalize(place.name) === normalize(other.name) && samePoint(place.coordinates, other.coordinates)));
      cache.set(key, unique);
      return unique;
    });
    requestQueue = task;
    return task;
  }
  async function resolvePlace(kind) {
    clearTimeout(timers[kind]);
    const query = inputs[kind].value.trim();
    if (selected[kind] || query.length < 3) return;
    cancelSearch(kind);
    const version = searchVersions[kind];
    const local = [...places, ...locations.filter((place) => place.coordinates)].find((place) => [place.name, ...(place.aliases || [])].some((name) => normalize(name) === normalize(query)));
    if (local) { setPlace(kind, local); return; }
    const controller = new AbortController();
    controllers[kind] = controller;
    const timeout = setTimeout(() => controller.abort(), 12000);
    inputs[kind].setAttribute('aria-busy', 'true');
    say(`Looking for ${query}…`);
    try {
      const matches = await photonSearch(query, controller.signal);
      if (version !== searchVersions[kind]) return;
      if (matches.length === 1) setPlace(kind, matches[0]);
      else showMatches(kind, matches);
    } catch (error) {
      if (version !== searchVersions[kind]) return;
      say('Place search could not finish. Check your connection and try again. Bauan, Batangas City, and Grand Terminal are available without place search.', true);
    } finally {
      clearTimeout(timeout);
      if (version === searchVersions[kind]) { controllers[kind] = null; inputs[kind].removeAttribute('aria-busy'); }
    }
  }
  Object.entries(inputs).forEach(([kind, input]) => {
    input.addEventListener('input', () => {
      cancelSearch(kind);
      if (kind === 'start') { locationVersion += 1; locateButton.disabled = false; }
      selected[kind] = null;
      input.setCustomValidity('');
      if (markers[kind]) map.removeLayer(markers[kind]);
      markers[kind] = null;
      clearRoute();
      say('Enter both locations; the route updates automatically.');
      if (input.value.trim().length >= 3) timers[kind] = setTimeout(() => resolvePlace(kind), 800);
    });
    input.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown' && !panels[kind].hidden) { event.preventDefault(); panels[kind].querySelector('button')?.focus(); }
      if (event.key === 'Escape') panels[kind].hidden = true;
    });
  });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    await resolvePlace('start');
    await resolvePlace('end');
    if (selected.start && selected.end) showRoute();
  });
  document.getElementById('route-example').addEventListener('click', () => { setPlace('start', places[0]); setPlace('end', places[1]); });
  document.getElementById('route-swap').addEventListener('click', () => {
    cancelSearch('start');
    cancelSearch('end');
    const oldStart = selected.start;
    const oldEnd = selected.end;
    if (oldStart && oldEnd) {
      setPlace('start', oldEnd);
      setPlace('end', oldStart);
      return;
    }
    const startValue = inputs.start.value;
    inputs.start.value = inputs.end.value;
    inputs.end.value = startValue;
    selected.start = null;
    selected.end = null;
    Object.keys(markers).forEach((kind) => { if (markers[kind]) map.removeLayer(markers[kind]); markers[kind] = null; });
    clearRoute();
    say('Locations swapped. Choose each place to show the updated route.');
  });
  locateButton.addEventListener('click', () => {
    if (!window.isSecureContext || !navigator.geolocation) { say('Location access needs HTTPS or localhost. You can type your starting place instead.', true); return; }
    cancelSearch('start');
    selected.start = null;
    clearRoute();
    const version = ++locationVersion;
    locateButton.disabled = true;
    say('Finding your location… Allow access when your browser asks.');
    navigator.geolocation.getCurrentPosition((position) => {
      if (version !== locationVersion) return;
      setPlace('start', { name: 'My current location', coordinates: [position.coords.longitude, position.coords.latitude] });
      if (!selected.end) map.setView([position.coords.latitude, position.coords.longitude], 15);
    }, (error) => {
      if (version !== locationVersion) return;
      locateButton.disabled = false;
      say(error.code === 1 ? 'Location access was denied. Type your starting place instead.' : 'Your location could not be found. Type your starting place instead.', true);
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 });
  });
  fitButton.addEventListener('click', () => { if (layers.getLayers().length) map.fitBounds(layers.getBounds(), { padding: [35, 35], maxZoom: 16 }); });
  clearButton.addEventListener('click', () => {
    if (journeySaved) {
      say('Your journey is saved. It will be removed when trip status is set to Out. Change a location to update it.', true);
      return;
    }
    clearRoute();
    locationVersion += 1;
    locateButton.disabled = false;
    Object.keys(inputs).forEach((kind) => {
      cancelSearch(kind);
      selected[kind] = null;
      inputs[kind].value = '';
      inputs[kind].setCustomValidity('');
      if (markers[kind]) map.removeLayer(markers[kind]);
      markers[kind] = null;
    });
    say('Enter your starting place and destination to see your jeepney route.');
  });
  document.getElementById('route-plan-fields').disabled = false;
  const approvedAreaGroups = assignedAreaSelection
    ? [...assignedAreaSelection.querySelectorAll('optgroup')].map((group) => ({
      label: group.label,
      options: [...group.querySelectorAll('option')].map((option) => option.cloneNode(true)),
    }))
    : [];
  function filterAssignedAreas(routeKey) {
    if (!assignedAreaSelection) return;
    const previousArea = assignedAreaSelection.value;
    const placeholder = assignedAreaSelection.querySelector('option[value=""]')?.cloneNode(true)
      || new Option('Choose an approved area', '');
    assignedAreaSelection.replaceChildren(placeholder);
    approvedAreaGroups.forEach(({ label, options }) => {
      const matching = options.filter((option) => String(JeepneyRouting.assignedAreaRoute(option.value)) === String(routeKey));
      if (!matching.length) return;
      const group = document.createElement('optgroup');
      group.label = label;
      matching.forEach((option) => group.appendChild(option.cloneNode(true)));
      assignedAreaSelection.appendChild(group);
    });
    assignedAreaSelection.value = [...assignedAreaSelection.options].some((option) => option.value === previousArea)
      ? previousArea : '';
  }
  serviceSelect.addEventListener('change', () => {
    if (driverRouteKeyInput) driverRouteKeyInput.value = serviceSelect.value;
    if (driverRouteSelection) driverRouteSelection.value = serviceSelect.value;
    filterAssignedAreas(serviceSelect.value);
    exploreService(serviceSelect.value);
  });
  driverRouteSelection?.addEventListener('change', () => {
    if (driverRouteKeyInput) driverRouteKeyInput.value = driverRouteSelection.value;
    serviceSelect.value = driverRouteSelection.value;
    filterAssignedAreas(driverRouteSelection.value);
    exploreService(driverRouteSelection.value);
  });
  assignedAreaSelection?.addEventListener('change', () => {
    exploreService(driverRouteSelection?.value || serviceSelect.value);
  });
  document.getElementById('map-pickup-clear')?.addEventListener('click', () => {
    const pickupForm = document.querySelector('.map-driver-pickup-form');
    const action = pickupForm?.querySelector('input[name="action"]');
    if (!(pickupForm instanceof HTMLFormElement) || !(action instanceof HTMLInputElement)) return;
    action.value = 'clear';
    pickupForm.submit();
  });
  const viewAllButton = document.getElementById('route-view-all');
  if (viewAllButton) {
    viewAllButton.addEventListener('click', () => {
      document.getElementById('route-terminals').checked = true;
      document.getElementById('route-boarding').checked = true;
      exploreService('');
    });
  }
  ['route-terminals', 'route-boarding'].forEach((id) => document.getElementById(id).addEventListener('change', renderPoints));
  terminalSearch?.addEventListener('input', renderPoints);
  areaFilter?.addEventListener('change', renderPoints);
  nearestTerminalButton?.addEventListener('click', findNearestTerminal);
  filterAssignedAreas(driverRouteSelection?.value || '');
  initializeStops();
  window.addEventListener('storage', (event) => {
    if (event.key !== journeyStorageKey || event.newValue !== null) return;
    setJourneySaved(false);
    clearButton.click();
  });
  if (!driverRouteSelection) restoreJourney();
  locateButton.disabled = false;
  say('Try Mabini → Batangas City or Bauan → Batangas City. Your route will appear automatically.');
  new ResizeObserver(() => map.invalidateSize()).observe(container);
})();
