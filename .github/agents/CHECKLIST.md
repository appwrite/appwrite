# Agent Checklist for Code Changes with UI Impact

Quick reference checklist for agents making code changes that affect the UI.

## Pre-Development
- [ ] Read and understand the issue/requirement
- [ ] Review existing code and patterns
- [ ] Identify if changes will affect UI/visual output

## Development
- [ ] Make minimal, focused code changes
- [ ] Follow existing code style and conventions
- [ ] Update relevant documentation
- [ ] Run linters and fix any issues

## Testing
- [ ] Run existing tests to ensure no regressions
- [ ] Add new tests for new functionality
- [ ] Verify all tests pass
- [ ] Manually test the changes (if applicable)

## Screenshots (If UI Changes)
- [ ] Install Playwright: `npm install -D @playwright/test && npx playwright install chromium`
- [ ] Create screenshot directory: `mkdir -p /tmp/pr-screenshots`
- [ ] Start local development server
- [ ] Capture "before" screenshots (if possible)
- [ ] Capture "after" screenshots showing changes
- [ ] Capture multiple viewports if responsive
- [ ] Capture different states (hover, active, error, etc.)
- [ ] Review screenshots for quality and clarity

## PR Submission
- [ ] Create PR with clear, descriptive title
- [ ] Fill out PR description explaining what changed
- [ ] Include test plan with verification steps
- [ ] Add screenshots to "Screenshots" section (if UI changes)
- [ ] Mark screenshot checkbox as complete
- [ ] Link to related issues
- [ ] Verify all PR template sections are completed

## Post-Submission
- [ ] Respond to review comments
- [ ] Update screenshots if significant changes made
- [ ] Re-run tests after addressing feedback

## Quick Screenshot Command

```bash
# 1. Install (first time only)
npm install -D @playwright/test
npx playwright install chromium

# 2. Create directory
mkdir -p /tmp/pr-screenshots

# 3. Use this template (save as /tmp/capture.js)
cat > /tmp/capture.js << 'EOF'
const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.setViewportSize({ width: 1920, height: 1080 });
  await page.goto('http://localhost:3000/dashboard'); // Replace with your actual URL
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: '/tmp/pr-screenshots/screenshot.png', fullPage: true });
  await browser.close();
})();
EOF

# 4. Run it
node /tmp/capture.js
```

## Reference

- Full details: [code-change-agent.md](./code-change-agent.md)
- Quick guide: [screenshot-guide.md](./screenshot-guide.md)
- PR template: [PULL_REQUEST_TEMPLATE.md](../PULL_REQUEST_TEMPLATE.md)
