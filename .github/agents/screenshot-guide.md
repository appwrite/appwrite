# Screenshot Capture Quick Guide

## TL;DR
When making UI changes, capture before/after screenshots using Playwright and include them in your PR.

## Quick Start

### 1. Install Playwright
```bash
npm install -D @playwright/test
npx playwright install chromium
```

### 2. Basic Screenshot Script
Save as `/tmp/capture-ui.js`:
```javascript
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  
  // Set viewport size
  await page.setViewportSize({ width: 1920, height: 1080 });
  
  // Navigate to your changed page (Appwrite console runs on http://localhost by default)
  await page.goto('http://localhost/console');
  await page.waitForLoadState('networkidle');
  
  // Take screenshot
  await page.screenshot({ 
    path: '/tmp/pr-screenshots/my-change.png',
    fullPage: true 
  });
  
  await browser.close();
})();
```

### 3. Run It
```bash
mkdir -p /tmp/pr-screenshots
node /tmp/capture-ui.js
```

## When to Screenshot

✅ **DO capture screenshots for:**
- New UI components or pages
- Layout changes
- Style/theme modifications
- Visual bug fixes
- Responsive design changes
- Dashboard or console updates

❌ **DON'T need screenshots for:**
- Backend API changes
- Database schema updates
- Pure logic/algorithm changes
- Non-visual bug fixes
- Configuration changes

## Screenshot Checklist

- [ ] Capture "before" state (if possible)
- [ ] Make your code changes
- [ ] Capture "after" state
- [ ] Use descriptive filenames
- [ ] Include screenshots in PR description or comment
- [ ] Capture both desktop and mobile views (if responsive)

## Common Scenarios

### Compare Before/After
```javascript
// Before changes
await page.screenshot({ path: '/tmp/pr-screenshots/before.png' });

// After changes (rebuild/reload your app)
await page.screenshot({ path: '/tmp/pr-screenshots/after.png' });
```

### Multiple Viewports
```javascript
const viewports = [
  { name: 'desktop', width: 1920, height: 1080 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'mobile', width: 375, height: 667 }
];

for (const viewport of viewports) {
  await page.setViewportSize(viewport);
  await page.screenshot({ 
    path: `/tmp/pr-screenshots/${viewport.name}.png` 
  });
}
```

### Specific Component
```javascript
// Screenshot just the changed component
const element = await page.locator('.my-component');
await element.screenshot({ 
  path: '/tmp/pr-screenshots/component.png' 
});
```

### Interactive States
```javascript
// Default
await page.screenshot({ path: '/tmp/pr-screenshots/default.png' });

// Hover
await page.hover('button.submit');
await page.screenshot({ path: '/tmp/pr-screenshots/hover.png' });

// Active/Focus
await page.click('input[name="search"]');
await page.screenshot({ path: '/tmp/pr-screenshots/focus.png' });

// Error state
await page.click('button.submit');
await page.waitForSelector('.error-message');
await page.screenshot({ path: '/tmp/pr-screenshots/error.png' });
```

## Including in PR

### Option 1: Upload to PR Description
```markdown
## Screenshots

### Before
![Before](url-to-before-image)

### After
![After](url-to-after-image)
```

### Option 2: Add as Comment
Drag and drop images into a PR comment or paste from clipboard.

### Option 3: Use GitHub CLI
```bash
# Upload screenshots by dragging files into the PR comment box in the web UI
# Or use the GitHub API to attach images
```

## Pro Tips

1. **Consistent sizing**: Use standard viewport sizes (1920x1080, 1366x768)
2. **Full page**: Use `fullPage: true` to capture scrollable content
3. **Wait for content**: Use `waitForLoadState('networkidle')` to ensure everything loads
4. **Dark/Light mode**: Capture both themes if your change affects them
5. **Annotations**: Use image editing tools to highlight specific changes

## Troubleshooting

### "Browser not installed"
```bash
npx playwright install chromium
```

### "Cannot connect to localhost"
Make sure your dev server is running:
```bash
npm run dev  # or your start command
```

### "Page not loading"
Add explicit waits:
```javascript
await page.waitForSelector('.main-content');
await page.waitForTimeout(1000); // Last resort
```

## Full Example

```javascript
const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  // Ensure screenshot directory exists
  const screenshotDir = '/tmp/pr-screenshots';
  if (!fs.existsSync(screenshotDir)) {
    fs.mkdirSync(screenshotDir, { recursive: true });
  }

  const browser = await chromium.launch();
  const page = await browser.newPage();

  // Test your changes on different pages
  const pages = [
    { url: 'http://localhost/console', name: 'console' },
    { url: 'http://localhost/console/account', name: 'account' }
  ];

  for (const pageInfo of pages) {
    console.log(`Capturing ${pageInfo.name}...`);
    
    await page.goto(pageInfo.url);
    await page.waitForLoadState('networkidle');
    
    // Desktop
    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.screenshot({ 
      path: `${screenshotDir}/${pageInfo.name}-desktop.png`,
      fullPage: true 
    });
    
    // Mobile
    await page.setViewportSize({ width: 375, height: 667 });
    await page.screenshot({ 
      path: `${screenshotDir}/${pageInfo.name}-mobile.png`,
      fullPage: true 
    });
  }

  await browser.close();
  console.log('Screenshots saved to', screenshotDir);
})();
```

## More Information

For complete details, see [code-change-agent.md](./code-change-agent.md).
