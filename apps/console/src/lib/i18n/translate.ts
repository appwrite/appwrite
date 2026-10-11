import { useCallback } from 'react'
import { useI18n, type SupportedLanguage } from '@/lib/i18n'
import { getActiveLanguage } from '@/lib/i18n/active-language'
import { heDictionary } from '@/lib/i18n/dictionaries/he'
import { jaDictionary } from '@/lib/i18n/dictionaries/ja'

const LANGUAGE_DICTIONARIES: Partial<
  Record<SupportedLanguage, Record<string, string>>
> = {
  he: heDictionary,
  ja: jaDictionary,
}

/**
 * Translate an English source string to the given language.
 * English is the source of truth: unknown strings fall back to English.
 */
export function translateText(
  text: string,
  language: SupportedLanguage,
): string {
  if (language === 'en') return text
  const dictionary = LANGUAGE_DICTIONARIES[language]
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
  return useCallback((text: string) => translateText(text, language), [language])
}

/**
 * Translate outside React (toasts fired from utilities, error formatting, etc.).
 * Resolves the active language on each call.
 */
export function translate(text: string): string {
  return translateText(text, getActiveLanguage())
}
