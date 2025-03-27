<?php
/**
 * Plugin Name: Random Redirect Manager
 * Plugin URI: https://github.com/lammersbjorn/YOURLS-Random-Redirect-Manager
 * Description: Redirects predefined keywords to a random URL from a list with customizable chance percentages. Creates shortlinks automatically.
 * Version: 3.2
 * Author: Bjorn Lammers
 * Author URI: https://github.com/lammersbjorn
 * License: BSD 3-Clause
 * License URI: https://opensource.org/licenses/BSD-3-Clause
 */

// Prevent direct access to this file
if (!defined('YOURLS_ABSPATH')) {
  die();
}

// Enable strict types for better code quality (optional, requires PHP 7+)
// declare(strict_types=1);

class RandomRedirectManager
{
  private const OPTION_NAME = 'random_redirect_settings';
  private array $settings = []; // Cache settings locally

  public function __construct()
  {
    // Load settings once
    $this->loadSettings();

    // Admin page hooks
    yourls_add_action('plugins_loaded', [$this, 'addAdminPage']);
    yourls_add_action(
      'admin_page_random_redirect_settings',
      [$this, 'displayAdminPage']
    );

    // Process form submissions
    yourls_add_action('admin_init', [$this, 'processFormSubmission']);

    // Check requests for redirects
    yourls_add_action('shutdown', [$this, 'checkRequest']);
  }

  /**
   * Load settings from YOURLS options.
   */
  private function loadSettings(): void
  {
    $settings = yourls_get_option(self::OPTION_NAME);
    $this->settings = is_array($settings) ? $settings : [];
  }

  /**
   * Check if the current request matches a configured keyword and perform redirect.
   */
  public function checkRequest(): void
  {
    // No need to reload settings if already loaded unless they might change mid-request
    // $this->loadSettings(); // Uncomment if settings could change between constructor and shutdown

    if (empty($this->settings)) {
      return;
    }

    // Get the requested keyword (path part of the URL)
    $request = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    // Decode URL-encoded characters (e.g., %20 -> space)
    $request = urldecode($request);

    if (
      isset($this->settings[$request]) &&
      !empty($this->settings[$request]['urls']) &&
      ($this->settings[$request]['enabled'] ?? false) // Default to false if not set
    ) {
      $listData = $this->settings[$request];
      $urls = $listData['urls'];
      $chances = $listData['chances'] ?? [];

      // Select URL based on chances
      $randomUrl = !empty($chances)
        ? $this->getWeightedRandomUrl($urls, $chances)
        : $urls[array_rand($urls)]; // Fallback to equal distribution

      // Perform the redirect
      // Use 307 Temporary Redirect to better indicate the resource itself hasn't moved
      yourls_redirect($randomUrl, 307);
      exit;
    }
  }

  /**
   * Get a weighted random URL based on chance percentages.
   *
   * @param array<int, string> $urls List of URLs.
   * @param array<int, float> $chances List of percentage chances corresponding to URLs.
   * @return string The selected URL.
   */
  private function getWeightedRandomUrl(array $urls, array $chances): string
  {
    // Ensure chances array matches urls array size, padding with 0 if needed
    $numUrls = count($urls);
    $chances = array_slice($chances, 0, $numUrls);
    $chances = array_pad($chances, $numUrls, 0.0);

    $validChances = array_filter($chances, fn($c) => $c > 0);

    // If no valid positive chances, return a random URL with equal probability
    if (empty($validChances)) {
      return $urls[array_rand($urls)];
    }

    $totalPercentage = array_sum($validChances);

    // If total is zero (e.g., all chances were 0 or negative), distribute equally
    if ($totalPercentage <= 0) {
      return $urls[array_rand($urls)];
    }

    $cumulative = 0.0;
    $distribution = [];
    foreach ($urls as $index => $url) {
      $chance = $chances[$index] ?? 0.0;
      if ($chance > 0) {
        // Normalize percentage based on the sum of positive chances
        $normalizedPercentage = ($chance / $totalPercentage) * 100.0;
        $cumulative += $normalizedPercentage;
        $distribution[$index] = $cumulative;
      }
    }

    // Get a random number between 0 and 100
    $random = mt_rand(0, 10000) / 100.0;

    // Find which URL the random number corresponds to
    foreach ($distribution as $index => $threshold) {
      if ($random <= $threshold) {
        return $urls[$index];
      }
    }

    // Fallback (should only happen with floating point inaccuracies or empty distribution)
    return $urls[array_key_last($urls)];
  }

  /**
   * Add admin page link to the YOURLS menu.
   */
  public function addAdminPage(): void
  {
    yourls_register_plugin_page(
      'random_redirect_settings', // Page slug
      'Random Redirect Manager', // Page title
      [$this, 'displayAdminPage'] // Display function
    );
  }

  /**
   * Display the admin settings page HTML.
   */
  public function displayAdminPage(): void
  {
    if (!yourls_is_admin()) {
      yourls_die('Access denied', 'Permission Denied', 403);
    }

    $nonce = yourls_create_nonce('random_redirect_settings_nonce');
    // Settings are already loaded in $this->settings

    // Output CSS and JS first
    $this->displayAdminAssets();

    // Output HTML using HEREDOC for better readability
    echo <<<HTML
    <h2>Random Redirect Manager</h2>

    <div class="notice notice-info">
      <p><strong>Note:</strong> When you add or update a redirect list, the plugin automatically creates/updates the corresponding YOURLS shortlink. The first URL in the list is used as the target for the shortlink.</p>
      <p><strong>Chance Percentages:</strong> Define the probability for each URL. The system normalizes positive percentages if they don't sum to 100%. URLs with 0% or no percentage set won't be chosen unless all percentages are zero (then it's equal distribution).</p>
    </div>

    <form method="post" id="random-redirect-form">
      <input type="hidden" name="nonce" value="{$nonce}">
      <input type="hidden" name="action" value="update_random_redirect_settings">

      <div class="redirect-lists-container">
HTML;

    // Display existing lists
    if (!empty($this->settings)) {
      foreach ($this->settings as $keyword => $listData) {
        $this->displayExistingListForm($keyword, $listData);
      }
    } else {
      echo '<p>No redirect lists configured yet.</p>';
    }

    echo <<<HTML
      </div> <!-- .redirect-lists-container -->

      <h3>Add New Redirect List</h3>
      <div class="settings-group add-new-list">
HTML;

    // Display form for adding a new list
    $this->displayNewListForm();

    echo <<<HTML
      </div> <!-- .settings-group.add-new-list -->

      <p><input type="submit" value="Save All Settings" class="button button-primary"></p>
    </form>
HTML;
  }

  /**
   * Display the form section for an existing redirect list.
   *
   * @param string $keyword The keyword for the list.
   * @param array $listData The data for the list (enabled, urls, chances).
   */
  private function displayExistingListForm(string $keyword, array $listData): void
  {
    $escapedKeyword = htmlspecialchars($keyword);
    $isEnabled = $listData['enabled'] ?? true; // Default to enabled
    $checked = $isEnabled ? 'checked' : '';
    $urls = $listData['urls'] ?? [];
    $chances = $listData['chances'] ?? [];

    echo <<<HTML
        <div class="redirect-list-settings" data-keyword="{$escapedKeyword}">
          <div class="redirect-list-header">
            <h4>Keyword: <span class="keyword-display">{$escapedKeyword}</span></h4>
            <div class="list-actions">
              <label class="redirect-list-toggle">
                <input type="checkbox" name="list_enabled[{$escapedKeyword}]" value="1" {$checked}>
                Enable
              </label>
              <button type="submit" name="delete_list" value="{$escapedKeyword}"
                class="button delete-list-button" onclick="return confirm('Are you sure you want to delete the list for keyword \'{$escapedKeyword}\'? This action is immediate and cannot be undone.')">
                Delete List
              </button>
            </div>
          </div>
          <div class="redirect-list-content">
            <div class="redirect-list-row">
              <div class="redirect-list-col">
                <label for="list_keyword_{$escapedKeyword}">Keyword (edit):</label>
                <input type="text" id="list_keyword_{$escapedKeyword}"
                  name="list_keyword[{$escapedKeyword}]"
                  value="{$escapedKeyword}"
                  class="text keyword-input"
                  required
                  pattern="^[a-zA-Z0-9-_\/]+$"
                  title="Allowed characters: a-z, A-Z, 0-9, -, _, /">
              </div>
            </div>
            <div class="redirect-list-row">
              <div class="redirect-list-col full">
                <label>URLs and Chances (%):</label>
                <div class="url-chances-container">
HTML;

    // Display URL/Chance rows
    if (!empty($urls)) {
      foreach ($urls as $index => $url) {
        $escapedUrl = htmlspecialchars($url);
        $chance = isset($chances[$index]) ? htmlspecialchars((string)$chances[$index]) : '';
        $this->displayUrlChanceRow(
          "list_urls[{$escapedKeyword}][]",
          "list_chances[{$escapedKeyword}][]",
          $escapedUrl,
          $chance
        );
      }
    } else {
      // Display one empty row if the list is somehow empty
      $this->displayUrlChanceRow(
        "list_urls[{$escapedKeyword}][]",
        "list_chances[{$escapedKeyword}][]"
      );
    }

    // Hidden template row for JS cloning
    $this->displayUrlChanceRow(
      "list_urls[{$escapedKeyword}][]",
      "list_chances[{$escapedKeyword}][]",
      '',
      '',
      true // isTemplate
    );

    echo <<<HTML
                </div> <!-- .url-chances-container -->
                <div class="url-actions">
                  <button type="button" class="add-url button button-secondary">Add URL</button>
                  <div class="total-percentage">
                    Total: <span class="percentage-sum">0</span>%
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div> <!-- .redirect-list-settings -->
HTML;
  }

  /**
   * Display the form section for adding a new redirect list.
   */
  private function displayNewListForm(): void
  {
    echo <<<HTML
        <div class="redirect-list-row">
          <div class="redirect-list-col">
            <label for="new_list_keyword">New Keyword:</label>
            <input type="text" id="new_list_keyword" name="new_list_keyword" class="text keyword-input"
              placeholder="Enter keyword (e.g., random-link)"
              pattern="^[a-zA-Z0-9-_\/]+$"
              title="Allowed characters: a-z, A-Z, 0-9, -, _, /">
          </div>
        </div>
        <div class="redirect-list-row">
          <div class="redirect-list-col full">
            <label>URLs and Chances (%):</label>
            <div class="url-chances-container">
HTML;

    // Display one empty row initially
    $this->displayUrlChanceRow("new_list_urls[]", "new_list_chances[]");

    // Hidden template row for JS cloning
    $this->displayUrlChanceRow("new_list_urls[]", "new_list_chances[]", '', '', true);

    echo <<<HTML
            </div> <!-- .url-chances-container -->
            <div class="url-actions">
              <button type="button" class="add-url button button-secondary">Add URL</button>
              <div class="total-percentage">
                Total: <span class="percentage-sum">0</span>%
              </div>
            </div>
          </div>
        </div>
HTML;
  }

  /**
   * Helper function to display a single URL/Chance row.
   *
   * @param string $urlName Name attribute for URL input.
   * @param string $chanceName Name attribute for chance input.
   * @param string $urlValue Value for URL input.
   * @param string $chanceValue Value for chance input.
   * @param bool $isTemplate Whether this is the hidden template row.
   */
  private function displayUrlChanceRow(
    string $urlName,
    string $chanceName,
    string $urlValue = '',
    string $chanceValue = '',
    bool $isTemplate = false
  ): void {
    $style = $isTemplate ? 'style="display: none;"' : '';
    $class = $isTemplate ? 'url-chance-row template' : 'url-chance-row';
    $urlRequired = !$isTemplate && empty($urlValue) ? 'required' : ''; // Require first URL

    echo <<<HTML
        <div class="{$class}" {$style}>
          <input type="url" name="{$urlName}" value="{$urlValue}" class="text url-input" placeholder="https://example.com" {$urlRequired}>
          <input type="number" name="{$chanceName}" value="{$chanceValue}" class="text chance-input" min="0" max="100" step="any" placeholder="%">
          <span class="percent-sign">%</span>
          <button type="button" class="remove-url button" aria-label="Remove URL">✕</button>
        </div>
HTML;
  }

  /**
   * Output CSS and JavaScript for the admin page.
   */
  private function displayAdminAssets(): void
  {
    // CSS - Using HEREDOC for multiline string
    $css = <<<'CSS'
      .notice { margin: 15px 0; padding: 10px 15px; border-radius: 5px; }
      .notice-info { background-color: rgba(0, 128, 255, 0.1); border-left: 4px solid #0080ff; }
      .settings-group, .redirect-list-settings { margin: 20px 0; padding: 15px; border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 5px; }
      .redirect-lists-container { margin: 20px 0; display: flex; flex-direction: column; gap: 15px; }
      .redirect-list-header { display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; border-bottom: 1px solid rgba(128, 128, 128, 0.2); background-color: rgba(128, 128, 128, 0.05); }
      .redirect-list-header h4 { margin: 0; }
      .list-actions { display: flex; align-items: center; gap: 10px; }
      .redirect-list-content { padding: 15px; }
      .redirect-list-row { display: flex; gap: 15px; margin-bottom: 15px; }
      .redirect-list-row:last-child { margin-bottom: 0; }
      .redirect-list-col { flex: 1; }
      .redirect-list-col.full { flex: 0 0 100%; }
      .redirect-list-col label { display: block; margin-bottom: 5px; font-weight: bold; }
      input.text, textarea.text { width: 100%; padding: 8px; border: 1px solid rgba(128, 128, 128, 0.3); border-radius: 3px; background: transparent; color: inherit; box-sizing: border-box; }
      input:required:invalid { border-color: #f44336; }
      .redirect-list-toggle { display: flex; align-items: center; gap: 5px; font-weight: normal; }
      .button.delete-list-button { background-color: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; }
      .button.delete-list-button:hover { background-color: #c82333; }
      .url-chances-container { display: flex; flex-direction: column; gap: 8px; margin-bottom: 10px; }
      .url-chance-row { display: flex; align-items: center; gap: 10px; width: 100%; }
      .url-input { flex: 3; min-width: 250px; }
      .chance-input { flex: 0 0 80px; width: 80px; text-align: right; }
      .percent-sign { flex: 0 0 10px; margin-right: 5px; }
      .remove-url { flex: 0 0 25px; background-color: #f44336; color: white; border: none; width: 25px; height: 25px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 12px; padding: 0; line-height: 1; }
      .remove-url:hover { background-color: #e53935; }
      .url-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; }
      .total-percentage { color: #666; font-weight: bold; }
      .percentage-sum { color: #008000; }
      .percentage-sum.error { color: #f44336; font-weight: bold; }
      .add-url { cursor: pointer; }
      @media (max-width: 768px) {
        .redirect-list-row { flex-direction: column; gap: 10px; }
        .url-chance-row { flex-wrap: wrap; }
        .url-input { min-width: 200px; }
        .list-actions { flex-direction: column; align-items: flex-start; gap: 5px; }
      }
CSS;

    // JavaScript - Using HEREDOC, note the $ escaping
    $js = <<<'JS'
      document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('random-redirect-form');
        if (!form) return;

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
        });

        // --- Initialization ---
        // Calculate initial percentage sums for all containers
        document.querySelectorAll('.url-chances-container').forEach(container => {
          updatePercentageSum(container);
        });
      });

      function addNewUrlRow(container) {
        const template = container.querySelector('.template');
        if (!template) return;

        const newRow = template.cloneNode(true);
        newRow.style.display = 'flex';
        newRow.classList.remove('template');

        // Clear template values and remove 'required' if it was added dynamically
        const urlInput = newRow.querySelector('.url-input');
        const chanceInput = newRow.querySelector('.chance-input');
        if(urlInput) {
            urlInput.value = '';
            urlInput.removeAttribute('required'); // Only first row might be required initially
        }
        if(chanceInput) chanceInput.value = '';

        // Enable inputs (template inputs might be disabled)
        newRow.querySelectorAll('input').forEach(input => input.disabled = false);

        // Insert before the template
        container.insertBefore(newRow, template);

        updatePercentageSum(container); // Update sum after adding
        if(urlInput) urlInput.focus(); // Focus new URL input
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
JS;

    // Output the CSS and JS
    echo "<style type=\"text/css\">\n" . $css . "\n</style>\n";
    echo "<script type=\"text/javascript\">\n" . $js . "\n</script>\n";
  }


  /**
   * Process admin form submission for updating/adding/deleting lists.
   */
  public function processFormSubmission(): void
  {
    // Check if it's our action and user is admin
    if (
      !isset($_POST['action']) ||
      $_POST['action'] !== 'update_random_redirect_settings' ||
      !yourls_is_admin()
    ) {
      return;
    }

    // Verify nonce
    yourls_verify_nonce(
      'random_redirect_settings_nonce',
      $_POST['nonce'] ?? '',
      false // Do not die, just return false
    ) or
      yourls_die('Invalid security token', 'Error', 403);

    $currentSettings = $this->settings; // Use cached settings
    $newSettings = [];
    $messages = ['updated' => 0, 'created' => 0, 'deleted' => 0, 'errors' => []];

    // --- Handle Delete Action ---
    if (isset($_POST['delete_list']) && !empty($_POST['delete_list'])) {
      $keywordToDelete = $this->sanitizeKeyword($_POST['delete_list']);
      if ($keywordToDelete && isset($currentSettings[$keywordToDelete])) {
        unset($currentSettings[$keywordToDelete]);
        // Note: We don't delete the base YOURLS shortlink automatically. Admin can do that manually if desired.
        if (yourls_update_option(self::OPTION_NAME, $currentSettings)) {
          yourls_add_notice(
            "Redirect list for keyword '{$keywordToDelete}' deleted successfully."
          );
          $this->loadSettings(); // Reload settings after successful delete
        } else {
          yourls_add_notice(
            "Error deleting list for keyword '{$keywordToDelete}'.",
            'error'
          );
        }
        // Stop further processing after delete
        return;
      } else {
        yourls_add_notice(
          "Could not delete list: Keyword '{$keywordToDelete}' not found.",
          'error'
        );
        return; // Stop if delete was intended but failed
      }
    }

    // --- Process Existing Lists ---
    if (isset($_POST['list_keyword']) && is_array($_POST['list_keyword'])) {
      foreach ($_POST['list_keyword'] as $oldKeyword => $newKeywordInput) {
        $oldKeyword = $this->sanitizeKeyword($oldKeyword); // Sanitize the key from POST
        $newKeyword = $this->sanitizeKeyword($newKeywordInput);

        if (!$oldKeyword || !$newKeyword) {
          $messages['errors'][] =
            "Invalid or empty keyword provided for an existing list (original: '{$oldKeyword}'). Skipped.";
          continue;
        }

        // Check if keyword changed and the new one is already taken by another list *in this submission*
        if (
          $oldKeyword !== $newKeyword &&
          isset($newSettings[$newKeyword])
        ) {
          $messages['errors'][] =
            "Keyword '{$newKeyword}' is used multiple times in this submission. Reverted change for '{$oldKeyword}'.";
          $newKeyword = $oldKeyword; // Keep the old keyword to avoid conflict
        }

        $urls = isset($_POST['list_urls'][$oldKeyword])
          ? $this->sanitizeUrlArray($_POST['list_urls'][$oldKeyword])
          : [];
        $chances = isset($_POST['list_chances'][$oldKeyword])
          ? $this->sanitizeChanceArray($_POST['list_chances'][$oldKeyword])
          : [];

        if (empty($urls)) {
          $messages['errors'][] =
            "No valid URLs provided for keyword '{$newKeyword}'. List not saved.";
          continue; // Skip if no URLs
        }

        // Ensure chances array matches urls array size
        $chances = array_slice($chances, 0, count($urls));
        $chances = array_pad($chances, count($urls), 0.0);

        $newSettings[$newKeyword] = [
          'enabled' => isset($_POST['list_enabled'][$oldKeyword]),
          'urls' => $urls,
          'chances' => $chances,
        ];

        // Ensure shortlink exists/is updated for the *new* keyword
        $this->ensureShortlinkExists(
          $newKeyword,
          $urls[0], // Use first URL
          $messages['created'],
          $messages['updated'],
          $messages['errors']
        );

        // If keyword changed, we might need to handle the old shortlink?
        // For now, we leave the old shortlink as is. User can delete it manually.
      }
    }

    // --- Process New List ---
    if (
      !empty($_POST['new_list_keyword']) &&
      !empty($_POST['new_list_urls'])
    ) {
      $newKeyword = $this->sanitizeKeyword($_POST['new_list_keyword']);
      $urls = $this->sanitizeUrlArray($_POST['new_list_urls']);
      $chances = isset($_POST['new_list_chances'])
        ? $this->sanitizeChanceArray($_POST['new_list_chances'])
        : [];

      if (!$newKeyword) {
        $messages['errors'][] = 'Invalid or empty keyword provided for the new list. Skipped.';
      } elseif (isset($newSettings[$newKeyword])) {
        $messages['errors'][] =
          "The new keyword '{$newKeyword}' conflicts with another list in this submission. New list skipped.";
      } elseif (empty($urls)) {
        $messages['errors'][] =
          "No valid URLs provided for the new keyword '{$newKeyword}'. New list not saved.";
      } else {
        // Ensure chances array matches urls array size
        $chances = array_slice($chances, 0, count($urls));
        $chances = array_pad($chances, count($urls), 0.0);

        $newSettings[$newKeyword] = [
          'enabled' => true, // New lists are enabled by default
          'urls' => $urls,
          'chances' => $chances,
        ];

        // Create shortlink for the new keyword
        $this->ensureShortlinkExists(
          $newKeyword,
          $urls[0],
          $messages['created'],
          $messages['updated'],
          $messages['errors']
        );
      }
    }

    // --- Save Settings ---
    // Only update if there were no critical errors preventing saving (like keyword conflicts handled above)
    if (yourls_update_option(self::OPTION_NAME, $newSettings)) {
      yourls_add_notice('Random Redirect settings saved successfully.');
      $this->loadSettings(); // Reload settings after successful save
    } else {
      yourls_add_notice('Error saving settings.', 'error');
    }

    // --- Display Summary Notices ---
    if ($messages['created'] > 0) {
      yourls_add_notice("Created {$messages['created']} new shortlink(s).");
    }
    if ($messages['updated'] > 0) {
      yourls_add_notice("Updated {$messages['updated']} existing shortlink(s).");
    }
    foreach ($messages['errors'] as $error) {
      yourls_add_notice($error, 'error');
    }
  }

  /**
   * Sanitize a keyword string. Allows alphanumeric, hyphen, underscore, forward slash.
   *
   * @param mixed $keyword Input keyword.
   * @return string Sanitized keyword or empty string if invalid.
   */
  private function sanitizeKeyword($keyword): string
  {
    if (!is_string($keyword)) {
      return '';
    }
    $keyword = trim($keyword);
    // Allow letters, numbers, hyphen, underscore, forward slash
    // Remove leading/trailing slashes and collapse multiple slashes
    $keyword = trim($keyword, '/');
    $keyword = preg_replace('#/+#', '/', $keyword);
    if (preg_match('/^[a-zA-Z0-9-_\/]+$/', $keyword)) {
      return $keyword;
    }
    return ''; // Invalid characters
  }

  /**
   * Sanitize an array of URLs.
   *
   * @param mixed $urls Input array.
   * @return array<int, string> Array of valid URLs.
   */
  private function sanitizeUrlArray($urls): array
  {
    if (!is_array($urls)) {
      return [];
    }
    $sanitizedUrls = [];
    foreach ($urls as $url) {
      if (is_string($url)) {
        $trimmedUrl = trim($url);
        // Use filter_var for basic URL validation
        if (!empty($trimmedUrl) && filter_var($trimmedUrl, FILTER_VALIDATE_URL)) {
          $sanitizedUrls[] = $trimmedUrl;
        }
      }
    }
    // Re-index the array
    return array_values($sanitizedUrls);
  }

  /**
   * Sanitize an array of chance values.
   *
   * @param mixed $chances Input array.
   * @return array<int, float> Array of valid, non-negative float chances.
   */
  private function sanitizeChanceArray($chances): array
  {
    if (!is_array($chances)) {
      return [];
    }
    $sanitizedChances = [];
    foreach ($chances as $chance) {
      $floatVal = filter_var($chance, FILTER_VALIDATE_FLOAT);
      // Ensure it's a non-negative float, default to 0.0 if empty or invalid
      $sanitizedChances[] =
        $floatVal !== false && $floatVal >= 0 ? $floatVal : 0.0;
    }
    return $sanitizedChances;
  }


  /**
   * Ensure a YOURLS shortlink exists for the given keyword, pointing to the first URL.
   * Creates or updates the shortlink as needed.
   *
   * @param string $keyword The keyword (must be sanitized).
   * @param string $url The target URL (must be sanitized and valid).
   * @param int &$created Counter for created shortlinks.
   * @param int &$updated Counter for updated shortlinks.
   * @param array &$errors Array to store error messages.
   */
  private function ensureShortlinkExists(
    string $keyword,
    string $url,
    int &$created,
    int &$updated,
    array &$errors
  ): void {
    if (empty($keyword) || empty($url)) {
      $errors[] =
        "Cannot ensure shortlink: Invalid keyword or URL provided. Keyword: '{$keyword}'";
      return;
    }

    // Check if keyword is reserved or already taken by YOURLS core/another plugin
    if (yourls_keyword_is_reserved($keyword)) {
        $errors[] = "Keyword '{$keyword}' is reserved and cannot be used.";
        return;
    }

    $existingUrl = yourls_get_keyword_longurl($keyword);

    if ($existingUrl === false) {
      // Shortlink does not exist, create it
      $result = yourls_add_new_link($url, $keyword);
      if ($result && $result['status'] === 'success') {
        $created++;
      } elseif ($result && isset($result['message'])) {
        // Check if the failure was due to the keyword already existing (race condition?)
        if (strpos($result['message'], 'already exists') === false) {
             $errors[] = "Failed to create shortlink for '{$keyword}': {$result['message']}";
        } else {
            // Keyword exists now, maybe created by another process or race condition. Try updating.
            $existingUrl = yourls_get_keyword_longurl($keyword); // Re-fetch
             if ($existingUrl !== false && $existingUrl !== $url) {
                if (yourls_edit_link_url($keyword, $url)) { // Use specific function if available (YOURLS 1.7.3+)
                    $updated++;
                } else {
                    // Fallback for older YOURLS or if specific function fails
                    if (yourls_edit_link($url, $keyword, $keyword)) { // This updates URL and optionally title
                        $updated++;
                    } else {
                        $errors[] = "Failed to update existing shortlink URL for '{$keyword}' after creation conflict.";
                    }
                }
            }
        }
      } else {
        $errors[] = "Failed to create shortlink for '{$keyword}' (Unknown error).";
      }
    } elseif ($existingUrl !== $url) {
      // Shortlink exists, but URL is different, update it
       if (function_exists('yourls_edit_link_url') && yourls_edit_link_url($keyword, $url)) { // Use specific function if available (YOURLS 1.7.3+)
            $updated++;
        } else {
            // Fallback for older YOURLS or if specific function fails
            if (yourls_edit_link($url, $keyword, $keyword)) { // This updates URL and optionally title
                $updated++;
            } else {
                $errors[] = "Failed to update existing shortlink URL for '{$keyword}'.";
            }
        }
    }
    // If $existingUrl === $url, do nothing, it's already correct.
  }
}

// Initialize the plugin
new RandomRedirectManager();
