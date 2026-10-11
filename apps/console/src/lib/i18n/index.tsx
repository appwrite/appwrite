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
import { jaCatalog } from '@/lib/i18n/messages/ja' // pragma: allowlist secret
import {
  resolveLanguagePreference,
  type SupportedLanguage,
} from '@/lib/i18n/active-language'

export type { SupportedLanguage }

const LANGUAGE_CATALOGS: Record<SupportedLanguage, EnCatalog> = {
  en: enCatalog,
  he: heCatalog,
  ja: jaCatalog,
}

type I18nContextValue = {
  language: SupportedLanguage
  languagePreference: DebugLanguageOverride
  catalog: EnCatalog
}

const DEFAULT_LANGUAGE: SupportedLanguage = 'en'

const I18nContext = createContext<I18nContextValue>({
  language: DEFAULT_LANGUAGE,
  languagePreference: 'en',
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
