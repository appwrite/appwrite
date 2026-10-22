import type { CSSProperties } from 'react'
import { cn } from '@/lib/utils'

/** Bottom-left floating promo card width variants. */
export type ConsoleBannerCardSize = 'default' | 'large'

export type ConsoleBannerCardAspectRatio = 'default' | 'square'

export const CONSOLE_BANNER_FLOATING_CARD_BASE_MAX_WIDTH_PX = 280

/** 1:1 floating promo cards (Native OAuth promo). Midpoint between 280 and 320. */
export const CONSOLE_BANNER_FLOATING_CARD_SQUARE_MAX_WIDTH_PX = 300

/** Max width for `large` (slightly above default 280px). */
export const CONSOLE_BANNER_FLOATING_CARD_LARGE_MAX_WIDTH_PX = 300

/** @deprecated Renamed to {@link CONSOLE_BANNER_FLOATING_CARD_LARGE_MAX_WIDTH_PX}. */
export const CONSOLE_BANNER_FLOATING_CARD_LARGE_WIDTH_SCALE =
  CONSOLE_BANNER_FLOATING_CARD_LARGE_MAX_WIDTH_PX /
  CONSOLE_BANNER_FLOATING_CARD_BASE_MAX_WIDTH_PX

/** Preview height scale for `large` (non-square cards only). */
export const CONSOLE_BANNER_FLOATING_CARD_LARGE_PREVIEW_SCALE = 1.3

export function getConsoleBannerFloatingCardMaxWidthPx(
  cardSize: ConsoleBannerCardSize = 'default',
  aspectRatio: ConsoleBannerCardAspectRatio = 'default',
): number {
  if (aspectRatio === 'square') {
    return CONSOLE_BANNER_FLOATING_CARD_SQUARE_MAX_WIDTH_PX
  }
  return cardSize === 'large'
    ? CONSOLE_BANNER_FLOATING_CARD_LARGE_MAX_WIDTH_PX
    : CONSOLE_BANNER_FLOATING_CARD_BASE_MAX_WIDTH_PX
}

export function scaleConsoleBannerLayoutPx(
  px: number,
  cardSize: ConsoleBannerCardSize = 'default',
): number {
  if (cardSize !== 'large') return px
  return Math.round(px * CONSOLE_BANNER_FLOATING_CARD_LARGE_PREVIEW_SCALE)
}

export function consoleBannerFloatingCardShellClassName(
  cardSize: ConsoleBannerCardSize = 'default',
  aspectRatio: ConsoleBannerCardAspectRatio = 'default',
): string {
  const maxW = getConsoleBannerFloatingCardMaxWidthPx(cardSize, aspectRatio)
  return cn(
    'fixed bottom-4 start-4 z-50 overflow-hidden rounded-lg border border-[#2d2d31]',
    aspectRatio === 'square'
      ? 'flex flex-col'
      : `w-[min(calc(100%-2rem),${maxW}px)]`,
    'bg-[linear-gradient(to_bottom,#0d0d10_0%,#131316_55%,#19191c_100%)]',
  )
}

/** Fixed square dimensions (avoids `aspect-square` growing with viewport width). */
export function consoleBannerFloatingCardShellStyle(
  cardSize: ConsoleBannerCardSize = 'default',
  aspectRatio: ConsoleBannerCardAspectRatio = 'default',
): CSSProperties | undefined {
  if (aspectRatio !== 'square') return undefined
  const px = getConsoleBannerFloatingCardMaxWidthPx(cardSize, aspectRatio)
  return {
    width: px,
    height: px,
    maxWidth: `min(calc(100vw - 2rem), ${px}px)`,
    maxHeight: `min(calc(100dvh - 2rem), ${px}px)`,
  }
}

export function isConsoleBannerFloatingCardLarge(
  cardSize: ConsoleBannerCardSize = 'default',
): boolean {
  return cardSize === 'large'
}
