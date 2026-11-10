(() => {
  'use strict';
  const form = document.getElementById('seat-form');
  if (!form) return;
  const input = document.getElementById('seat-occupied');
  const capacity = Number(form.dataset.capacity);
  const seatEndpoint = form.dataset.seatEndpoint || form.action;
  let savedCount = Number(input.value);
  let saveQueue = Promise.resolve();
  let selectedAvailablePercentage = null;
  const status = document.getElementById('seat-update-status');
  const initialStatus = status.textContent;
  const percentageOptions = [...form.querySelectorAll('.seat-percentage-option')];
  function render() {
    const occupied = input.value === '' ? NaN : Number(input.value);
    const valid = Number.isInteger(occupied) && occupied >= 0 && occupied <= capacity;
    input.setCustomValidity(valid ? '' : `Enter a whole number from 0 to ${capacity}.`);
    if (!valid) { status.textContent = `Enter a whole number from 0 to ${capacity}. Your saved count has not changed.`; return; }
    const percentage = occupied / capacity * 100;
    const available = capacity - occupied;
    const color = percentage >= 90 ? 'red' : percentage >= 70 ? 'orange' : percentage >= 50 ? 'yellow' : 'green';
    const label = available === 0 ? 'Full' : percentage >= 90 ? 'Almost full' : percentage >= 70 ? 'Filling up' : percentage >= 50 ? 'Half full' : 'Seats available';
    document.getElementById('seat-readout').className = `seat-readout seat-color-${color}`;
    document.getElementById('seat-state-label').textContent = label;
    document.getElementById('seat-available').textContent = available;
    document.getElementById('seat-available-percent').textContent = `${Math.round(available / capacity * 100)}% available`;
    document.getElementById('seat-occupied-note').textContent = `${occupied} occupied  -  ${Math.round(percentage)}% occupied`;
    const meter = document.getElementById('seat-meter');
    meter.setAttribute('aria-valuenow', occupied);
    meter.firstElementChild.style.width = `${percentage}%`;
    percentageOptions.forEach((option) => {
      const selected = Number(option.dataset.availablePercent) === selectedAvailablePercentage;
      option.classList.toggle('is-selected', selected);
      option.setAttribute('aria-pressed', String(selected));
    });
    status.textContent = Number(input.value) === savedCount ? initialStatus : 'Updating seat availability…';
    document.querySelector('.seat-save-message')?.remove();
  }
  function saveCount() {
    const count = Number(input.value);
    if (!Number.isInteger(count) || count < 0 || count > capacity) return;
    saveQueue = saveQueue.then(async () => {
      const body = new FormData(form);
      body.set('occupied', String(count));
      body.set('ajax', '1');
      status.textContent = 'Updating seat availability…';
      const response = await fetch(seatEndpoint, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' });
      const contentType = response.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) throw new Error('Seat update failed. Please reload the driver dashboard and try again.');
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Seat availability could not be updated.');
      savedCount = result.occupied;
      document.querySelector('input[name="revision"]').value = result.revision;
      status.textContent = result.message;
      render();
    }).catch((error) => { status.textContent = error.message; });
  }
  function setAvailablePercentage(percentage) {
    selectedAvailablePercentage = percentage;
    input.value = String(Math.round(capacity * (100 - percentage) / 100));
    render();
    saveCount();
  }
  form.addEventListener('submit', (event) => { event.preventDefault(); saveCount(); });
  percentageOptions.forEach((option) => option.addEventListener('click', () => setAvailablePercentage(Number(option.dataset.availablePercent))));
  render();
})();
