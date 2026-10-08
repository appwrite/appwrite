import {
  INIT_TICKET_IMAGE_HEIGHT,
  INIT_TICKET_IMAGE_WIDTH,
} from '@/lib/init/ticket-layout'
import { resolveInitTicketOgAccentColor } from '@/lib/init/og/init-ticket-og-styles'

/** Console dark theme tokens (`src/styles.css`). */
const INIT_TICKET_OG_UI_DARK = {
  background: '#19191c',
  border: '#3f3f46',
  mutedWash: 'rgba(39, 39, 42, 0.22)',
  heroPink: '#fd366e',
} as const

const INIT_TICKET_OG_UI_LIGHT = {
  background: '#ffffff',
  border: '#e4e4e7',
  mutedWash: 'rgba(244, 244, 245, 0.28)',
  heroPink: '#fd366e',
} as const

const DOT_SPACING = 18

type InitTicketOgUiPalette = {
  background: string
  border: string
  mutedWash: string
  heroPink: string
}

export function getInitTicketOgUiPalette(
  usesDarkChrome: boolean,
): InitTicketOgUiPalette {
  return usesDarkChrome ? INIT_TICKET_OG_UI_DARK : INIT_TICKET_OG_UI_LIGHT
}

function hexToRgb(hex: string): { r: number; g: number; b: number } | null {
  const normalized = hex.replace('#', '').trim()
  if (normalized.length === 3) {
    const [r, g, b] = normalized.split('')
    return {
      r: Number.parseInt(`${r}${r}`, 16),
      g: Number.parseInt(`${g}${g}`, 16),
      b: Number.parseInt(`${b}${b}`, 16),
    }
  }
  if (normalized.length !== 6) return null
  return {
    r: Number.parseInt(normalized.slice(0, 2), 16),
    g: Number.parseInt(normalized.slice(2, 4), 16),
    b: Number.parseInt(normalized.slice(4, 6), 16),
  }
}

function rgba(hex: string, alpha: number): string {
  const rgb = hexToRgb(hex)
  if (!rgb) return `rgba(253, 54, 110, ${alpha})`
  return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alpha})`
}

/** Static hero-style particles (matches InitHeroBackground density on the ticket stage). */
function buildInitTicketOgParticleLayer(
  accent: string,
  usesDarkChrome: boolean,
): string {
  const particles = [
    { cx: 760, cy: 180, r: 48, opacity: usesDarkChrome ? 0.14 : 0.09 },
    { cx: 860, cy: 260, r: 34, opacity: usesDarkChrome ? 0.11 : 0.07 },
    { cx: 680, cy: 320, r: 40, opacity: usesDarkChrome ? 0.09 : 0.06 },
    { cx: 920, cy: 140, r: 24, opacity: usesDarkChrome ? 0.08 : 0.05 },
    { cx: 540, cy: 220, r: 20, opacity: usesDarkChrome ? 0.07 : 0.04 },
    { cx: 820, cy: 360, r: 28, opacity: usesDarkChrome ? 0.08 : 0.05 },
    { cx: 180, cy: 520, r: 32, opacity: usesDarkChrome ? 0.05 : 0.03 },
    { cx: 960, cy: 420, r: 18, opacity: usesDarkChrome ? 0.06 : 0.04 },
  ]

  return particles
    .map(
      (particle) =>
        `<circle cx="${particle.cx}" cy="${particle.cy}" r="${particle.r}" fill="${rgba(accent, particle.opacity)}" />`,
    )
    .join('')
}

export function buildInitTicketOgBackgroundSvg(
  accentColor: string,
  usesDarkChrome: boolean,
  width = INIT_TICKET_IMAGE_WIDTH,
  height = INIT_TICKET_IMAGE_HEIGHT,
): string {
  const palette = getInitTicketOgUiPalette(usesDarkChrome)
  const accent = resolveInitTicketOgAccentColor(accentColor)
  const particles = buildInitTicketOgParticleLayer(accent, usesDarkChrome)

  return `
    <svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <pattern id="init-ticket-dots" width="${DOT_SPACING}" height="${DOT_SPACING}" patternUnits="userSpaceOnUse">
          <circle cx="1" cy="1" r="1" fill="${palette.border}" />
        </pattern>
        <radialGradient id="init-ticket-accent-glow" cx="74%" cy="36%" r="52%">
          <stop offset="0%" stop-color="${accent}" stop-opacity="${usesDarkChrome ? 0.12 : 0.08}" />
          <stop offset="45%" stop-color="${accent}" stop-opacity="${usesDarkChrome ? 0.04 : 0.03}" />
          <stop offset="100%" stop-color="${accent}" stop-opacity="0" />
        </radialGradient>
        <radialGradient id="init-ticket-pink-wash" cx="12%" cy="88%" r="48%">
          <stop offset="0%" stop-color="${palette.heroPink}" stop-opacity="${usesDarkChrome ? 0.06 : 0.04}" />
          <stop offset="55%" stop-color="${palette.heroPink}" stop-opacity="${usesDarkChrome ? 0.02 : 0.015}" />
          <stop offset="100%" stop-color="${palette.heroPink}" stop-opacity="0" />
        </radialGradient>
        <radialGradient id="init-ticket-center-wash" cx="50%" cy="52%" r="72%">
          <stop offset="0%" stop-color="${palette.background}" stop-opacity="0" />
          <stop offset="100%" stop-color="${palette.background}" stop-opacity="${usesDarkChrome ? 0.22 : 0.12}" />
        </radialGradient>
      </defs>
      <rect width="${width}" height="${height}" fill="${palette.background}" />
      <rect width="${width}" height="${height}" fill="url(#init-ticket-dots)" opacity="${usesDarkChrome ? 0.38 : 0.48}" />
      <rect width="${width}" height="${height}" fill="url(#init-ticket-pink-wash)" />
      <rect width="${width}" height="${height}" fill="url(#init-ticket-accent-glow)" />
      <rect width="${width}" height="${height}" fill="${palette.mutedWash}" />
      ${particles}
      <rect width="${width}" height="${height}" fill="url(#init-ticket-center-wash)" />
    </svg>
  `.trim()
}

export function buildInitTicketOgBackgroundDataUri(
  accentColor: string,
  usesDarkChrome: boolean,
  width = INIT_TICKET_IMAGE_WIDTH,
  height = INIT_TICKET_IMAGE_HEIGHT,
): string {
  const svg = buildInitTicketOgBackgroundSvg(
    accentColor,
    usesDarkChrome,
    width,
    height,
  )
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`
}
