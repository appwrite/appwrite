import {
  createContext,
  useContext,
  useEffect,
  useMemo,
  type ReactNode,
} from 'react'
import { useDebugOverrides, type DebugLanguageOverride } from '@/lib/debug-overrides'
import { enCatalog, type EnCatalog } from '@/lib/i18n/messages/en' // pragma: allowlist secret

import { heCatalog } from '@/lib/i18n/messages/he' // pragma: allowlist secret

export type SupportedLanguage = 'en' | 'he'

const LANGUAGE_CATALOGS: Record<SupportedLanguage, EnCatalog> = {
  en: enCatalog,
  he: heCatalog,
}

function resolveLanguagePreference(
  preference: DebugLanguageOverride,
): SupportedLanguage {
  if (preference === 'en' || preference === 'he') return preference

  if (typeof navigator === 'undefined') return 'en'

  const browserLocales =
    navigator.languages && navigator.languages.length > 0
      ? navigator.languages
      : [navigator.language]
  const prefersHebrew = browserLocales.some(
    (locale) => typeof locale === 'string' && locale.toLowerCase().startsWith('he'),
  )

  if (prefersHebrew) {
    return 'he'
  }

  return 'en'
}

type I18nContextValue = {
  language: SupportedLanguage
  languagePreference: DebugLanguageOverride
  catalog: EnCatalog
}

const DEFAULT_LANGUAGE: SupportedLanguage = 'en'

const I18nContext = createContext<I18nContextValue>({
  language: DEFAULT_LANGUAGE,
  languagePreference: 'auto',
  catalog: enCatalog,
})

export function I18nProvider({ children }: { children: ReactNode }) {
  const { language: languagePreference } = useDebugOverrides()
  const language = resolveLanguagePreference(languagePreference)
  const catalog = LANGUAGE_CATALOGS[language]

  useEffect(() => {
    if (typeof document === 'undefined') return
    document.documentElement.lang = language
  }, [language])

  const value = useMemo<I18nContextValue>(
    () => ({
      language,
      languagePreference,
      catalog,
    }),
    [language, languagePreference, catalog],
  )

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>
}

export function useI18n() {
  return useContext(I18nContext)
}

export function getEnglishCatalog() {
  return enCatalog
}
