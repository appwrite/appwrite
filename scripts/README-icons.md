# Apple Touch Icon Generation

This script generates the `apple-touch-icon.png` file required for iOS devices (iPhone/iPad) to display the app icon correctly.

## Quick Start

1. Install the required dependency:

   ```bash
   bun add -d sharp
   # or
   npm install --save-dev sharp
   ```

2. Run the generation script:
   ```bash
   bun run generate:icons
   # or
   npm run generate:icons
   ```

This will create `public/apple-touch-icon.png` (180x180px) from `public/logo.svg`.

## Manual Alternative

If you prefer not to install sharp, you can:

1. Open `public/logo.svg` in a graphics editor (e.g., Figma, Sketch, Photoshop)
2. Export it as PNG at 180x180px
3. Save it as `public/apple-touch-icon.png`

## What This Fixes

iOS devices (iPhone and iPad) require PNG files for app icons when users add your site to their home screen. The SVG favicon works in browsers but not for home screen icons on iOS.

The meta tag in `src/routes/__root.tsx` points to `/apple-touch-icon.png`, which iOS will automatically use when users add the site to their home screen.
