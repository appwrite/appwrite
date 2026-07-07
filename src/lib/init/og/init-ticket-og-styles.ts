import { initTicketOgScalePx } from '@/lib/init/ticket-layout'
import { INIT_TICKET_OG_FONT_INTER } from '@/lib/init/og/fonts'

const SERVER_BRAND_CTA = '#fd366e'

/** Typography and spacing matched to InitTicketCard at max width, scaled to OG pixels. */
export const INIT_TICKET_OG_LAYOUT = {
  wordmarkHeight: initTicketOgScalePx(38),
  dateSize: initTicketOgScalePx(13),
  stackIconSize: initTicketOgScalePx(20),
  stackGap: initTicketOgScalePx(14),
  headerGap: initTicketOgScalePx(8),
  bodyGap: initTicketOgScalePx(16),
  detailGap: initTicketOgScalePx(10),
  detailPaddingTop: initTicketOgScalePx(16),
  titleSize: initTicketOgScalePx(16),
  githubSize: initTicketOgScalePx(12),
  githubIconSize: initTicketOgScalePx(14),
  githubGap: initTicketOgScalePx(6),
  passSize: initTicketOgScalePx(9),
  ticketSize: initTicketOgScalePx(10),
  stubWordmarkHeight: initTicketOgScalePx(14),
  stubTicketSize: initTicketOgScalePx(12),
  stubNameSize: initTicketOgScalePx(16),
  stubPassSize: initTicketOgScalePx(7),
  stubGap: initTicketOgScalePx(4),
} as const

export function resolveInitTicketOgAccentColor(accentColor: string): string {
  return accentColor.startsWith('var(') ? SERVER_BRAND_CTA : accentColor
}

export function getInitTicketOgPalette(usesDarkChrome: boolean) {
  return {
    text: usesDarkChrome ? '#ffffff' : '#111827',
    date: usesDarkChrome ? 'rgba(255,255,255,0.60)' : '#737373',
    muted: usesDarkChrome ? 'rgba(255,255,255,0.55)' : '#737373',
    label: usesDarkChrome ? 'rgba(255,255,255,0.70)' : '#525252',
    github: usesDarkChrome ? 'rgba(255,255,255,0.75)' : '#525252',
    separator: usesDarkChrome ? 'rgba(255,255,255,0.20)' : 'rgba(17,24,39,0.15)',
  }
}

/** Card uses Tailwind tracking in em; Satori needs px letterSpacing. */
export function initTicketOgEmTracking(fontSize: number, em: number): number {
  return Math.round(fontSize * em * 10) / 10
}

export function initTicketOgNameTracking(nameSize: number): number {
  return initTicketOgEmTracking(nameSize, -0.025)
}

export function initTicketOgTextStyle(
  size: number,
  color: string,
  extra?: Record<string, string | number>,
) {
  return {
    fontFamily: INIT_TICKET_OG_FONT_INTER,
    fontSize: size,
    color,
    margin: 0,
    padding: 0,
    lineHeight: 1.2,
    ...extra,
  }
}

export function initTicketOgHolderNameSize(name: string): number {
  const len = name.trim().length
  if (len > 32) return initTicketOgScalePx(28)
  if (len > 24) return initTicketOgScalePx(34)
  if (len > 16) return initTicketOgScalePx(40)
  return initTicketOgScalePx(48)
}

export function initTicketOgStubTitleSize(title: string): number {
  const len = title.trim().length
  if (len > 36) return initTicketOgScalePx(8)
  if (len > 28) return initTicketOgScalePx(9)
  return initTicketOgScalePx(10)
}

export function truncateInitTicketOgText(value: string, maxLength: number): string {
  const trimmed = value.trim()
  if (trimmed.length <= maxLength) return trimmed
  return `${trimmed.slice(0, Math.max(0, maxLength - 3)).trim()}...`
}
