import { cssColorToHex, getCssColorExpression } from '@/lib/css-theme-colors'
import type { GlobeConfig } from '@/components/ui/globe'

/** Brand CTA as `rgb(...)` for arc alpha blending. */
export function getInitGlobeBrandRgb(): string {
  return getCssColorExpression('var(--brand-cta)', 'rgb(253, 54, 110)')
}

const EVEN_LIGHTING = {
  evenLighting: true,
  ambientLightIntensity: 2.4,
  directionalLightIntensity: 0.2,
} as const

/**
 * Globe palette from theme tokens.
 * Light mode: bright, evenly lit. Dark mode: deeper sphere with directional lighting.
 */
export function buildInitGlobeConfig(isDark: boolean): GlobeConfig {
  const softAtmosphere = cssColorToHex(
    isDark
      ? 'color-mix(in srgb, var(--brand-cta) 32%, var(--card))'
      : 'color-mix(in srgb, var(--brand-cta) 24%, var(--card))',
    isDark ? '#3d2f38' : '#f5e8ee',
  )

  if (!isDark) {
    const ocean = cssColorToHex('var(--background)', '#ffffff')
    const land = cssColorToHex(
      'color-mix(in srgb, var(--muted-foreground) 70%, var(--border))',
      '#8b8b96',
    )

    return {
      ...EVEN_LIGHTING,
      pointSize: 4,
      globeColor: ocean,
      showAtmosphere: true,
      atmosphereColor: softAtmosphere,
      atmosphereAltitude: 0.11,
      emissive: ocean,
      emissiveIntensity: 0.45,
      shininess: 0.08,
      polygonColor: land,
      ambientLight: '#ffffff',
      directionalLeftLight: '#ffffff',
      directionalTopLight: '#ffffff',
      fogColor: ocean,
      arcTime: 1400,
      arcLength: 0.9,
      rings: 1,
      maxRings: 3,
      autoRotate: true,
      autoRotateSpeed: 0.5,
    }
  }

  const ocean = cssColorToHex(
    'color-mix(in srgb, var(--brand-cta) 5%, color-mix(in srgb, var(--muted) 82%, var(--muted-foreground)))',
    '#43434d',
  )
  const land = cssColorToHex(
    'color-mix(in srgb, var(--muted-foreground) 70%, var(--foreground))',
    '#b8b8c0',
  )

  return {
    pointSize: 4,
    globeColor: ocean,
    showAtmosphere: true,
    atmosphereColor: softAtmosphere,
    atmosphereAltitude: 0.13,
    emissive: ocean,
    emissiveIntensity: 0.14,
    shininess: 0.85,
    polygonColor: land,
    ambientLight: cssColorToHex(
      'color-mix(in srgb, var(--brand-cta) 22%, var(--muted-foreground))',
      '#9a8a92',
    ),
    directionalLeftLight: cssColorToHex('var(--foreground)', '#fafafa'),
    directionalTopLight: cssColorToHex('var(--muted-foreground)', '#a1a1aa'),
    pointLight: cssColorToHex(
      'color-mix(in srgb, var(--brand-cta) 35%, var(--card))',
      '#5c3d4d',
    ),
    fogColor: ocean,
    arcTime: 1400,
    arcLength: 0.9,
    rings: 1,
    maxRings: 3,
    autoRotate: true,
    autoRotateSpeed: 0.5,
  }
}
