import { useCallback, useSyncExternalStore } from 'react'
import { useI18n } from '@/lib/i18n'
import type { SupportedLanguage } from '@/lib/i18n/active-language'
import { getActiveLanguage } from '@/lib/i18n/active-language'
import {
  getLanguageDictionary,
  getLanguageDictionariesVersion,
  subscribeLanguageDictionaries,
} from '@/lib/i18n/dictionaries/load'

/**
 * Translate an English source string to the given language.
 * English is the source of truth: unknown strings fall back to English.
 * Non-English dictionaries load on demand; until then this returns English.
 */
export function translateText(
  text: string,
  language: SupportedLanguage,
): string {
  if (language === 'en') return text
  const dictionary = getLanguageDictionary(language)
  return dictionary?.[text] ?? text
}

export type Translator = (text: string) => string

/**
 * Hook returning a `t()` translator for the active language.
 *
 * Usage: `const t = useT()` then `t('Create bucket')`.
 * English strings stay inline in components as the source of truth;
 * other languages are looked up in per-domain dictionaries.
 */
export function useT(): Translator {
  const { language } = useI18n()
  const version = useSyncExternalStore(
    subscribeLanguageDictionaries,
    getLanguageDictionariesVersion,
    getLanguageDictionariesVersion,
  )
  return useCallback(
    (text: string) => translateText(text, language),
    [language, version],
  )
}

/**
 * Translate outside React (toasts fired from utilities, error formatting, etc.).
 * Resolves the active language on each call.
 */
export function translate(text: string): string {
  return translateText(text, getActiveLanguage())
}
