<?php
/**
 * Plugin Name: Random Redirect Manager
 * Plugin URI: ttps://github.com/lammersbjorn/YOURLS-Random-Redirect-Manager
 * Description: Redirects predefined keywords to a random URL from a list with customizable chance percentages. Creates shortlinks automatically.
 * Version: 3.1
 * Author: Bjorn Lammers
 * Author URI: https://github.com/lammersbjorn
 */

// Prevent direct access to this file
if (!defined('YOURLS_ABSPATH')) die();

class RandomRedirectManager
{
  private const OPTION_NAME = 'random_redirect_settings';

  public function __construct()
  {
    // Admin page hooks
    yourls_add_action('plugins_loaded', [$this, 'addAdminPage']);
    yourls_add_action('admin_page_random_redirect_settings', [$this, 'displayAdminPage']);

    // Process form submissions
    yourls_add_action('admin_init', [$this, 'processFormSubmission']);

    // Check requests for redirects
    yourls_add_action('shutdown', [$this, 'checkRequest']);
  }

  /**
   * Check if current request should be redirected
   */
  public function checkRequest(): void
  {
    $settings = yourls_get_option(self::OPTION_NAME);
    if (!$settings || !is_array($settings)) return;

    $request = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

    if (isset($settings[$request]) && !empty($settings[$request]['urls']) && $settings[$request]['enabled']) {
      $urls = $settings[$request]['urls'];
      $chances = $settings[$request]['chances'] ?? [];

      // If chances are defined, use weighted random selection
      if (!empty($chances)) {
        $randomUrl = $this->getWeightedRandomUrl($urls, $chances);
      } else {
        // Fallback to equal distribution if no chances defined
        $randomUrl = $urls[array_rand($urls)];
      }

      yourls_redirect($randomUrl, 302);
      exit;
    }
  }

  /**
   * Get a weighted random URL based on chance percentages
   *
   * @param array $urls List of URLs
   * @param array $chances List of percentage chances
   * @return string The selected URL
   */
  private function getWeightedRandomUrl(array $urls, array $chances): string
  {
    // Convert percentages to a cumulative distribution
    $cumulative = [];
    $total = 0;

    // Calculate actual total (may not be 100%)
    $totalPercentage = array_sum($chances);

    foreach ($urls as $index => $url) {
      $percentage = isset($chances[$index]) ? (float)$chances[$index] : 0;
      // Normalize percentage if total isn't 100%
      if ($totalPercentage > 0) {
        $normalizedPercentage = ($percentage / $totalPercentage) * 100;
      } else {
        // Default to equal distribution if all percentages are 0
        $normalizedPercentage = 100 / count($urls);
      }

      $total += $normalizedPercentage;
      $cumulative[$index] = $total;
    }

    // Get a random number between 0 and 100
    $random = mt_rand(0, 10000) / 100;

    // Find which URL the random number corresponds to
    foreach ($cumulative as $index => $threshold) {
      if ($random <= $threshold) {
        return $urls[$index];
      }
    }

    // Fallback (should not reach here unless rounding issues)
    return $urls[array_key_last($urls)];
  }

  /**
   * Add admin page to YOURLS menu
   */
  public function addAdminPage(): void
  {
    yourls_register_plugin_page('random_redirect_settings', 'Random Redirect Manager', [$this, 'displayAdminPage']);
  }

  /**
   * Display admin settings page
   */
  public function displayAdminPage(): void
  {
    if (!yourls_is_admin()) die('Access denied');

    $nonce = yourls_create_nonce('random_redirect_settings');
    $settings = yourls_get_option(self::OPTION_NAME) ?: [];
?>
    <h2>Random Redirect Manager</h2>

    <div class="notice info">
      <p><strong>Note:</strong> When you add a new redirect list or update an existing one, the plugin will automatically create a shortlink if it doesn't already exist. The first URL in the list will be used as the target URL for the shortlink creation.</p>
      <p><strong>Chance Percentages:</strong> You can set the chance percentage for each URL. The percentages should ideally sum to 100%, but the system will normalize the values if they don't.</p>
    </div>

    <form method="post">
      <input type="hidden" name="nonce" value="<?php echo $nonce; ?>">
      <input type="hidden" name="action" value="update_random_redirect_settings">

      <div class="redirect-lists-container">
        <?php foreach ($settings as $keyword => $listData): ?>
          <div class="redirect-list-settings">
            <div class="redirect-list-header">
              <h4>Keyword: <?php echo htmlspecialchars($keyword); ?></h4>
              <div class="list-actions">
                <label class="redirect-list-toggle">
                  <input type="checkbox" name="list_enabled[<?php echo $keyword; ?>]" value="1"
                    <?php echo ($listData['enabled'] ?? true) ? 'checked' : ''; ?>>
                  Enable
                </label>
                <button type="submit" name="delete_list" value="<?php echo htmlspecialchars($keyword); ?>"
                  class="button delete" onclick="return confirm('Are you sure you want to delete this list?')">
                  Delete
                </button>
              </div>
            </div>
            <div class="redirect-list-content">
              <div class="redirect-list-row">
                <div class="redirect-list-col">
                  <label for="list_keyword_<?php echo $keyword; ?>">Keyword:</label>
                  <input type="text" id="list_keyword_<?php echo $keyword; ?>"
                    name="list_keyword[<?php echo $keyword; ?>]"
                    value="<?php echo htmlspecialchars($keyword); ?>"
                    class="text">
                </div>
              </div>
              <div class="redirect-list-row">
                <div class="redirect-list-col full">
                  <label>URLs and Chances:</label>
                  <div class="url-chances-container">
                    <?php
                    $urls = $listData['urls'] ?? [];
                    $chances = $listData['chances'] ?? [];
                    foreach ($urls as $index => $url):
                      $chance = isset($chances[$index]) ? $chances[$index] : '';
                    ?>
                      <div class="url-chance-row">
                        <input type="url"
                          name="list_urls[<?php echo $keyword; ?>][]"
                          value="<?php echo htmlspecialchars($url); ?>"
                          class="text url-input"
                          placeholder="https://example.com">
                        <input type="number"
                          name="list_chances[<?php echo $keyword; ?>][]"
                          value="<?php echo htmlspecialchars($chance); ?>"
                          class="text chance-input"
                          min="0"
                          max="100"
                          step="0.1"
                          placeholder="%">
                        <span class="percent-sign">%</span>
                        <button type="button" class="remove-url button" aria-label="Remove URL">✕</button>
                      </div>
                    <?php endforeach; ?>
                    <div class="url-chance-row template" style="display: none;">
                      <input type="url" name="list_urls[<?php echo $keyword; ?>][]" value="" class="text url-input" placeholder="https://example.com">
                      <input type="number" name="list_chances[<?php echo $keyword; ?>][]" value="" class="text chance-input" min="0" max="100" step="0.1" placeholder="%">
                      <span class="percent-sign">%</span>
                      <button type="button" class="remove-url button" aria-label="Remove URL">✕</button>
                    </div>
                  </div>
                  <div class="url-actions">
                    <button type="button" class="add-url button secondary" data-keyword="<?php echo $keyword; ?>">Add URL</button>
                    <div class="total-percentage">
                      Total: <span class="percentage-sum">0</span>%
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <h3>Add New Redirect List</h3>
      <div class="settings-group">
        <div class="redirect-list-row">
          <div class="redirect-list-col">
            <label for="new_list_keyword">Keyword:</label>
            <input type="text" id="new_list_keyword" name="new_list_keyword" class="text"
              placeholder="Enter keyword to trigger redirect">
          </div>
        </div>
        <div class="redirect-list-row">
          <div class="redirect-list-col full">
            <label>URLs and Chances:</label>
            <div class="url-chances-container new-list-container">
              <div class="url-chance-row">
                <input type="url" name="new_list_urls[]" value="" class="text url-input" placeholder="https://example.com">
                <input type="number" name="new_list_chances[]" value="" class="text chance-input" min="0" max="100" step="0.1" placeholder="%">
                <span class="percent-sign">%</span>
                <button type="button" class="remove-url button" aria-label="Remove URL">✕</button>
              </div>
              <div class="url-chance-row template" style="display: none;">
                <input type="url" name="new_list_urls[]" value="" class="text url-input" placeholder="https://example.com">
                <input type="number" name="new_list_chances[]" value="" class="text chance-input" min="0" max="100" step="0.1" placeholder="%">
                <span class="percent-sign">%</span>
                <button type="button" class="remove-url button" aria-label="Remove URL">✕</button>
              </div>
            </div>
            <div class="url-actions">
              <button type="button" class="add-url button secondary" data-container="new-list-container">Add URL</button>
              <div class="total-percentage">
                Total: <span class="percentage-sum">0</span>%
              </div>
            </div>
          </div>
        </div>
      </div>

      <p><input type="submit" value="Save Settings" class="button primary"></p>
    </form>

    <style>
      .notice {
        margin: 15px 0;
        padding: 10px 15px;
        border-radius: 5px;
      }

      .notice.info {
        background-color: rgba(0, 128, 255, 0.1);
        border-left: 4px solid #0080ff;
      }

      .settings-group {
        margin: 20px 0;
        padding: 15px;
        border: 1px solid rgba(128, 128, 128, 0.2);
        border-radius: 5px;
      }

      .redirect-lists-container {
        margin: 20px 0;
        display: flex;
        flex-direction: column;
        gap: 15px;
      }

      .redirect-list-settings {
        border: 1px solid rgba(128, 128, 128, 0.2);
        border-radius: 5px;
      }

      .redirect-list-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 15px;
        border-bottom: 1px solid rgba(128, 128, 128, 0.2);
      }

      .redirect-list-header h4 {
        margin: 0;
      }

      .list-actions {
        display: flex;
        align-items: center;
        gap: 10px;
      }

      .redirect-list-content {
        padding: 15px;
      }

      .redirect-list-row {
        display: flex;
        gap: 15px;
        margin-bottom: 15px;
      }

      .redirect-list-row:last-child {
        margin-bottom: 0;
      }

      .redirect-list-col {
        flex: 1;
      }

      .redirect-list-col.full {
        flex: 0 0 100%;
      }

      .redirect-list-col label {
        display: block;
        margin-bottom: 5px;
      }

      input.text,
      textarea.text {
        width: 100%;
        padding: 5px;
        border: 1px solid rgba(128, 128, 128, 0.2);
        border-radius: 3px;
        background: transparent;
        color: inherit;
      }

      .redirect-list-toggle {
        display: flex;
        align-items: center;
        gap: 5px;
      }

      .button.delete {
        background-color: #f44336;
        color: white;
        border: none;
        padding: 5px 10px;
        border-radius: 3px;
        cursor: pointer;
      }

      /* Improved styles for URL chances */
      .url-chances-container {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-bottom: 10px;
      }

      .url-chance-row {
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
      }

      .url-input {
        flex: 3;
        min-width: 300px;
      }

      .chance-input {
        flex: 0 0 80px;
        width: 80px;
        text-align: right;
      }

      .percent-sign {
        flex: 0 0 10px;
        margin-right: 5px;
      }

      .remove-url {
        flex: 0 0 25px;
        background-color: #f44336;
        color: white;
        border: none;
        width: 25px;
        height: 25px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 12px;
        padding: 0;
      }

      .url-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 10px;
      }

      .total-percentage {
        color: #666;
        font-weight: bold;
      }

      .percentage-sum {
        color: #008000;
      }

      .percentage-sum.error {
        color: #f44336;
      }

      /* Add button styling */
      .add-url {
        cursor: pointer;
      }

      /* For responsive design */
      @media (max-width: 768px) {
        .redirect-list-row {
          flex-direction: column;
          gap: 10px;
        }

        .url-chance-row {
          flex-wrap: wrap;
        }

        .url-input {
          min-width: 200px;
        }
      }
    </style>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
        setupEventHandlers();

        // Calculate initial percentage sums
        document.querySelectorAll('.url-chances-container').forEach(container => {
          updatePercentageSum(container);
        });
      });

      function setupEventHandlers() {
        // Add URL button handlers
        document.querySelectorAll('.add-url').forEach(button => {
          button.addEventListener('click', function() {
            const containerId = this.getAttribute('data-container');
            const keyword = this.getAttribute('data-keyword');
            const container = containerId ?
              document.querySelector('.' + containerId) :
              this.closest('.redirect-list-col').querySelector('.url-chances-container');

            if (container) {
              addNewUrlRow(container);
            }
          });
        });

        // Remove URL button handlers
        document.querySelectorAll('.remove-url').forEach(button => {
          if (!button.closest('.template')) {
            setupRemoveButtonHandler(button);
          }
        });

        // Percentage change handlers
        document.querySelectorAll('.chance-input').forEach(input => {
          if (!input.closest('.template')) {
            setupPercentageInputHandler(input);
          }
        });
      }

      function addNewUrlRow(container) {
        // Clone the template row
        const template = container.querySelector('.template');
        const newRow = template.cloneNode(true);

        // Show and remove template class
        newRow.style.display = 'flex';
        newRow.classList.remove('template');

        // Add event handlers
        setupRemoveButtonHandler(newRow.querySelector('.remove-url'));
        setupPercentageInputHandler(newRow.querySelector('.chance-input'));

        // Add to container before the template
        container.insertBefore(newRow, template);

        // Update total percentage
        updatePercentageSum(container);

        // Focus on the URL input
        newRow.querySelector('.url-input').focus();
      }

      function setupRemoveButtonHandler(button) {
        button.addEventListener('click', function() {
          const row = this.closest('.url-chance-row');
          const container = row.closest('.url-chances-container');

          // Don't remove if it's the last visible row
          const visibleRows = Array.from(container.querySelectorAll('.url-chance-row'))
            .filter(r => !r.classList.contains('template') && r.style.display !== 'none');

          if (visibleRows.length > 1) {
            row.remove();
            updatePercentageSum(container);
          } else {
            alert('You must have at least one URL in the list.');
          }
        });
      }

      function setupPercentageInputHandler(input) {
        input.addEventListener('input', function() {
          const container = this.closest('.url-chances-container');
          updatePercentageSum(container);
        });
      }

      function updatePercentageSum(container) {
        const inputs = Array.from(container.querySelectorAll('.chance-input'))
          .filter(input => !input.closest('.template') && input.closest('.url-chance-row').style.display !== 'none');

        let sum = 0;
        inputs.forEach(input => {
          const value = parseFloat(input.value) || 0;
          sum += value;
        });

        // Update the sum display
        const sumElement = container.closest('.redirect-list-col').querySelector('.percentage-sum');
        sumElement.textContent = sum.toFixed(1);

        // Highlight if not 100%
        if (Math.abs(sum - 100) > 0.1 && inputs.some(input => input.value !== '')) {
          sumElement.classList.add('error');
        } else {
          sumElement.classList.remove('error');
        }
      }
    </script>
<?php
  }

  /**
   * Process admin form submission
   */
  public function processFormSubmission(): void
  {
    if (!isset($_POST['action']) || $_POST['action'] !== 'update_random_redirect_settings') {
      return;
    }

    // Verify nonce
    yourls_verify_nonce('random_redirect_settings');

    // Current settings
    $currentSettings = yourls_get_option(self::OPTION_NAME) ?: [];

    // Check for delete action
    if (isset($_POST['delete_list']) && !empty($_POST['delete_list'])) {
      $keyword = $_POST['delete_list'];

      if (isset($currentSettings[$keyword])) {
        unset($currentSettings[$keyword]);
        yourls_update_option(self::OPTION_NAME, $currentSettings);
        yourls_add_notice("List for keyword '$keyword' deleted successfully");
        return;
      }
    }

    $settings = [];
    $shortlinksCreated = 0;
    $shortlinksUpdated = 0;

    // Update existing lists
    if (isset($_POST['list_keyword']) && is_array($_POST['list_keyword'])) {
      foreach ($_POST['list_keyword'] as $oldKeyword => $newKeyword) {
        if (empty($newKeyword)) continue;

        $newKeyword = trim($newKeyword);
        $urls = isset($_POST['list_urls'][$oldKeyword])
          ? array_values(array_filter($_POST['list_urls'][$oldKeyword], 'trim'))
          : [];

        $chances = isset($_POST['list_chances'][$oldKeyword])
          ? array_map('floatval', $_POST['list_chances'][$oldKeyword])
          : [];

        // Trim chances array to match URLs array length
        $chances = array_slice($chances, 0, count($urls));

        // Pad chances array if shorter than URLs array
        while (count($chances) < count($urls)) {
          $chances[] = 0;
        }

        if (!empty($urls)) {
          $settings[$newKeyword] = [
            'enabled' => isset($_POST['list_enabled'][$oldKeyword]),
            'urls' => $urls,
            'chances' => $chances
          ];

          // Create or update shortlink
          $this->ensureShortlinkExists($newKeyword, $urls[0], $shortlinksCreated, $shortlinksUpdated);
        }
      }
    }

    // Add new list if provided
    if (!empty($_POST['new_list_keyword']) && !empty($_POST['new_list_urls'])) {
      $keyword = trim($_POST['new_list_keyword']);
      $urls = array_values(array_filter($_POST['new_list_urls'], 'trim'));

      $chances = isset($_POST['new_list_chances'])
        ? array_map('floatval', $_POST['new_list_chances'])
        : [];

      // Trim chances array to match URLs array length
      $chances = array_slice($chances, 0, count($urls));

      // Pad chances array if shorter than URLs array
      while (count($chances) < count($urls)) {
        $chances[] = 0;
      }

      if (!empty($urls)) {
        $settings[$keyword] = [
          'enabled' => true,
          'urls' => $urls,
          'chances' => $chances
        ];

        // Create shortlink for new keyword
        $this->ensureShortlinkExists($keyword, $urls[0], $shortlinksCreated, $shortlinksUpdated);
      }
    }

    // Save settings
    yourls_update_option(self::OPTION_NAME, $settings);

    // Add notices
    yourls_add_notice('Settings updated successfully');
    if ($shortlinksCreated > 0) {
      yourls_add_notice("Created $shortlinksCreated new shortlink(s)");
    }
    if ($shortlinksUpdated > 0) {
      yourls_add_notice("Updated $shortlinksUpdated existing shortlink(s)");
    }
  }

  /**
   * Ensure a shortlink exists for the given keyword
   *
   * @param string $keyword The keyword
   * @param string $url The URL to link to
   * @param int &$created Counter for created shortlinks
   * @param int &$updated Counter for updated shortlinks
   */
  private function ensureShortlinkExists(string $keyword, string $url, int &$created, int &$updated): void
  {
    // Check if shortlink exists
    $exists = yourls_keyword_is_taken($keyword);

    if (!$exists) {
      // Create new shortlink
      $result = yourls_add_new_link($url, $keyword);
      if ($result['status'] === 'success') {
        $created++;
      }
    } else {
      // Get current URL for this shortlink
      $current_url = yourls_get_keyword_longurl($keyword);

      // Update if different
      if ($current_url !== $url) {
        yourls_edit_link($url, $keyword, $keyword);
        $updated++;
      }
    }
  }
}

// Initialize the plugin
new RandomRedirectManager();
?>
