'use client'

import { useEffect, type ReactNode } from 'react'
import { DirectionProvider } from '@radix-ui/react-direction'
import {
  useDebugOverrides,
  type PageDirectionOverride,
} from '@/lib/debug-overrides'

export function usePageDirection(): PageDirectionOverride {
  return useDebugOverrides().pageDirection
}

export function PageDirectionProvider({ children }: { children: ReactNode }) {
  const pageDirection = usePageDirection()

  useEffect(() => {
    document.documentElement.dir = pageDirection
  }, [pageDirection])

  return <DirectionProvider dir={pageDirection}>{children}</DirectionProvider>
}
