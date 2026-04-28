document.addEventListener('DOMContentLoaded', function() {
  const form = document.getElementById('random-redirect-form');
  if (!form) return;

  const segmentColors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#6366f1', '#84cc16'];

  // --- Shortlink picker bootstrap ---
  let allShortlinks = [];
  const bootstrapEl = document.getElementById('rrm-bootstrap');
  if (bootstrapEl) {
    try {
      const data = JSON.parse(bootstrapEl.textContent);
      if (Array.isArray(data.shortlinks)) allShortlinks = data.shortlinks;
    } catch (e) { /* keep allShortlinks empty on parse failure */ }
  }

  const picker = document.getElementById('rrm-picker');
  const pickerInput = document.getElementById('rrm-picker-q');
  const pickerList = document.getElementById('rrm-picker-list');
  const pickerCancel = document.getElementById('rrm-picker-cancel');
  let pickerTarget = null;

  if (picker) {
    const sleekyMeta = document.querySelector('meta[name="sleeky_theme"]');
    if (sleekyMeta && sleekyMeta.getAttribute('content') === 'dark') {
      picker.classList.add('rrm-dark');
    }
  }

  function openPicker(targetInput) {
    if (!picker) return;
    pickerTarget = targetInput;
    if (pickerInput) pickerInput.value = '';
    renderPickerList('');
    if (typeof picker.showModal === 'function') picker.showModal();
    else picker.setAttribute('open', '');
    if (pickerInput) setTimeout(() => pickerInput.focus(), 30);
  }

  function closePicker() {
    if (!picker) return;
    if (typeof picker.close === 'function') picker.close();
    else picker.removeAttribute('open');
    pickerTarget = null;
  }

  function renderPickerList(query) {
    if (!pickerList) return;
    const q = (query || '').toLowerCase().trim();
    const matches = (q === ''
      ? allShortlinks
      : allShortlinks.filter(l =>
          (l.keyword || '').toLowerCase().includes(q) ||
          (l.url || '').toLowerCase().includes(q) ||
          (l.title || '').toLowerCase().includes(q)
        )
    ).slice(0, 200);

    pickerList.innerHTML = '';
    if (matches.length === 0) {
      const li = document.createElement('li');
      li.innerHTML = '<em>No matches.</em>';
      pickerList.appendChild(li);
      return;
    }
    for (const link of matches) {
      const li = document.createElement('li');
      li.dataset.shorturl = link.shorturl || '';
      const kw = document.createElement('strong');
      kw.textContent = link.keyword;
      li.appendChild(kw);
      const url = document.createElement('span');
      url.className = 'rrm-picker-url';
      url.textContent = link.url || '';
      li.appendChild(url);
      if (link.title) {
        const t = document.createElement('span');
        t.className = 'rrm-picker-title';
        t.textContent = link.title;
        li.appendChild(t);
      }
      pickerList.appendChild(li);
    }
  }

  if (pickerInput) {
    pickerInput.addEventListener('input', e => renderPickerList(e.target.value));
    pickerInput.addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        e.preventDefault();
        const first = pickerList && pickerList.querySelector('li[data-shorturl]');
        if (first) first.click();
      } else if (e.key === 'Escape') {
        closePicker();
      }
    });
  }
  if (pickerList) {
    pickerList.addEventListener('click', e => {
      const li = e.target.closest('li[data-shorturl]');
      if (!li) return;
      if (pickerTarget) {
        pickerTarget.value = li.dataset.shorturl;
        pickerTarget.dispatchEvent(new Event('input', { bubbles: true }));
      }
      closePicker();
    });
  }
  if (pickerCancel) pickerCancel.addEventListener('click', closePicker);

  form.addEventListener('click', function(event) {
    const addButton = event.target.closest('.add-url');
    const removeButton = event.target.closest('.remove-url');
    const pickButton = event.target.closest('.pick-shortlink');
    const equalButton = event.target.closest('.equal-split');
    const normalizeButton = event.target.closest('.normalize-split');

    if (addButton) {
      event.preventDefault();
      const container = addButton.closest('.redirect-list-col, .settings-group').querySelector('.url-chances-container');
      if (container) addNewUrlRow(container);
    } else if (removeButton) {
      event.preventDefault();
      const row = removeButton.closest('.url-chance-row');
      if (!row) return;
      const container = row.closest('.url-chances-container');
      if (container) removeUrlRow(row, container);
    } else if (pickButton) {
      event.preventDefault();
      const row = pickButton.closest('.url-chance-row');
      const urlInput = row && row.querySelector('.url-input');
      if (urlInput) openPicker(urlInput);
    } else if (equalButton) {
      event.preventDefault();
      const container = findListContainer(equalButton);
      if (container) {
        setEqualSplit(container);
        renderAllocationEditor(container);
      }
    } else if (normalizeButton) {
      event.preventDefault();
      const container = findListContainer(normalizeButton);
      if (container) {
        normalizeSplit(container);
        renderAllocationEditor(container);
      }
    }
  });

  form.addEventListener('input', function(event) {
    if (event.target.classList.contains('chance-input')) {
      const container = event.target.closest('.url-chances-container');
      if (container) {
        updatePercentageSum(container);
        renderAllocationEditor(container);
      }
    } else if (event.target.classList.contains('keyword-input')) {
      const listSettings = event.target.closest('.redirect-list-settings');
      if (listSettings && !listSettings.classList.contains('add-new-list')) {
        const displaySpan = listSettings.querySelector('.keyword-display');
        if (displaySpan) displaySpan.textContent = event.target.value;
      }
    }

    if (event.target.id === 'new_list_keyword') {
      syncNewListRequired();
    }
  });

  document.querySelectorAll('.url-chances-container').forEach(container => {
    updatePercentageSum(container);
    renderAllocationEditor(container);
  });
  syncNewListRequired();

  function addNewUrlRow(container) {
    const template = container.querySelector('.template');
    if (!template) return;

    const newRow = template.cloneNode(true);
    newRow.style.display = 'flex';
    newRow.classList.remove('template');

    const urlInput = newRow.querySelector('.url-input');
    const chanceInput = newRow.querySelector('.chance-input');
    if (urlInput) {
      urlInput.value = '';
      const isNewListRow = (urlInput.name || '').startsWith('new_list_urls');
      if (isNewListRow) urlInput.removeAttribute('required');
      else urlInput.setAttribute('required', '');
    }
    if (chanceInput) chanceInput.value = '';

    newRow.querySelectorAll('input').forEach(input => input.disabled = false);
    container.insertBefore(newRow, template);

    updatePercentageSum(container);
    renderAllocationEditor(container);
    syncNewListRequired();
    if (urlInput) urlInput.focus();
  }

  function syncNewListRequired() {
    const kwInput = document.getElementById('new_list_keyword');
    if (!kwInput) return;
    const hasKeyword = kwInput.value.trim() !== '';
    document
      .querySelectorAll('input[name="new_list_urls[]"]')
      .forEach(input => {
        const row = input.closest('.url-chance-row');
        if (row && row.classList.contains('template')) return;
        if (hasKeyword) input.setAttribute('required', '');
        else input.removeAttribute('required');
      });
  }

  function removeUrlRow(row, container) {
    const visibleRows = Array.from(container.querySelectorAll('.url-chance-row:not(.template)'));

    if (visibleRows.length > 1) {
      row.remove();
      updatePercentageSum(container);
      renderAllocationEditor(container);
    } else {
      alert('You must have at least one URL in the list.');
    }
  }

  function findListContainer(element) {
    const scope = element.closest('.redirect-list-col, .settings-group');
    return scope && scope.querySelector('.url-chances-container');
  }

  function getChanceInputs(container) {
    return Array.from(container.querySelectorAll('.url-chance-row:not(.template) .chance-input'));
  }

  function getChanceValues(container) {
    return getChanceInputs(container).map(input => {
      const value = parseFloat(input.value);
      return Number.isFinite(value) && value > 0 ? value : 0;
    });
  }

  function roundChance(value) {
    const finiteValue = Number.isFinite(value) ? value : 0;
    return Math.round(finiteValue * 10) / 10;
  }

  function formatChance(value) {
    const rounded = roundChance(value);
    return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1);
  }

  function valuesToNormalized(values) {
    const safeValues = values.map(value => Number.isFinite(value) ? Math.max(0, value) : 0);
    const total = safeValues.reduce((sum, value) => sum + value, 0);
    if (values.length === 0) return [];
    if (total <= 0) return equalValues(values.length);

    let running = 0;
    return safeValues.map((value, index) => {
      if (index === safeValues.length - 1) {
        return roundChance(Math.max(0, 100 - running));
      }
      const next = roundChance((value / total) * 100);
      running = roundChance(running + next);
      return next;
    });
  }

  function equalValues(count) {
    if (count <= 0) return [];
    const base = Math.floor((1000 / count)) / 10;
    const values = Array(count).fill(base);
    const used = roundChance(base * count);
    values[count - 1] = roundChance(values[count - 1] + (100 - used));
    return values;
  }

  function applyChanceValues(container, values) {
    getChanceInputs(container).forEach((input, index) => {
      input.value = formatChance(values[index] || 0);
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    updatePercentageSum(container);
  }

  function setEqualSplit(container) {
    applyChanceValues(container, equalValues(getChanceInputs(container).length));
  }

  function normalizeSplit(container) {
    applyChanceValues(container, valuesToNormalized(getChanceValues(container)));
  }

  function valuesToBoundaries(values) {
    const normalized = valuesToNormalized(values);
    const boundaries = [];
    let running = 0;
    normalized.slice(0, -1).forEach(value => {
      running = roundChance(running + value);
      boundaries.push(running);
    });
    return boundaries;
  }

  function boundariesToValues(boundaries) {
    const points = [0].concat(boundaries, 100);
    const values = [];
    for (let i = 1; i < points.length; i++) {
      values.push(roundChance(points[i] - points[i - 1]));
    }
    return values;
  }

  function renderAllocationEditor(container, focusHandleIndex) {
    const editor = container.parentElement.querySelector('.rrm-allocation-editor');
    if (!editor) return;

    const inputs = getChanceInputs(container);
    editor.innerHTML = '';
    editor.classList.toggle('is-hidden', inputs.length === 0);

    if (inputs.length === 0) return;
    if (inputs.length === 1) {
      const empty = document.createElement('div');
      empty.className = 'rrm-allocation-empty';
      empty.textContent = 'Single URL gets 100%.';
      editor.appendChild(empty);
      return;
    }

    const values = valuesToNormalized(getChanceValues(container));
    const boundaries = valuesToBoundaries(values);
    const label = document.createElement('div');
    label.className = 'rrm-allocation-label';
    label.textContent = 'Traffic split';
    editor.appendChild(label);

    const track = document.createElement('div');
    track.className = 'rrm-allocation-track';

    let left = 0;
    values.forEach((value, index) => {
      const segment = document.createElement('div');
      segment.className = 'rrm-allocation-segment';
      if (value < 6) segment.classList.add('is-tiny');
      segment.style.left = left + '%';
      segment.style.width = value + '%';
      segment.style.backgroundColor = segmentColors[index % segmentColors.length];

      const label = document.createElement('span');
      label.textContent = formatChance(value) + '%';
      segment.appendChild(label);
      track.appendChild(segment);
      left = roundChance(left + value);
    });

    boundaries.forEach((boundary, index) => {
      const handle = document.createElement('button');
      handle.type = 'button';
      handle.className = 'rrm-allocation-handle';
      handle.style.left = boundary + '%';
      handle.style.transform = 'translate(-50%, -50%) translateY(' + handleVisualOffset(boundaries, index) + 'px)';
      handle.style.zIndex = String(10 + index);
      handle.dataset.boundaryIndex = String(index);
      handle.setAttribute('role', 'slider');
      handle.setAttribute('aria-orientation', 'horizontal');
      handle.setAttribute('aria-valuemin', formatChance(index === 0 ? 0 : boundaries[index - 1]));
      handle.setAttribute('aria-valuemax', formatChance(index === boundaries.length - 1 ? 100 : boundaries[index + 1]));
      handle.setAttribute('aria-valuenow', formatChance(boundary));
      handle.setAttribute('aria-valuetext', 'Boundary at ' + formatChance(boundary) + ' percent');
      handle.setAttribute('aria-label', 'Adjust split between URL ' + (index + 1) + ' and URL ' + (index + 2));
      handle.addEventListener('pointerdown', event => beginHandleDrag(event, track, container, index, boundaries));
      handle.addEventListener('keydown', event => handleBoundaryKey(event, container, index, boundaries));
      track.appendChild(handle);
    });

    editor.appendChild(track);

    const meta = document.createElement('div');
    meta.className = 'rrm-allocation-meta';
    values.forEach((value, index) => {
      const item = document.createElement('span');
      item.style.setProperty('--rrm-segment-color', segmentColors[index % segmentColors.length]);
      item.textContent = 'URL ' + (index + 1) + ': ' + formatChance(value) + '%';
      meta.appendChild(item);
    });
    editor.appendChild(meta);

    if (typeof focusHandleIndex === 'number') {
      const handle = track.querySelector('.rrm-allocation-handle[data-boundary-index="' + focusHandleIndex + '"]');
      if (handle) handle.focus();
    }
  }

  function handleVisualOffset(boundaries, index) {
    let overlapRank = 0;
    for (let i = 0; i < index; i++) {
      if (Math.abs(boundaries[i] - boundaries[index]) < 0.1) overlapRank++;
    }
    return overlapRank * 10;
  }

  function updateAllocationTrack(track, values, boundaries) {
    const segments = Array.from(track.querySelectorAll('.rrm-allocation-segment'));
    const handles = Array.from(track.querySelectorAll('.rrm-allocation-handle'));
    const metaItems = Array.from(track.parentElement.querySelectorAll('.rrm-allocation-meta span'));
    let left = 0;

    values.forEach((value, index) => {
      const segment = segments[index];
      if (!segment) return;
      segment.classList.toggle('is-tiny', value < 6);
      segment.style.left = left + '%';
      segment.style.width = value + '%';
      const label = segment.querySelector('span');
      if (label) label.textContent = formatChance(value) + '%';
      if (metaItems[index]) {
        metaItems[index].textContent = 'URL ' + (index + 1) + ': ' + formatChance(value) + '%';
      }
      left = roundChance(left + value);
    });

    boundaries.forEach((boundary, index) => {
      const handle = handles[index];
      if (!handle) return;
      handle.style.left = boundary + '%';
      handle.style.transform = 'translate(-50%, -50%) translateY(' + handleVisualOffset(boundaries, index) + 'px)';
      handle.setAttribute('aria-valuemin', formatChance(index === 0 ? 0 : boundaries[index - 1]));
      handle.setAttribute('aria-valuemax', formatChance(index === boundaries.length - 1 ? 100 : boundaries[index + 1]));
      handle.setAttribute('aria-valuenow', formatChance(boundary));
      handle.setAttribute('aria-valuetext', 'Boundary at ' + formatChance(boundary) + ' percent');
    });
  }

  function clampBoundary(value, boundaries, index) {
    const minGap = 0;
    const min = index === 0 ? 0 : boundaries[index - 1] + minGap;
    const max = index === boundaries.length - 1 ? 100 : boundaries[index + 1] - minGap;
    return Math.min(max, Math.max(min, roundChance(value)));
  }

  function applyBoundary(container, boundaries, index, value, focusHandleIndex) {
    const nextBoundaries = boundaries.slice();
    nextBoundaries[index] = clampBoundary(value, nextBoundaries, index);
    applyChanceValues(container, boundariesToValues(nextBoundaries));
    renderAllocationEditor(container, focusHandleIndex);
    return nextBoundaries;
  }

  function boundaryValueFromPointer(event, track) {
    const rect = track.getBoundingClientRect();
    if (rect.width <= 0) return 0;
    return ((event.clientX - rect.left) / rect.width) * 100;
  }

  function beginHandleDrag(event, track, container, index, boundaries) {
    event.preventDefault();
    const handle = event.currentTarget;
    let dragBoundaries = boundaries.slice();
    if (handle.setPointerCapture) handle.setPointerCapture(event.pointerId);

    const move = moveEvent => {
      dragBoundaries[index] = clampBoundary(boundaryValueFromPointer(moveEvent, track), dragBoundaries, index);
      const values = boundariesToValues(dragBoundaries);
      applyChanceValues(container, values);
      updateAllocationTrack(track, values, dragBoundaries);
    };
    const stop = stopEvent => {
      if (handle.releasePointerCapture) handle.releasePointerCapture(stopEvent.pointerId);
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', stop);
      window.removeEventListener('pointercancel', stop);
      renderAllocationEditor(container);
    };

    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', stop);
    window.addEventListener('pointercancel', stop);
    move(event);
  }

  function handleBoundaryKey(event, container, index, boundaries) {
    const current = boundaries[index];
    let next = current;

    if (event.key === 'ArrowRight' || event.key === 'ArrowUp') next = current + 1;
    else if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') next = current - 1;
    else if (event.key === 'PageUp') next = current + 10;
    else if (event.key === 'PageDown') next = current - 10;
    else if (event.key === 'Home') next = index === 0 ? 0 : boundaries[index - 1];
    else if (event.key === 'End') next = index === boundaries.length - 1 ? 100 : boundaries[index + 1];
    else return;

    event.preventDefault();
    applyBoundary(container, boundaries, index, next, index);
  }

  function updatePercentageSum(container) {
    const inputs = getChanceInputs(container);
    let sum = 0;
    let hasNonEmptyChance = false;

    inputs.forEach(input => {
      const value = parseFloat(input.value);
      if (Number.isFinite(value) && value > 0) sum += value;
      if (input.value.trim() !== '') hasNonEmptyChance = true;
    });

    const sumElement = container.closest('.redirect-list-col, .settings-group').querySelector('.percentage-sum');
    if (!sumElement) return;

    sumElement.textContent = sum.toFixed(1);

    const tolerance = 0.01;
    if (hasNonEmptyChance && Math.abs(sum - 100) > tolerance) {
      sumElement.classList.add('error');
    } else {
      sumElement.classList.remove('error');
    }
  }
});
