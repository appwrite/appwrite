export type GeneratorPanelVisibility = {
  left: boolean
  right: boolean
}

export const GENERATOR_PANEL_VISIBILITY_DEFAULT: GeneratorPanelVisibility = {
  left: true,
  right: true,
}

export const GENERATOR_PANEL_VISIBILITY_STORAGE_KEY =
  'console.generator.panelVisibility'

export function normalizeGeneratorPanelVisibility(
  value: Partial<GeneratorPanelVisibility> | null | undefined,
): GeneratorPanelVisibility {
  return {
    left: value?.left !== false,
    right: value?.right !== false,
  }
}

export function readGeneratorPanelVisibilityFromStorage(): GeneratorPanelVisibility {
  if (typeof window === 'undefined') return GENERATOR_PANEL_VISIBILITY_DEFAULT

  try {
    const raw = window.localStorage.getItem(GENERATOR_PANEL_VISIBILITY_STORAGE_KEY)
    if (!raw) return GENERATOR_PANEL_VISIBILITY_DEFAULT
    return normalizeGeneratorPanelVisibility(JSON.parse(raw) as GeneratorPanelVisibility)
  } catch {
    return GENERATOR_PANEL_VISIBILITY_DEFAULT
  }
}

export function writeGeneratorPanelVisibilityToStorage(
  visibility: GeneratorPanelVisibility,
): void {
  if (typeof window === 'undefined') return

  try {
    window.localStorage.setItem(
      GENERATOR_PANEL_VISIBILITY_STORAGE_KEY,
      JSON.stringify(visibility),
    )
  } catch {
    /* private mode */
  }
}
