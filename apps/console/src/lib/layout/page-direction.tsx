'use client'

import { useEffect, type ReactNode } from 'react'
import { DirectionProvider } from '@radix-ui/react-direction'
import {
  useDebugOverrides,
  setDebugOverride,
  type PageDirectionOverride,
} from '@/lib/debug-overrides'
import { useI18n } from '@/lib/i18n'
import type { SupportedLanguage } from '@/lib/i18n/active-language'

export function resolveEffectivePageDirection(
  language: SupportedLanguage,
): PageDirectionOverride {
  return language === 'he' ? 'rtl' : 'ltr'
}

export function usePageDirection(): PageDirectionOverride {
  const { language } = useI18n()
  return resolveEffectivePageDirection(language)
}

export function PageDirectionProvider({ children }: { children: ReactNode }) {
  const { language } = useI18n()
  const { pageDirection } = useDebugOverrides()
  const effectiveDirection = resolveEffectivePageDirection(language)

  useEffect(() => {
    if (language === 'he' && pageDirection !== 'rtl') {
      setDebugOverride('pageDirection', 'rtl')
    } else if (language === 'en' && pageDirection !== 'ltr') {
      setDebugOverride('pageDirection', 'ltr')
    }
  }, [language, pageDirection])

  useEffect(() => {
    document.documentElement.dir = effectiveDirection
  }, [effectiveDirection])

  return <DirectionProvider dir={effectiveDirection}>{children}</DirectionProvider>
}
