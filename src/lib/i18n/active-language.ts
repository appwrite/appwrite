import {
  loadDebugOverrides,
  type DebugLanguageOverride,
} from '@/lib/debug-overrides'

export type SupportedLanguage = 'en' | 'he'

/**
 * Resolve a language preference ('auto' | 'en' | 'he') to a concrete language.
 * Pure function usable outside React (e.g. SDK initialization).
 */
export function resolveLanguagePreference(
  preference: DebugLanguageOverride,
): SupportedLanguage {
  if (preference === 'en' || preference === 'he') return preference

  if (typeof navigator === 'undefined') return 'en'

  const browserLocales =
    navigator.languages && navigator.languages.length > 0
      ? navigator.languages
      : [navigator.language]
  const prefersHebrew = browserLocales.some(
    (locale) =>
      typeof locale === 'string' && locale.toLowerCase().startsWith('he'),
  )

  return prefersHebrew ? 'he' : 'en'
}

/**
 * Current active UI language, resolved from the debug override (or browser
 * locale in auto mode). Safe to call outside React and during SSR.
 */
export function getActiveLanguage(): SupportedLanguage {
  return resolveLanguagePreference(loadDebugOverrides().language)
}
