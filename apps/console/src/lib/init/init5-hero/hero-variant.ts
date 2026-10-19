/** Hero concepts for the Init 5 cover. Kept dependency-free so debug overrides can import it. */
export const INIT5_HERO_VARIANTS = [
  {
    id: 'particles',
    label: 'Particles',
    description:
      'Luminous particle 5 that assembles, breathes and reacts to the cursor.',
  },
  {
    id: 'trails',
    label: 'Light trails',
    description:
      'Streaks of light racing along the 5, drawn in Init line-art style.',
  },
  {
    id: 'glass',
    label: 'Glass',
    description: 'A refractive glass and chrome 5 on a lit stage.',
  },
] as const

export type Init5HeroVariantId = (typeof INIT5_HERO_VARIANTS)[number]['id']

export const INIT5_HERO_VARIANT_IDS = INIT5_HERO_VARIANTS.map(
  (variant) => variant.id,
) as readonly Init5HeroVariantId[]

export const DEFAULT_INIT5_HERO_VARIANT: Init5HeroVariantId = 'particles'

/** `/init?hero=` override so a concept can be shared by link. */
export const INIT5_HERO_VARIANT_SEARCH_PARAM = 'hero'

export function isInit5HeroVariantId(
  value: unknown,
): value is Init5HeroVariantId {
  return (
    typeof value === 'string' &&
    (INIT5_HERO_VARIANT_IDS as readonly string[]).includes(value)
  )
}

export function formatInit5HeroVariant(id: Init5HeroVariantId): string {
  return INIT5_HERO_VARIANTS.find((variant) => variant.id === id)?.label ?? id
}
