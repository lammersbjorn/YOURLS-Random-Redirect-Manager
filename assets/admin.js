      document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('random-redirect-form');
        if (!form) return;

        // --- Shortlink picker bootstrap ---
        let allShortlinks = [];
        const bootstrapEl = document.getElementById('rrm-bootstrap');
        if (bootstrapEl) {
          try {
            const data = JSON.parse(bootstrapEl.textContent);
            if (Array.isArray(data.shortlinks)) allShortlinks = data.shortlinks;
          } catch (e) { /* keep allShortlinks empty on parse failure */ }
        }

        const picker      = document.getElementById('rrm-picker');
        const pickerInput = document.getElementById('rrm-picker-q');
        const pickerList  = document.getElementById('rrm-picker-list');
        const pickerCancel = document.getElementById('rrm-picker-cancel');
        let pickerTarget = null;

        // Sleeky's plugin emits <meta name="sleeky_theme" content="light|dark">
        // when active. Tag the picker so its CSS picks the matching variant
        // instead of forcing a white modal onto a dark page.
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
                (l.url     || '').toLowerCase().includes(q) ||
                (l.title   || '').toLowerCase().includes(q)
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

        // --- Event Delegation ---
        form.addEventListener('click', function(event) {
          // Add URL button
          if (event.target.classList.contains('add-url')) {
            event.preventDefault();
            const container = event.target.closest('.redirect-list-col, .settings-group').querySelector('.url-chances-container');
            if (container) {
              addNewUrlRow(container);
            }
          }
          // Remove URL button
          else if (event.target.classList.contains('remove-url')) {
            event.preventDefault();
            const row = event.target.closest('.url-chance-row');
            const container = row.closest('.url-chances-container');
            removeUrlRow(row, container);
          }
          // Pick existing shortlink
          else if (event.target.classList.contains('pick-shortlink')) {
            event.preventDefault();
            const row = event.target.closest('.url-chance-row');
            const urlInput = row && row.querySelector('.url-input');
            if (urlInput) openPicker(urlInput);
          }
        });

        form.addEventListener('input', function(event) {
          // Chance input changes
          if (event.target.classList.contains('chance-input')) {
            const container = event.target.closest('.url-chances-container');
            if (container) {
              updatePercentageSum(container);
            }
          }
          // Keyword input changes (update header display)
          else if (event.target.classList.contains('keyword-input')) {
             const listSettings = event.target.closest('.redirect-list-settings');
             if (listSettings && !listSettings.classList.contains('add-new-list')) {
                const displaySpan = listSettings.querySelector('.keyword-display');
                if(displaySpan) {
                    displaySpan.textContent = event.target.value;
                }
             }
          }
          // New-list keyword toggles the required-state of its URL rows.
          if (event.target.id === 'new_list_keyword') {
            syncNewListRequired();
          }
        });

        // --- Initialization ---
        // Calculate initial percentage sums for all containers
        document.querySelectorAll('.url-chances-container').forEach(container => {
          updatePercentageSum(container);
        });
        // Apply the initial required-state to the new-list URL rows so a
        // pre-filled keyword (e.g. after a server-side validation bounce)
        // gets the required attribute right away.
        syncNewListRequired();
      });

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
            // Existing-list rows must always be required so the user
            // can't silently submit an empty URL. New-list rows track
            // the keyword field via syncNewListRequired() below.
            const isNewListRow = (urlInput.name || '').startsWith('new_list_urls');
            if (isNewListRow) {
                urlInput.removeAttribute('required');
            } else {
                urlInput.setAttribute('required', '');
            }
        }
        if (chanceInput) chanceInput.value = '';

        // Enable inputs (template inputs might be disabled)
        newRow.querySelectorAll('input').forEach(input => input.disabled = false);

        // Insert before the template
        container.insertBefore(newRow, template);

        updatePercentageSum(container);
        // Newly added new-list rows might still need to flip to required
        // if the user has already typed a keyword.
        syncNewListRequired();
        if (urlInput) urlInput.focus();
      }

      // Make the New-Redirect-List section's URL inputs required only
      // when the user has actually typed a new keyword. Otherwise the
      // empty starter row in that section would block every save.
      function syncNewListRequired() {
        const kwInput = document.getElementById('new_list_keyword');
        if (!kwInput) return;
        const hasKeyword = kwInput.value.trim() !== '';
        document
          .querySelectorAll('input[name="new_list_urls[]"]')
          .forEach((input) => {
            const row = input.closest('.url-chance-row');
            if (row && row.classList.contains('template')) return;
            if (hasKeyword) input.setAttribute('required', '');
            else input.removeAttribute('required');
          });
      }

      function removeUrlRow(row, container) {
        // Count visible rows excluding the template
        const visibleRows = Array.from(container.querySelectorAll('.url-chance-row:not(.template)'));

        if (visibleRows.length > 1) {
          row.remove();
          updatePercentageSum(container); // Update sum after removing
        } else {
          alert('You must have at least one URL in the list.');
        }
      }

      function updatePercentageSum(container) {
        const inputs = Array.from(container.querySelectorAll('.url-chance-row:not(.template) .chance-input'));
        let sum = 0;
        let hasNonEmptyChance = false;

        inputs.forEach(input => {
          const value = parseFloat(input.value);
          if (!isNaN(value) && value > 0) { // Only sum positive values
            sum += value;
          }
          if (input.value.trim() !== '') {
            hasNonEmptyChance = true;
          }
        });

        // Find the sum display element relative to the container
        const sumElement = container.closest('.redirect-list-col, .settings-group').querySelector('.percentage-sum');
        if (!sumElement) return;

        sumElement.textContent = sum.toFixed(1); // Use toFixed for consistent decimal display

        // Add error class if sum is positive but not close to 100, or if any chance was entered
        const tolerance = 0.01; // Allow for floating point inaccuracies
        if (hasNonEmptyChance && Math.abs(sum - 100) > tolerance) {
           sumElement.classList.add('error');
        } else {
           sumElement.classList.remove('error');
        }
      }