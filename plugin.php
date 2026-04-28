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
 * Requires at least: YOURLS 1.7.3
 * Tested up to: YOURLS 1.10.2
 * Requires PHP: 7.4
 * Tested up to PHP: 8.5
 */

// Prevent direct access to this file
if (!defined("YOURLS_ABSPATH")) {
    die();
}

// Enable strict types for better code quality (optional, requires PHP 7+)
// declare(strict_types=1);

class RandomRedirectManager
{
    private const OPTION_NAME = "random_redirect_settings";
    private array $settings = []; // Cache settings locally

    public function __construct()
    {
        // Load settings once
        $this->loadSettings();

        // Admin page hooks
        yourls_add_action("plugins_loaded", [$this, "addAdminPage"]);

        // Process form submissions on the plugin page itself. The
        // `load-<plugin_page>` action fires from yourls_plugin_admin_page()
        // *after* yourls_maybe_require_auth() has defined YOURLS_USER, so
        // yourls_verify_nonce() sees the same user as yourls_create_nonce()
        // did when the form was rendered. The earlier `admin_init` hook
        // ran from Init::__construct() *before* auth, leaving YOURLS_USER
        // undefined — that mismatch is what produced the
        // "Unauthorized action or expired link" failure on save.
        yourls_add_action("load-random_redirect_settings", [
            $this,
            "processFormSubmission",
        ]);

        // Check requests for redirects
        yourls_add_action("shutdown", [$this, "checkRequest"]);
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

        // Get the requested keyword (path part of the URL).
        // PHP 8.1+ deprecates passing null to string functions, so coalesce
        // any null/false from parse_url() (malformed REQUEST_URI) to "".
        $requestUri = isset($_SERVER["REQUEST_URI"]) ? (string) $_SERVER["REQUEST_URI"] : "";
        $path = parse_url($requestUri, PHP_URL_PATH);
        $request = trim(is_string($path) ? $path : "", "/");
        // Decode URL-encoded characters (e.g., %20 -> space)
        $request = urldecode($request);

        if (
            isset($this->settings[$request]) &&
            !empty($this->settings[$request]["urls"]) &&
            ($this->settings[$request]["enabled"] ?? false) // Default to false if not set
        ) {
            $listData = $this->settings[$request];
            $urls = $listData["urls"];
            $chances = $listData["chances"] ?? [];

            // Select URL based on chances
            $randomUrl = !empty($chances)
                ? $this->getWeightedRandomUrl($urls, $chances)
                : $urls[array_rand($urls)]; // Fallback to equal distribution

            // Perform the redirect
            // Use 307 Temporary Redirect to better indicate the resource itself hasn't moved
            yourls_redirect($randomUrl, 307);
            exit();
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
            "random_redirect_settings", // Page slug
            "Random Redirect Manager", // Page title
            [$this, "displayAdminPage"] // Display function
        );
    }

    /**
     * Display the admin settings page HTML.
     */
    public function displayAdminPage(): void
    {
        if (!yourls_is_admin()) {
            yourls_die("Access denied", "Permission Denied", 403);
        }

        $nonce = yourls_create_nonce("random_redirect_settings_nonce");
        // Settings are already loaded in $this->settings

        // Output CSS and JS first
        $this->displayAdminAssets();

        // Output HTML using HEREDOC for better readability. The whole page
        // is wrapped in `.rrm-page` so the plugin styles don't bleed into
        // the surrounding YOURLS / Sleeky admin chrome.
        echo <<<HTML
    <div class="rrm-page">
    <h2>Random Redirect Manager</h2>

    <div class="rrm-info-box">
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
            echo "<p>No redirect lists configured yet.</p>";
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

    <dialog id="rrm-picker" class="rrm-picker">
      <h3>Pick a YOURLS shortlink</h3>
      <input type="text" id="rrm-picker-q" placeholder="Search keyword, URL or title…" autocomplete="off">
      <ul id="rrm-picker-list"></ul>
      <div class="rrm-picker-actions">
        <button type="button" id="rrm-picker-cancel" class="button button-secondary">Cancel</button>
      </div>
    </dialog>
    </div> <!-- .rrm-page -->
HTML;

        // Bootstrap payload for the shortlink picker. The JS inside
        // displayAdminAssets() reads this on load. JSON_HEX_TAG escapes
        // `<` and `>` to `<` / `>` so a user-controlled shortlink
        // URL or title cannot smuggle a `</script>` sequence and break out
        // of the inline JSON block. JSON.parse handles the escapes natively.
        $shortlinks = $this->getAllShortlinks();
        $payload = json_encode(
            ["shortlinks" => $shortlinks],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        );
        echo "<script id=\"rrm-bootstrap\" type=\"application/json\">"
            . ($payload !== false ? $payload : '{"shortlinks":[]}')
            . "</script>\n";
    }

    /**
     * Display the form section for an existing redirect list.
     *
     * @param string $keyword The keyword for the list.
     * @param array $listData The data for the list (enabled, urls, chances).
     */
    private function displayExistingListForm(
        string $keyword,
        array $listData
    ): void {
        $escapedKeyword = htmlspecialchars($keyword);
        $isEnabled = $listData["enabled"] ?? true; // Default to enabled
        $checked = $isEnabled ? "checked" : "";
        $urls = $listData["urls"] ?? [];
        $chances = $listData["chances"] ?? [];

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
                $chance = isset($chances[$index])
                    ? htmlspecialchars((string) $chances[$index])
                    : "";
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
            "",
            "",
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
        $this->displayUrlChanceRow(
            "new_list_urls[]",
            "new_list_chances[]",
            "",
            "",
            true
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
        string $urlValue = "",
        string $chanceValue = "",
        bool $isTemplate = false
    ): void {
        $style = $isTemplate ? 'style="display: none;"' : "";
        $class = $isTemplate ? "url-chance-row template" : "url-chance-row";

        // Only make URL required for existing lists, not for new lists
        $urlRequired = !$isTemplate && empty($urlValue) && strpos($urlName, 'new_list_urls') === false ? "required" : "";

        echo <<<HTML
        <div class="{$class}" {$style}>
          <input type="url" name="{$urlName}" value="{$urlValue}" class="text url-input" placeholder="https://example.com" {$urlRequired}>
          <button type="button" class="pick-shortlink button button-secondary" aria-label="Pick a YOURLS shortlink">Pick…</button>
          <input type="number" name="{$chanceName}" value="{$chanceValue}" class="text chance-input" min="0" max="100" step="any" placeholder="%">
          <span class="percent-sign">%</span>
          <button type="button" class="remove-url button" aria-label="Remove URL">✕</button>
        </div>
    HTML;
    }

    /**
     * Fetch all existing YOURLS shortlinks for the picker UI.
     *
     * Mirrors the approach used by the Link Front Page plugin: a single
     * SELECT against the URL table, capped to keep the bootstrap payload
     * sane on busy installs. Each row is enriched with the resolved short
     * URL so the JS picker can drop it straight into the input field.
     *
     * @return array<int, array{keyword: string, url: string, title: string, shorturl: string}>
     */
    private function getAllShortlinks(): array
    {
        global $ydb;
        $table = defined("YOURLS_DB_TABLE_URL") ? YOURLS_DB_TABLE_URL : "yourls_url";

        try {
            $rows = $ydb->fetchObjects(
                "SELECT keyword, url, title FROM `{$table}` ORDER BY timestamp DESC LIMIT 5000"
            );
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $links = [];
        foreach ($rows as $row) {
            $keyword = (string) ($row->keyword ?? "");
            if ($keyword === "") {
                continue;
            }
            $links[] = [
                "keyword" => $keyword,
                "url" => (string) ($row->url ?? ""),
                "title" => (string) ($row->title ?? ""),
                "shorturl" => yourls_link($keyword),
            ];
        }
        return $links;
    }

    /**
     * Output CSS and JavaScript for the admin page.
     */
    private function displayAdminAssets(): void
    {
        // CSS - Using HEREDOC for multiline string. Every selector is scoped
        // to .rrm-page so the plugin styles don't bleed into the surrounding
        // admin chrome. Colors use rgba()/inherit so the form looks right on
        // both vanilla YOURLS and the Sleeky admin theme (light + dark).
        $css = <<<'CSS'
      .rrm-page .rrm-info-box { margin: 15px 0; padding: 10px 15px; border-radius: 5px; background-color: #e7f3ff; border-left: 4px solid #0080ff; color: #0d2a3e; }
      .rrm-page .rrm-info-box p { margin: 4px 0; }
      .rrm-page .rrm-info-box strong { color: #062840; }
      .rrm-page .settings-group, .rrm-page .redirect-list-settings { margin: 20px 0; padding: 0; border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 5px; background: transparent; }
      .rrm-page .settings-group { padding: 15px; }
      .rrm-page .redirect-lists-container { margin: 20px 0; display: flex; flex-direction: column; gap: 15px; }
      .rrm-page .redirect-list-header { display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; border-bottom: 1px solid rgba(128, 128, 128, 0.2); background-color: rgba(128, 128, 128, 0.05); }
      .rrm-page .redirect-list-header h4 { margin: 0; font-size: 1em; }
      .rrm-page .keyword-display { font-family: monospace; }
      .rrm-page .list-actions { display: flex; align-items: center; gap: 10px; }
      .rrm-page .redirect-list-content { padding: 15px; }
      .rrm-page .redirect-list-row { display: flex; gap: 15px; margin-bottom: 15px; }
      .rrm-page .redirect-list-row:last-child { margin-bottom: 0; }
      .rrm-page .redirect-list-col { flex: 1; }
      .rrm-page .redirect-list-col.full { flex: 0 0 100%; }
      .rrm-page .redirect-list-col label { display: block; margin-bottom: 5px; font-weight: bold; }
      .rrm-page input.text, .rrm-page textarea.text { width: 100%; padding: 8px; border: 1px solid rgba(128, 128, 128, 0.3); border-radius: 3px; background: transparent; color: inherit; box-sizing: border-box; }
      .rrm-page input.text:focus, .rrm-page textarea.text:focus { outline: 2px solid rgba(0, 128, 255, 0.4); outline-offset: 1px; }
      .rrm-page input:required:invalid { border-color: #f44336; }
      .rrm-page .redirect-list-toggle { display: flex; align-items: center; gap: 5px; font-weight: normal; }
      .rrm-page .button.delete-list-button { background-color: #dc3545; color: #fff; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; }
      .rrm-page .button.delete-list-button:hover { background-color: #c82333; }
      .rrm-page .url-chances-container { display: flex; flex-direction: column; gap: 8px; margin-bottom: 10px; }
      .rrm-page .url-chance-row { display: flex; align-items: center; gap: 10px; width: 100%; }
      .rrm-page .url-input { flex: 3; min-width: 200px; }
      .rrm-page .chance-input { flex: 0 0 80px; width: 80px; text-align: right; }
      .rrm-page .percent-sign { flex: 0 0 10px; margin-right: 5px; opacity: 0.7; }
      .rrm-page .remove-url { flex: 0 0 25px; background-color: #f44336; color: #fff; border: none; width: 25px; height: 25px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 12px; padding: 0; line-height: 1; }
      .rrm-page .remove-url:hover { background-color: #e53935; }
      .rrm-page .url-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; }
      .rrm-page .total-percentage { font-weight: bold; opacity: 0.8; }
      .rrm-page .percentage-sum { color: #008000; }
      .rrm-page .percentage-sum.error { color: #f44336; font-weight: bold; }
      /* Secondary buttons (Add URL, Pick…, Cancel). Translucent grays so the
         button blends with both Sleeky's light and dark themes — text uses
         `inherit` to pick up the surrounding admin chrome's foreground color. */
      .rrm-page .button.button-secondary, .rrm-page .add-url, .rrm-page .pick-shortlink {
        background-color: rgba(128, 128, 128, 0.12);
        color: inherit;
        border: 1px solid rgba(128, 128, 128, 0.35);
        padding: 6px 14px;
        border-radius: 3px;
        cursor: pointer;
        font-size: 0.9em;
        line-height: 1.4;
        transition: background-color 0.15s, border-color 0.15s;
      }
      .rrm-page .button.button-secondary:hover, .rrm-page .add-url:hover, .rrm-page .pick-shortlink:hover {
        background-color: rgba(128, 128, 128, 0.22);
        border-color: rgba(128, 128, 128, 0.55);
      }
      .rrm-page .pick-shortlink { flex: 0 0 auto; padding: 6px 10px; font-size: 0.85em; }
      /* Shortlink picker dialog */
      .rrm-page .rrm-picker { width: min(600px, 92vw); max-height: 80vh; padding: 18px; border: 1px solid rgba(128, 128, 128, 0.4); border-radius: 6px; background: #fff; color: #1a1a1a; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.25); }
      .rrm-page .rrm-picker::backdrop { background: rgba(0, 0, 0, 0.45); }
      .rrm-page .rrm-picker h3 { margin: 0 0 10px; font-size: 1.05em; }
      .rrm-page .rrm-picker input[type="text"] { width: 100%; padding: 8px; border: 1px solid rgba(128, 128, 128, 0.4); border-radius: 3px; box-sizing: border-box; margin-bottom: 10px; background: #fff; color: #1a1a1a; }
      .rrm-page .rrm-picker ul { list-style: none; margin: 0; padding: 0; max-height: 50vh; overflow-y: auto; border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 3px; }
      .rrm-page .rrm-picker li { padding: 8px 10px; border-bottom: 1px solid rgba(128, 128, 128, 0.15); cursor: pointer; }
      .rrm-page .rrm-picker li:last-child { border-bottom: none; }
      .rrm-page .rrm-picker li:hover, .rrm-page .rrm-picker li.is-active { background: rgba(0, 128, 255, 0.08); }
      .rrm-page .rrm-picker li strong { font-family: monospace; color: #0080ff; }
      .rrm-page .rrm-picker .rrm-picker-url { display: block; font-size: 0.85em; color: #555; word-break: break-all; }
      .rrm-page .rrm-picker .rrm-picker-title { display: block; font-size: 0.85em; color: #888; font-style: italic; }
      .rrm-page .rrm-picker-actions { display: flex; justify-content: flex-end; margin-top: 10px; }
      @media (max-width: 768px) {
        .rrm-page .redirect-list-row { flex-direction: column; gap: 10px; }
        .rrm-page .url-chance-row { flex-wrap: wrap; }
        .rrm-page .url-input { min-width: 200px; }
        .rrm-page .list-actions { flex-direction: column; align-items: flex-start; gap: 5px; }
      }
CSS;

        // JavaScript - Using HEREDOC, note the $ escaping
        $js = <<<'JS'
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
            !isset($_POST["action"]) ||
            $_POST["action"] !== "update_random_redirect_settings" ||
            !yourls_is_admin()
        ) {
            return;
        }

        // Verify nonce. The 4th argument is the message YOURLS will die()
        // with on failure (a truthy $return triggers `die($return)` inside
        // yourls_verify_nonce in YOURLS 1.10), so passing our own message
        // here removes the need for a separate yourls_die() branch — the
        // previous shape was unreachable. The 3rd argument stays at the
        // sentinel `false` so YOURLS picks the logged-in user automatically.
        yourls_verify_nonce(
            "random_redirect_settings_nonce",
            (string) ($_POST["nonce"] ?? ""),
            false,
            "Invalid security token"
        );

        $currentSettings = $this->settings; // Use cached settings
        $newSettings = [];
        $messages = [
            "updated" => 0,
            "created" => 0,
            "deleted" => 0,
            "errors" => [],
        ];

        // --- Handle Delete Action ---
        if (isset($_POST["delete_list"]) && !empty($_POST["delete_list"])) {
            $keywordToDelete = $this->sanitizeKeyword($_POST["delete_list"]);
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
                        "error"
                    );
                }
                // Stop further processing after delete
                return;
            } else {
                yourls_add_notice(
                    "Could not delete list: Keyword '{$keywordToDelete}' not found.",
                    "error"
                );
                return; // Stop if delete was intended but failed
            }
        }

        // --- Process Existing Lists ---
        if (isset($_POST["list_keyword"]) && is_array($_POST["list_keyword"])) {
            foreach (
                $_POST["list_keyword"]
                as $oldKeyword => $newKeywordInput
            ) {
                $oldKeyword = $this->sanitizeKeyword($oldKeyword); // Sanitize the key from POST
                $newKeyword = $this->sanitizeKeyword($newKeywordInput);

                if (!$oldKeyword || !$newKeyword) {
                    $messages[
                        "errors"
                    ][] = "Invalid or empty keyword provided for an existing list (original: '{$oldKeyword}'). Skipped.";
                    continue;
                }

                // Check if keyword changed and the new one is already taken by another list *in this submission*
                if (
                    $oldKeyword !== $newKeyword &&
                    isset($newSettings[$newKeyword])
                ) {
                    $messages[
                        "errors"
                    ][] = "Keyword '{$newKeyword}' is used multiple times in this submission. Reverted change for '{$oldKeyword}'.";
                    $newKeyword = $oldKeyword; // Keep the old keyword to avoid conflict
                }

                $urls = isset($_POST["list_urls"][$oldKeyword])
                    ? $this->sanitizeUrlArray($_POST["list_urls"][$oldKeyword])
                    : [];
                $chances = isset($_POST["list_chances"][$oldKeyword])
                    ? $this->sanitizeChanceArray(
                        $_POST["list_chances"][$oldKeyword]
                    )
                    : [];

                if (empty($urls)) {
                    $messages[
                        "errors"
                    ][] = "No valid URLs provided for keyword '{$newKeyword}'. List not saved.";
                    continue; // Skip if no URLs
                }

                // Ensure chances array matches urls array size
                $chances = array_slice($chances, 0, count($urls));
                $chances = array_pad($chances, count($urls), 0.0);

                $newSettings[$newKeyword] = [
                    "enabled" => isset($_POST["list_enabled"][$oldKeyword]),
                    "urls" => $urls,
                    "chances" => $chances,
                ];

                // Ensure shortlink exists/is updated for the *new* keyword
                $this->ensureShortlinkExists(
                    $newKeyword,
                    $urls[0], // Use first URL
                    $messages["created"],
                    $messages["updated"],
                    $messages["errors"]
                );

                // If keyword changed, we might need to handle the old shortlink?
                // For now, we leave the old shortlink as is. User can delete it manually.
            }
        }

        // --- Process New List ---
        if (
            !empty($_POST["new_list_keyword"]) // Only check if keyword is provided
        ) {
            $newKeyword = $this->sanitizeKeyword($_POST["new_list_keyword"]);
            $urls = $this->sanitizeUrlArray($_POST["new_list_urls"] ?? []);
            $chances = isset($_POST["new_list_chances"])
                ? $this->sanitizeChanceArray($_POST["new_list_chances"])
                : [];
        
            if (!$newKeyword) {
                $messages["errors"][] =
                    "Invalid or empty keyword provided for the new list. Skipped.";
            } elseif (isset($newSettings[$newKeyword])) {
                $messages[
                    "errors"
                ][] = "The new keyword '{$newKeyword}' conflicts with another list in this submission. New list skipped.";
            } elseif (empty($urls)) {
                $messages[
                    "errors"
                ][] = "No valid URLs provided for the new keyword '{$newKeyword}'. New list not saved.";
            } else {
                // Ensure chances array matches urls array size
                $chances = array_slice($chances, 0, count($urls));
                $chances = array_pad($chances, count($urls), 0.0);
        
                $newSettings[$newKeyword] = [
                    "enabled" => true, // New lists are enabled by default
                    "urls" => $urls,
                    "chances" => $chances,
                ];
        
                // Create shortlink for the new keyword
                $this->ensureShortlinkExists(
                    $newKeyword,
                    $urls[0],
                    $messages["created"],
                    $messages["updated"],
                    $messages["errors"]
                );
            }
        }

        // --- Save Settings ---
        // Only update if there were no critical errors preventing saving (like keyword conflicts handled above)
        if (yourls_update_option(self::OPTION_NAME, $newSettings)) {
            yourls_add_notice("Random Redirect settings saved successfully.");
            $this->loadSettings(); // Reload settings after successful save
        } else {
            yourls_add_notice("Error saving settings.", "error");
        }

        // --- Display Summary Notices ---
        if ($messages["created"] > 0) {
            yourls_add_notice(
                "Created {$messages["created"]} new shortlink(s)."
            );
        }
        if ($messages["updated"] > 0) {
            yourls_add_notice(
                "Updated {$messages["updated"]} existing shortlink(s)."
            );
        }
        foreach ($messages["errors"] as $error) {
            yourls_add_notice($error, "error");
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
            return "";
        }
        $keyword = trim($keyword);
        // Allow letters, numbers, hyphen, underscore, forward slash.
        // Remove leading/trailing slashes and collapse multiple slashes.
        $keyword = trim($keyword, "/");
        // preg_replace can return null on regex error — coalesce so PHP 8.1+
        // doesn't emit a deprecation when the result reaches preg_match.
        $collapsed = preg_replace("#/+#", "/", $keyword);
        $keyword = is_string($collapsed) ? $collapsed : $keyword;
        if (preg_match('/^[a-zA-Z0-9\-_\/]+$/', $keyword)) {
            return $keyword;
        }
        return ""; // Invalid characters
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
                if (
                    !empty($trimmedUrl) &&
                    filter_var($trimmedUrl, FILTER_VALIDATE_URL)
                ) {
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
            $errors[] = "Cannot ensure shortlink: Invalid keyword or URL provided. Keyword: '{$keyword}'";
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
            if ($result && $result["status"] === "success") {
                $created++;
            } elseif ($result && isset($result["message"])) {
                // Check if the failure was due to the keyword already existing (race condition?)
                if (strpos($result["message"], "already exists") === false) {
                    $errors[] = "Failed to create shortlink for '{$keyword}': {$result["message"]}";
                } else {
                    // Keyword exists now, maybe created by another process or race condition. Try updating.
                    $existingUrl = yourls_get_keyword_longurl($keyword); // Re-fetch
                    if ($existingUrl !== false && $existingUrl !== $url) {
                        if (yourls_edit_link_url($keyword, $url)) {
                            // Use specific function if available (YOURLS 1.7.3+)
                            $updated++;
                        } else {
                            // Fallback for older YOURLS or if specific function fails
                            if (yourls_edit_link($url, $keyword, $keyword)) {
                                // This updates URL and optionally title
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
            if (
                function_exists("yourls_edit_link_url") &&
                yourls_edit_link_url($keyword, $url)
            ) {
                // Use specific function if available (YOURLS 1.7.3+)
                $updated++;
            } else {
                // Fallback for older YOURLS or if specific function fails
                if (yourls_edit_link($url, $keyword, $keyword)) {
                    // This updates URL and optionally title
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
