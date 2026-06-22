/** Secondary brand light tones used in marketing soft-light washes. */
export type CoverSoftLightTone = 'pink' | 'purple' | 'teal' | 'orange'

export type CoverSoftLightOpacityLevel = {
  strong: number
  mid: number
}

export type CoverSoftLightOpacity = Record<
  CoverSoftLightTone,
  CoverSoftLightOpacityLevel
>

/** Matches HomeSoftLights / marketing hero rgba values. */
export const COVER_BRAND_LIGHT_RGB: Record<
  CoverSoftLightTone,
  [number, number, number]
> = {
  pink: [253, 54, 110],
  purple: [124, 103, 254],
  teal: [133, 219, 216],
  orange: [254, 149, 103],
}

const LIGHT_OPACITY: CoverSoftLightOpacity = {
  pink: { strong: 0.2, mid: 0.07 },
  purple: { strong: 0.17, mid: 0.06 },
  teal: { strong: 0.17, mid: 0.06 },
  orange: { strong: 0.17, mid: 0.06 },
}

const DARK_OPACITY: CoverSoftLightOpacity = {
  pink: { strong: 0.12, mid: 0.04 },
  purple: { strong: 0.11, mid: 0.04 },
  teal: { strong: 0.11, mid: 0.04 },
  orange: { strong: 0.11, mid: 0.04 },
}

export function getCoverSoftLightOpacityPreset(
  family: 'light' | 'dark',
  scale = 1,
): CoverSoftLightOpacity {
  const base = family === 'light' ? LIGHT_OPACITY : DARK_OPACITY
  if (scale === 1) return base

  return (Object.keys(base) as CoverSoftLightTone[]).reduce((acc, tone) => {
    acc[tone] = {
      strong: base[tone].strong * scale,
      mid: base[tone].mid * scale,
    }
    return acc
  }, {} as CoverSoftLightOpacity)
}

export function getCoverBrandLightRgb(tone: CoverSoftLightTone): [number, number, number] {
  return COVER_BRAND_LIGHT_RGB[tone]
}
