<section id="live-route" class="live-route">
  <div class="live-route-toolbar">
    <div><label for="live-route-service">Route you are driving</label><select id="live-route-service" disabled><option value="">Loading mapped routes…</option></select></div>
    <button type="button" class="button button-primary" id="live-route-start" disabled>Start live tracking</button>
    <button type="button" class="button button-outline" id="live-route-stop" disabled>Stop tracking</button>
  </div>
  <p id="live-route-status" class="live-route-status" role="status">Choose your route, then start tracking to allow GPS access.</p>
  <div class="live-route-layout">
    <div class="live-route-information">
      <div class="live-next-card" aria-live="polite" aria-atomic="true"><span class="form-kicker">NEXT STOP</span><h3 id="live-next-name">Waiting for GPS</h3><strong id="live-next-distance">—</strong><p id="live-next-description">Your next stop will update as you travel.</p></div>
      <p id="live-current-road" class="live-current-road">Current location: not available</p>
      <div class="live-direction-choice"><label for="live-next-hint">Next stop you are heading toward</label><select id="live-next-hint" disabled><option value="">Detect automatically</option></select><p>Choose a stop if the route passes the same road in both directions.</p></div>
      <h4>Upcoming stops</h4><ol id="live-upcoming"><li>Start tracking to see the stops ahead.</li></ol>
      <p id="live-gps-quality" class="live-gps-quality">No location received yet.</p>
    </div>
    <div><div id="live-route-map" aria-label="Live driver position and next-stop map"></div><button type="button" class="button button-outline button-small" id="live-route-follow" disabled>Follow my location</button></div>
  </div>
  <a href="driver-route.php">Open route planner and assigned route <svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#arrow"></use></svg></a>
</section>
