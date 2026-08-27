# Appwrite 2.0 Launch Video

Remotion teaser for **Appwrite 2.0**, styled to match the marketing homepage and cover generator templates.

## Preview

```bash
cd remotion
pnpm i
pnpm run dev
```

## Render

```bash
pnpm run render          # 1920×1080
pnpm run render:vertical # 1080×1920
```

Output: `remotion/out/appwrite-2-launch.mp4`

## Audio

Background music: **Corporate Background Music** by prettyjohn1 ([Pixabay](https://pixabay.com/)), as `public/audio/launch-music.wav` (~33s, loops). Typing: Dragon Studio keyboard sample. Swoosh: Alex Zavesa. Adjust levels in `src/constants.ts`.

## Structure (~70 seconds)

| Scene | Content |
| ----- | ------- |
| Intro | The new Appwrite Console |
| Console screenshot | Browser rises with console screen recording + title |
| Thesis | Docs, API, and dashboard. One flow. |
| Flow panels | Docs / API / Dashboard placeholders (2 from top, 1 from bottom) |
| Console reveal | Rebuilt to reduce friction |
| Feature × 7 | Title typewriter + browser clip (audit logs, usage, realtime, API explorer, keyboard, speed, unified flow) |
| Slogan | The open-source cloud for agents and developers |
| Finale | Logo fade |

## Feature clips

Each entry in `FEATURE_CLIPS` (`src/constants.ts`) accepts its own `videoSrc` and `videoStartFrame`. Until dedicated recordings exist, all beats use segments of `public/videos/area.mp4`.

## Flow panel placeholders

After the thesis scene, `FLOW_PANEL_LAYOUTS` in `src/lib/flow-panels-layout.ts` defines three square frames in one row. Screenshots live in `public/images/flow-panels/` (`docs.png`, `dashboard.png`, `api.png`).

## Brand alignment

Visual language mirrors:

- **Cover generator** `simple-title`, `milestone-centered`, and `title-icon` templates
- **Homepage** dotted grid + pink/purple hero soft lights (`HomeSoftLights`)
- **Typography**: Inter eyebrows (0.25em tracking + `_`), Aeonik Pro titles, `.text-gradient-brand` for hero stats
- **Product bento** icon shell on the finale lockup
