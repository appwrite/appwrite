import { format } from 'date-fns'
import type { Locale } from 'date-fns'
import { enGB } from 'date-fns/locale/en-GB'
import { he as heDateFns } from 'date-fns/locale/he'
import { enGB as enDayPickerLocale } from 'react-day-picker/locale/en-GB'
import { he as heDayPickerLocale } from 'react-day-picker/locale/he'
import {
  getActiveLanguage,
  type SupportedLanguage,
} from '@/lib/i18n/active-language'

type DayPickerLocale = typeof enDayPickerLocale

const DATE_FNS_LOCALES: Record<SupportedLanguage, Locale> = {
  en: enGB,
  he: heDateFns,
}

const INTL_LOCALES: Record<SupportedLanguage, string> = {
  en: 'en-GB',
  he: 'he-IL',
}

const DAY_PICKER_LOCALES: Record<SupportedLanguage, DayPickerLocale> = {
  en: enDayPickerLocale,
  he: heDayPickerLocale,
}

export function getDateFnsLocale(
  language: SupportedLanguage = getActiveLanguage(),
): Locale {
  return DATE_FNS_LOCALES[language] ?? enGB
}

export function getIntlLocale(
  language: SupportedLanguage = getActiveLanguage(),
): string {
  return INTL_LOCALES[language] ?? 'en-GB'
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
