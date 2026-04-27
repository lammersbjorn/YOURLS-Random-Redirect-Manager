import { test as base, expect, Page, Response } from '@playwright/test';

export type ErrorLog = {
  consoleErrors: string[];
  pageErrors: string[];
  serverErrors: string[];
};

// Patterns that almost always indicate a real, plugin-induced PHP failure.
// Deprecation / strict-warning noise from YOURLS or PHP itself is intentionally
// excluded so upstream churn doesn't break this suite.
const PHP_ERROR_PATTERNS: RegExp[] = [
  /<b>\s*Fatal error\s*<\/b>/i,
  /<b>\s*Parse error\s*<\/b>/i,
  /Uncaught\s+(?:Error|TypeError|ValueError|ArgumentCountError|ArgumentError)/,
  /Stack trace:/i,
];

// Narrower patterns: only flag a Warning / Notice when it points back at this
// plugin's directory, since those are something the plugin author can fix.
const PLUGIN_ONLY_ERROR_PATTERNS: RegExp[] = [
  /<b>\s*Warning\s*<\/b>:[^<]*Random-Redirect-Manager/i,
  /<b>\s*Notice\s*<\/b>:[^<]*Random-Redirect-Manager/i,
];

const CONSOLE_IGNORE: RegExp[] = [
  /favicon\.ico/i,
  /Failed to load resource: the server responded with a status of 404/i,
];

async function snapshotPhpErrors(page: Page, sink: string[]) {
  try {
    const html = await page.content();
    const all = [...PHP_ERROR_PATTERNS, ...PLUGIN_ONLY_ERROR_PATTERNS];
    for (const pattern of all) {
      const m = html.match(pattern);
      if (m) {
        const idx = html.indexOf(m[0]);
        const excerpt = html
          .substring(Math.max(0, idx - 80), Math.min(html.length, idx + 240))
          .replace(/\s+/g, ' ')
          .trim();
        sink.push(`PHP error matched ${pattern} on ${page.url()}: …${excerpt}…`);
      }
    }
  } catch {
    /* page may have navigated away */
  }
}

export const test = base.extend<{ errors: ErrorLog }>({
  errors: async ({ page }, use) => {
    const errors: ErrorLog = {
      consoleErrors: [],
      pageErrors: [],
      serverErrors: [],
    };

    page.on('console', (msg) => {
      if (msg.type() !== 'error') return;
      const text = msg.text();
      if (CONSOLE_IGNORE.some((re) => re.test(text))) return;
      errors.consoleErrors.push(text);
    });

    page.on('pageerror', (err) => {
      errors.pageErrors.push(`${err.name}: ${err.message}`);
    });

    page.on('response', (response: Response) => {
      const status = response.status();
      const url = response.url();
      if (status >= 500 && !/\.(?:png|jpg|jpeg|gif|svg|ico)$/i.test(url)) {
        errors.serverErrors.push(`HTTP ${status} ${url}`);
      }
    });

    page.on('framenavigated', async (frame) => {
      if (frame !== page.mainFrame()) return;
      await snapshotPhpErrors(page, errors.serverErrors);
    });

    await use(errors);

    await snapshotPhpErrors(page, errors.serverErrors);

    expect.soft(errors.serverErrors, 'YOURLS / PHP errors').toEqual([]);
    expect.soft(errors.pageErrors, 'Uncaught JavaScript errors').toEqual([]);
    expect.soft(errors.consoleErrors, 'Browser console errors').toEqual([]);
  },
});

export { expect };
