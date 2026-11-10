<?php
declare(strict_types=1);
require_once __DIR__ . '/map-locations.php';
$mapLocations = map_locations();
$isDriverRouteMap = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'driver-route.php';
$journeyStorageKey = $isDriverRouteMap && isset($driver['id'])
    ? 'alaEhJourney:' . (int) $driver['id']
    : 'alaEhJourney:public';
?>
<section class="route-planner" aria-labelledby="route-planner-title" data-driver-map="<?= $isDriverRouteMap ? 'true' : 'false' ?>" data-explore-only="<?= $isDriverRouteMap ? 'false' : 'true' ?>" data-journey-storage-key="<?= h($journeyStorageKey) ?>" data-seat-color="<?= h($routeSeatColor ?? 'unknown') ?>" data-driver-id="<?= (int) ($mapDriver['id'] ?? 0) ?>" data-driver-route-key="<?= h($mapDriverRouteKey ?? '') ?>">
  <div class="route-planner-heading">
    <div><span class="form-kicker">PLAN YOUR BYAHE</span><h2 id="route-planner-title">Where will you travel?</h2><p>Enter your trip to see possible passenger pick-up and drop-off areas in travel order.</p></div>
    <button class="button button-outline" id="route-locate" type="button" disabled><svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#target"></use></svg> Use my location</button>
  </div>
  <ol class="route-progress" aria-label="Route planning steps"><li class="is-active"><span>1</span> Route</li><li><span>2</span> Passenger areas</li><li><span>3</span> Go</li></ol>
  <div class="route-planner-layout">
    <div class="route-planner-controls">
      <form id="route-plan-form">
        <fieldset id="route-plan-fields" disabled>
          <legend class="route-control-label">Your journey</legend>
          <div class="route-journey-fields">
          <div class="route-field"><span class="route-field-marker is-start">A</span><div><label class="route-coordinate-label" for="route-start">Starting point</label>
          <input id="route-start" name="start" type="text" placeholder="e.g. Bauan" maxlength="160" required autocomplete="off" aria-describedby="route-place-help">
          </div></div>
          <div id="route-start-matches" class="route-place-matches" role="group" aria-label="Starting location matches" hidden></div>
          <button class="route-swap" id="route-swap" type="button" aria-label="Swap starting point and destination" title="Swap locations">&#8645;</button>
          <div class="route-field"><span class="route-field-marker is-end">B</span><div><label class="route-coordinate-label" for="route-end">Destination</label>
          <input id="route-end" name="end" type="text" placeholder="e.g. Batangas City" maxlength="160" required autocomplete="off" aria-describedby="route-place-help">
          </div></div>
          <div id="route-end-matches" class="route-place-matches" role="group" aria-label="Destination matches" hidden></div>
          </div>
          <p id="route-place-help" class="route-map-help">Type a town, barangay, or landmark in Batangas. Choose a match if several places share the name.</p>
          <button class="button button-outline button-small route-example" id="route-example" type="button">Try Bauan → Batangas City</button>
          <button class="button button-outline button-small" id="route-show" type="submit" hidden>Try again <svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#arrow"></use></svg></button>
          <div class="route-map-actions">
            <button class="button button-outline button-small" id="route-fit" type="button" disabled>Fit route</button>
            <button class="button button-outline button-small" id="route-clear" type="button">Clear</button>
          </div>
        </fieldset>
      </form>
      <p id="route-map-status" class="route-map-status" role="status" aria-live="polite">Loading map…</p>
      <div id="route-result" class="route-result" hidden>
        <p id="route-matched-name" class="route-matched-name"></p>
        <div class="route-metrics"><div><strong id="route-distance"></strong><span>Along the route</span></div><div><strong>BW-02</strong><span>Jeepney route</span></div></div>
        <p id="route-access-note" class="route-map-help"></p>
        <section class="passenger-areas" aria-labelledby="passenger-areas-title">
          <div class="passenger-areas-heading"><div><span class="form-kicker">ALONG YOUR BYAHE</span><h3 id="passenger-areas-title">Passenger areas</h3></div><span id="route-stop-count" class="route-stop-count"></span></div>
          <p class="route-map-help">Tap an area to locate it on the map. Intermediate stops may be used for both pick-up and drop-off.</p>
          <div class="passenger-area-grid">
            <div class="passenger-area-column is-pickup"><h4><span aria-hidden="true">&#8593;</span> Pick-up areas</h4><ol id="route-pickup-areas" class="passenger-area-list"></ol></div>
            <div class="passenger-area-column is-dropoff"><h4><span aria-hidden="true">&#8595;</span> Drop-off areas</h4><ol id="route-dropoff-areas" class="passenger-area-list"></ol></div>
          </div>
        </section>
        <details class="route-directions route-all-stops"><summary>View all stops in travel order</summary><ol id="route-trip-stops" class="route-trip-stops"></ol><p class="route-map-help">Community-mapped stops. Check the route sign and boarding side before riding.</p></details>
        <details open class="route-directions"><summary>Roads you will pass</summary><ol id="route-steps"></ol></details>
        <a id="route-source" class="route-source" target="_blank" rel="noopener noreferrer">View mapped route source</a>
      </div>
      <noscript><p>Enable JavaScript to use the interactive map.</p></noscript>
    </div>
    <div class="route-map-panel<?= isset($mapDriver) && $mapDriver !== null ? ' has-driver-pickup' : '' ?>">
      <div class="map-explore-bar">
        <div hidden><label for="route-service">Explore a jeepney route</label><select id="route-service" disabled><option value="">All mapped routes</option></select></div>
      </div>
      <?php if (isset($mapDriver) && $mapDriver !== null): ?>
        <section class="map-driver-pickup" id="map-pickup-area" aria-labelledby="map-pickup-title">
          <div class="map-driver-pickup-heading"><span class="form-kicker">DRIVER AREA</span><h3 id="map-pickup-title">Pickup and waiting area</h3><p>Choose your authorized boarding area so commuters can find you on this map.</p></div>
          <?php if (($mapPickupError ?? '') !== ''): ?><p class="message message-error" role="alert"><?= h($mapPickupError) ?></p><?php endif; ?>
          <?php if (($mapPickupFlash ?? '') !== ''): ?><p class="trip-save-message" role="status"><?= h($mapPickupFlash) ?></p><?php endif; ?>
          <?php if (can_update_seats(driver_seats((int) $mapDriver['id']))): ?>
            <form method="post" action="map.php#map-pickup-area" class="map-driver-pickup-form">
              <?= csrf_field() ?><input type="hidden" name="action" value="pickup"><input type="hidden" name="route_key" id="driver-route-key" value="<?= h($mapDriverRouteKey ?? '') ?>" required><input type="hidden" name="pickup_revision" value="<?= (int) ($mapDriverSetup['route_revision'] ?? 0) ?>">
              <label class="pickup-field" for="map-driver-route">Intended route
                <select id="map-driver-route" required>
                  <option value="">Choose your intended route</option>
                  <?php foreach (driver_route_options() as $key => $route): ?>
                    <option value="<?= h((string) $key) ?>" <?= ($mapDriverRouteKey ?? '') === (string) $key ? 'selected' : '' ?>><?= h($route['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="pickup-field" for="map-pickup-location">Assigned area
                <select id="map-pickup-location" name="location_key" required><option value="">Choose an approved area</option><?php $lastArea = null; foreach ($mapPickupAreas as $area): $areaGroup = str_contains((string) $area['area_label'], 'Mabini') ? 'Mabini (POV from Mabini)' : 'Batangas City'; if ($lastArea !== $areaGroup): ?><?php if ($lastArea !== null): ?></optgroup><?php endif; ?><optgroup label="<?= h($areaGroup) ?>"><?php $lastArea = $areaGroup; endif; ?><option value="<?= h($area['location_key']) ?>" <?= $mapPickupLocationKey === $area['location_key'] ? 'selected' : '' ?>><?= h($area['location_name'] . ' — ' . $area['landmark']) ?></option><?php endforeach; if ($lastArea !== null): ?></optgroup><?php endif; ?></select>
              </label>
              <p class="route-map-help">Choose your intended route first, then an assigned area in its starting municipality. The assigned area is your starting point, not live GPS. The orange line shows your route; if the starting pin is unverified, it shows the general route instead. Saving the area automatically puts you on duty and marks you as waiting for passengers.</p>
              <div class="map-driver-pickup-actions">
                <button class="button button-primary button-small" type="submit">Save area</button>
                <button class="button button-outline button-small" id="map-pickup-clear" type="button">Clear</button>
              </div>
            </form>
            <p id="assigned-route-preview-status" class="route-map-help" role="status" aria-live="polite"></p>
            <p class="map-driver-pickup-current">Current assigned area: <strong><?= h($mapPickupLocationLabel) ?></strong></p>
            <p class="map-driver-pickup-current">Current route: <strong><?= h($mapDriverSetup['route_name'] ?? 'Choose a route above') ?></strong><?php if (!empty($mapDriverSetup['route_start'])): ?> <span>(Start: <?= h($mapDriverSetup['route_start']) ?>  -  Destination: <?= h($mapDriverSetup['route_destination']) ?>)</span><?php endif; ?></p>
          <?php else: ?>
            <p class="route-map-help">Your driver account and approved jeepney are required before saving an area.</p>
          <?php endif; ?>
        </section>
      <?php endif; ?>
      <div id="driver-route-map" aria-label="Interactive jeepney route map. Pan and zoom, or drag a location pin to adjust it."></div>
      <div class="map-point-filters" aria-label="Map locations">
        <label><input type="checkbox" id="route-terminals" checked> <svg class="ui-icon map-terminal-color" aria-hidden="true"><use href="assets/icons.svg#building"></use></svg> Terminals</label>
        <label><input type="checkbox" id="route-boarding" checked> <svg class="ui-icon map-boarding-color" aria-hidden="true"><use href="assets/icons.svg#bus"></use></svg> Boarding points</label>
        <span class="terminal-area-legend"><i class="terminal-area-swatch is-batangas" aria-hidden="true"></i> Batangas City <i class="terminal-area-swatch is-mabini" aria-hidden="true"></i> Mabini</span>
      </div>
      <p class="route-map-caption"><?php if ($isDriverRouteMap): ?><span class="route-legend-start">A</span> Starting location <span class="route-legend-end">B</span> Destination <?php endif; ?><span class="route-legend-line"></span> Selected route<?php if ($isDriverRouteMap): ?> <span class="route-legend-driver"><img src="assets/jeepney-map-marker.png" alt=""></span> Your jeepney<?php endif; ?></p>
      <p id="map-locations-status" class="route-map-help" role="status">Loading terminals and boarding points…</p>
    </div>
  </div>
  <section class="map-location-directory" aria-labelledby="map-location-title">
    <div class="map-directory-heading"><div><span class="form-kicker">TERMINALS & BOARDING POINTS</span><h3 id="map-location-title">Your local stops</h3></div><span class="route-map-help">Select a mapped location to see it on the map.</span></div>
    <div class="map-location-grid">
    <?php foreach ($mapLocations as $location): ?>
      <article class="map-location-card">
        <span class="map-directory-icon <?= $location['type'] === 'terminal' ? 'map-terminal-color' : 'map-boarding-color' ?>"><svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#<?= $location['type'] === 'terminal' ? 'building' : 'bus' ?>"></use></svg></span>
        <div><span class="map-location-area"><?= h($location['area']) ?></span><h4><?= h($location['name']) ?></h4><p><?= h($location['landmark']) ?></p>
        <?php if ($location['coordinates'] !== null): ?>
          <button class="map-location-link" type="button" data-map-location="<?= h($location['id']) ?>" disabled>View on map <svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#arrow"></use></svg></button>
        <?php else: ?>
          <span class="map-pin-pending">Manual location confirmation needed</span>
        <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
    <p class="route-map-help">Locations without verified coordinates remain listed here and are flagged for manual confirmation before navigation is enabled.</p>
  </section>
</section>
<script id="map-location-data" type="application/json"><?= json_encode($mapLocations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
