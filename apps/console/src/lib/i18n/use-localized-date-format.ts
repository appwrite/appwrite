'use client'

import { useMemo } from 'react'
import { useI18n } from '@/lib/i18n'
import {
  formatLocalizedDate,
  formatLocalizedDateShort,
  formatLocalizedDateTime,
  getDayPickerLocale,
  getIntlLocale,
} from '@/lib/i18n/date-format'

export function useLocalizedDateFormat() {
  const { language } = useI18n()

  return useMemo(
    () => ({
      language,
      intlLocale: getIntlLocale(language),
      dayPickerLocale: getDayPickerLocale(language),
      formatDate: (date: Date, pattern: string) =>
        formatLocalizedDate(date, pattern, language),
      formatDateTime: (date: Date, options: Intl.DateTimeFormatOptions) =>
        formatLocalizedDateTime(date, options, language),
      formatDateShort: (date: Date) =>
        formatLocalizedDateShort(date, language),
    }),
    [language],
  )
}
