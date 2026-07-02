'use client'

import { useEffect, type ReactNode } from 'react'
import { DirectionProvider } from '@radix-ui/react-direction'
import {
  useDebugOverrides,
  setDebugOverride,
  type PageDirectionOverride,
} from '@/lib/debug-overrides'
import { useI18n } from '@/lib/i18n'

export function usePageDirection(): PageDirectionOverride {
  return useDebugOverrides().pageDirection
}

export function PageDirectionProvider({ children }: { children: ReactNode }) {
  const { language } = useI18n()
  const pageDirection = usePageDirection()
  const effectiveDirection: PageDirectionOverride =
    language === 'he' ? 'rtl' : pageDirection

  useEffect(() => {
    if (language === 'he' && pageDirection !== 'rtl') {
      setDebugOverride('pageDirection', 'rtl')
    }
  }, [language, pageDirection])

  useEffect(() => {
    document.documentElement.dir = effectiveDirection
  }, [effectiveDirection])

  return <DirectionProvider dir={effectiveDirection}>{children}</DirectionProvider>
}
