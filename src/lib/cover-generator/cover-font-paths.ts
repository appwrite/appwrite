/**
 * TTF assets under `public/fonts-ttf/` for cover export (fontconfig on Linux Docker,
 * SVG @font-face embed on macOS). Regenerate from WOFF2 in `public/fonts/` if brand
 * fonts change.
 */
export const COVER_EXPORT_TTF_SOURCES = {
  aeonikRegular: 'fonts-ttf/AeonikPro-Regular.ttf',
  aeonikMedium: 'fonts-ttf/AeonikPro-Medium.ttf',
  interRegular: 'fonts-ttf/Inter-Regular.ttf',
  interSemibold: 'fonts-ttf/Inter-SemiBold.ttf',
} as const
