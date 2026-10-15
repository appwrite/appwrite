'use client'

import type { ReactNode } from 'react'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type { ConsoleProfileFeatures } from '@/lib/console-profiles'

type FeatureGateProps = {
  /** Comma-separated feature keys; content shows when any listed flag is on. */
  anyOf?: string
  /** Comma-separated feature keys; content shows when every listed flag is on. */
  allOf?: string
  children?: ReactNode
}

function parseFeatureKeys(raw: string | undefined): (keyof ConsoleProfileFeatures)[] {
  if (!raw?.trim()) return []
  return raw
    .split(',')
    .map((key) => key.trim())
    .filter(Boolean) as (keyof ConsoleProfileFeatures)[]
}

/**
 * Markdoc wrapper that hides children when console profile feature flags are off.
 */
export function FeatureGate({ anyOf, allOf, children }: FeatureGateProps) {
  const { features } = useConsoleProfile()
  const anyKeys = parseFeatureKeys(anyOf)
  const allKeys = parseFeatureKeys(allOf)

  if (anyKeys.length > 0) {
    const enabled = anyKeys.some((key) => Boolean(features[key]))
    if (!enabled) return null
  }

  if (allKeys.length > 0) {
    const enabled = allKeys.every((key) => Boolean(features[key]))
    if (!enabled) return null
  }

  return <>{children}</>
}
