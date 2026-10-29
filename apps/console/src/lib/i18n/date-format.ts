import { format } from 'date-fns'
import type { Locale } from 'date-fns'
import { enUS } from 'date-fns/locale/en-US'
import { he as heDateFns } from 'date-fns/locale/he'
import { ja as jaDateFns } from 'date-fns/locale/ja'
import { enUS as enDayPickerLocale } from 'react-day-picker/locale/en-US'
import { he as heDayPickerLocale } from 'react-day-picker/locale/he'
import { ja as jaDayPickerLocale } from 'react-day-picker/locale/ja'
import {
  getActiveLanguage,
  type SupportedLanguage,
} from '@/lib/i18n/active-language'

type DayPickerLocale = typeof enDayPickerLocale

const DATE_FNS_LOCALES: Record<SupportedLanguage, Locale> = {
  en: enUS,
  he: heDateFns,
  ja: jaDateFns,
}

const INTL_LOCALES: Record<SupportedLanguage, string> = {
  en: 'en-US',
  he: 'he-IL',
  ja: 'ja-JP',
}

const DAY_PICKER_LOCALES: Record<SupportedLanguage, DayPickerLocale> = {
  en: enDayPickerLocale,
  he: heDayPickerLocale,
  ja: jaDayPickerLocale,
}

export function getDateFnsLocale(
  language: SupportedLanguage = getActiveLanguage(),
): Locale {
  return DATE_FNS_LOCALES[language] ?? enUS
}

export function getIntlLocale(
  language: SupportedLanguage = getActiveLanguage(),
): string {
  return INTL_LOCALES[language] ?? 'en-US'
}

export function getDayPickerLocale(
  language: SupportedLanguage = getActiveLanguage(),
): DayPickerLocale {
  return DAY_PICKER_LOCALES[language] ?? enDayPickerLocale
}

/** date-fns `format` with locale derived from the active UI language. */
export function formatLocalizedDate(
  date: Date,
  pattern: string,
  language: SupportedLanguage = getActiveLanguage(),
): string {
  return format(date, pattern, { locale: getDateFnsLocale(language) })
}

export function formatLocalizedDateTime(
  date: Date,
  options: Intl.DateTimeFormatOptions,
  language: SupportedLanguage = getActiveLanguage(),
): string {
  return date.toLocaleString(getIntlLocale(language), options)
}

/** International short date, e.g. "11 Dec 2025" / "11 בדצמ׳ 2025". */
export function formatLocalizedDateShort(
  date: Date,
  language: SupportedLanguage = getActiveLanguage(),
): string {
  return date.toLocaleDateString(getIntlLocale(language), {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}
