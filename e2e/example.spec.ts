import { test, expect } from '@playwright/test';

test('example e2e test', async ({ page }) => {
    // Just a placeholder test
    await page.goto('https://example.com');
    expect(await page.title()).toBe('Example Domain');
});
