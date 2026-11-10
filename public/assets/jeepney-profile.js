(() => {
  const form = document.querySelector('#vehicle-form');
  const canEditVehicle = form instanceof HTMLFormElement;
  const vehicleApi = canEditVehicle ? form.dataset.api : 'api/vehicle.php';
  const fields = canEditVehicle ? {
    plate_number: form.elements.namedItem('plate_number'),
    capacity: form.elements.namedItem('capacity'),
    route_assignment: form.elements.namedItem('route_assignment'),
  } : null;
  const statusBanner = document.querySelector('#vehicle-status-banner');
  const statusTitle = document.querySelector('#vehicle-status-title');
  const statusMessage = document.querySelector('#vehicle-status-message');
  const pendingAnimation = document.querySelector('#vehicle-pending-animation');
  const approvedDetails = document.querySelector('#approved-vehicle-details');
  const formMessage = document.querySelector('#vehicle-form-message');
  const submitButton = document.querySelector('#vehicle-submit');

  const showError = (message) => {
    if (formMessage instanceof HTMLElement) formMessage.textContent = message;
  };

  const setStatus = (status, pendingVehicle) => {
    if (!(statusBanner instanceof HTMLElement) ||
        !(statusTitle instanceof HTMLElement) ||
        !(statusMessage instanceof HTMLElement)) return;

    statusBanner.classList.remove('vehicle-status-pending', 'vehicle-status-verified', 'vehicle-status-rejected');
    if (pendingAnimation instanceof HTMLElement) {
      pendingAnimation.hidden = status !== 'pending_verification';
    }
    if (status === 'pending_verification') {
      statusBanner.classList.add('vehicle-status-pending');
      statusTitle.textContent = 'Changes pending verification';
      statusMessage.textContent = 'Your submitted plate number, capacity, and route will be reviewed by an administrator.';
    } else if (status === 'verified') {
      statusBanner.classList.add('vehicle-status-verified');
      statusTitle.textContent = 'Vehicle details verified';
      statusMessage.textContent = 'Your vehicle profile is approved. Any future edits will need verification.';
    } else if (status === 'rejected') {
      statusBanner.classList.add('vehicle-status-rejected');
      statusTitle.textContent = 'Changes need another review';
      statusMessage.textContent = 'Your previous vehicle submission was not approved. Update the details and submit them again.';
    } else if (pendingVehicle) {
      statusTitle.textContent = 'Vehicle profile not submitted';
      statusMessage.textContent = 'Add your jeepney details and submit them for verification.';
    } else {
      statusTitle.textContent = 'No vehicle profile yet';
      statusMessage.textContent = 'Add your jeepney details and submit them for verification.';
    }
  };

  const renderApprovedDetails = (vehicle) => {
    if (!(approvedDetails instanceof HTMLElement)) return;
    approvedDetails.replaceChildren();
    if (!vehicle || !vehicle.plate_number) {
      const empty = document.createElement('p');
      empty.className = 'vehicle-empty-details';
      empty.textContent = 'You do not have verified vehicle details yet.';
      approvedDetails.append(empty);
      return;
    }

    const items = [
      ['Plate number', vehicle.plate_number],
      ['Passenger capacity', vehicle.capacity],
      ['Route assignment', vehicle.route_assignment],
    ];
    for (const [label, value] of items) {
      const item = document.createElement('div');
      item.className = 'vehicle-detail-item';
      const caption = document.createElement('span');
      caption.textContent = label;
      const detail = document.createElement('strong');
      detail.textContent = String(value ?? '—');
      item.append(caption, detail);
      approvedDetails.append(item);
    }
  };

  const loadVehicle = async () => {
    const response = await fetch(vehicleApi, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Unable to load your vehicle details.');

    const vehicle = result.vehicle;
    renderApprovedDetails(vehicle);
    setStatus(vehicle?.verification_status ?? 'not_submitted', Boolean(
      vehicle?.pending_plate_number || vehicle?.pending_capacity || vehicle?.pending_route_assignment,
    ));

    if (vehicle && fields) {
      fields.plate_number.value = vehicle.pending_plate_number || vehicle.plate_number || '';
      fields.capacity.value = vehicle.pending_capacity || vehicle.capacity || '';
      fields.route_assignment.value = vehicle.pending_route_assignment || vehicle.route_assignment || '';
    }
  };

  if (!canEditVehicle || !fields || !(form instanceof HTMLFormElement)) {
    loadVehicle().catch((error) => {
      setStatus('not_submitted', false);
    });
    return;
  }

  for (const [field, errorId] of Object.entries({
    plate_number: 'plate-error',
    capacity: 'capacity-error',
    route_assignment: 'route-error',
  })) {
    fields[field].addEventListener('input', () => {
      const error = document.querySelector(`#${errorId}`);
      if (error instanceof HTMLElement) error.textContent = '';
      fields[field].removeAttribute('aria-invalid');
    });
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    showError('');
    for (const error of form.querySelectorAll('.vehicle-field-error')) error.textContent = '';

    if (!(submitButton instanceof HTMLButtonElement)) return;
    submitButton.disabled = true;
    submitButton.textContent = 'Submitting…';
    try {
      const csrfToken = form.elements.namedItem('csrf_token').value;
      const response = await fetch(form.dataset.api, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify({
          plate_number: fields.plate_number.value,
          capacity: fields.capacity.value,
          route_assignment: fields.route_assignment.value,
        }),
      });
      const result = await response.json();
      if (!response.ok) {
        if (result.fields) {
          for (const [field, message] of Object.entries(result.fields)) {
            const error = document.querySelector(`#${{
              plate_number: 'plate-error',
              capacity: 'capacity-error',
              route_assignment: 'route-error',
            }[field]}`);
            if (error instanceof HTMLElement) error.textContent = message;
            fields[field]?.setAttribute('aria-invalid', 'true');
          }
        }
        throw new Error(result.error || 'Unable to submit your vehicle details.');
      }

      showError(result.message || 'Changes submitted for verification.');
      setStatus(result.vehicle?.verification_status, true);
      await loadVehicle();
      if (formMessage instanceof HTMLElement) formMessage.textContent = result.message;
    } catch (error) {
      showError(error instanceof Error ? error.message : 'Unable to submit your vehicle details.');
    } finally {
      submitButton.disabled = false;
      submitButton.innerHTML = 'Submit for review <span aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#arrow"></use></svg></span>';
    }
  });

  loadVehicle().catch((error) => {
    setStatus('not_submitted', false);
    showError(error instanceof Error ? error.message : 'Unable to load your vehicle profile.');
    if (submitButton instanceof HTMLButtonElement) submitButton.disabled = true;
  });
})();
