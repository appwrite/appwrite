# Code Change Agent Instructions

## Overview
This document provides instructions for agents that make code changes to the Appwrite repository. These instructions ensure consistency and quality across automated code contributions.

## General Guidelines

### Code Changes
1. Make minimal, focused changes that address the specific issue or requirement
2. Follow the existing code style and conventions in the repository
3. Ensure all changes are properly tested
4. Update documentation when changing public APIs or adding new features

### Testing Requirements
1. Run existing tests to ensure no regressions
2. Add new tests for new functionality
3. Ensure all tests pass before creating a PR

## Screenshot Requirements for UI Changes

**IMPORTANT**: When making changes that affect the user interface or visual output, you MUST capture screenshots to document the changes.

### When to Take Screenshots
Take screenshots in the following scenarios:
- Changes to web UI components, layouts, or styles
- Modifications to console output or terminal displays
- Updates to dashboards, admin panels, or user-facing interfaces
- Changes that affect visual rendering or appearance
- New UI features or components

### How to Take Screenshots Using Playwright

1. **Install Playwright** (if not already available):
   ```bash
   npm install -D @playwright/test
   npx playwright install
   ```

2. **Create a screenshot script** in `/tmp/take-screenshots.js`:
   ```javascript
   const { chromium } = require('playwright');
   
   (async () => {
     const browser = await chromium.launch();
     const page = await browser.newPage();
     
     // Navigate to the changed UI (replace with your actual URL)
     await page.goto('http://localhost:3000/dashboard');
     
     // Wait for the page to fully load
     await page.waitForLoadState('networkidle');
     
     // Take screenshot
     await page.screenshot({ 
       path: 'screenshot-before.png',
       fullPage: true 
     });
     
     await browser.close();
   })();
   ```

3. **Take "before" and "after" screenshots**:
   - Take a screenshot of the UI BEFORE your changes (if possible)
   - Make your code changes
   - Take a screenshot of the UI AFTER your changes
   - Save screenshots with descriptive names like:
     - `ui-change-before.png`
     - `ui-change-after.png`
     - `feature-name-screenshot.png`

4. **Alternative: Use Playwright Test**:
   ```javascript
   const { test, expect } = require('@playwright/test');
   
   test('capture UI changes', async ({ page }) => {
     // Replace with your actual URL
     await page.goto('http://localhost:3000/dashboard');
     await expect(page).toHaveScreenshot('ui-state.png');
   });
   ```

### Including Screenshots in PRs

1. **Save screenshots in a dedicated directory**:
   ```bash
   mkdir -p /tmp/pr-screenshots
   mv *.png /tmp/pr-screenshots/
   ```

2. **Upload screenshots to an image hosting service** (if needed):
   - Use GitHub's issue/PR comment attachment feature
   - Use temporary image hosting services for review purposes
   
3. **Include screenshots in PR description**:
   Add a "Screenshots" section to your PR description:
   ```markdown
   ## Screenshots
   
   ### Before
   ![Before Changes](url-to-before-screenshot)
   
   ### After  
   ![After Changes](url-to-after-screenshot)
   
   ### Description
   Brief description of what changed visually.
   ```

4. **Add screenshots as PR comments**:
   If you can't edit the PR description directly, add screenshots as a comment:
   ```markdown
   📸 **UI Changes Screenshot**
   
   Here are screenshots showing the visual changes made in this PR:
   
   **Before:**
   ![Before](url-to-screenshot)
   
   **After:**
   ![After](url-to-screenshot)
   ```

### Screenshot Best Practices

1. **Capture the relevant area**: Focus on the changed UI elements, but include enough context
2. **Use consistent viewport sizes**: Stick to common resolutions (e.g., 1920x1080, 1366x768)
3. **Show multiple states** (if applicable):
   - Default state
   - Hover state
   - Active/clicked state
   - Error state
   - Loading state
4. **Annotate if helpful**: Add arrows or highlights to draw attention to specific changes
5. **Include mobile views**: For responsive changes, capture both desktop and mobile viewports

### Example Playwright Script for Common Scenarios

#### Capturing Multiple Views:
```javascript
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  
  // Desktop view
  await page.setViewportSize({ width: 1920, height: 1080 });
  await page.goto('http://localhost:3000/dashboard');
  await page.screenshot({ path: '/tmp/pr-screenshots/dashboard-desktop.png', fullPage: true });
  
  // Mobile view
  await page.setViewportSize({ width: 375, height: 667 });
  await page.screenshot({ path: '/tmp/pr-screenshots/dashboard-mobile.png', fullPage: true });
  
  // Specific component
  const component = await page.locator('.my-changed-component');
  await component.screenshot({ path: '/tmp/pr-screenshots/component-detail.png' });
  
  await browser.close();
})();
```

#### Capturing Interactive States:
```javascript
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.goto('http://localhost:3000/form');
  
  // Default state
  await page.screenshot({ path: '/tmp/pr-screenshots/form-default.png' });
  
  // Hover state
  await page.hover('button.submit');
  await page.screenshot({ path: '/tmp/pr-screenshots/form-hover.png' });
  
  // Filled state
  await page.fill('input[name="username"]', 'testuser');
  await page.screenshot({ path: '/tmp/pr-screenshots/form-filled.png' });
  
  await browser.close();
})();
```

## Workflow Summary

1. Identify the issue or feature to implement
2. Make code changes following best practices
3. **If UI is affected**:
   - Set up local environment to run the application
   - Use Playwright to capture "before" and "after" screenshots
   - Save screenshots to `/tmp/pr-screenshots/`
4. Run tests and ensure everything passes
5. Create PR with detailed description
6. **Include screenshots** in PR description or as a comment
7. Address review feedback

## Additional Resources

- [Playwright Documentation](https://playwright.dev/)
- [Playwright Screenshots Guide](https://playwright.dev/docs/screenshots)
- [Contributing Guidelines](../../CONTRIBUTING.md)
- [Pull Request Template](../PULL_REQUEST_TEMPLATE.md)
