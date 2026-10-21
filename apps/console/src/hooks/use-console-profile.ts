import { useState, useEffect } from 'react'
import {
  getActiveProfile,
  getActiveProfileWithoutDebugOverride,
  subscribeToProfileChange,
  type ConsoleProfile,
  type ConsoleProfileFeatures,
} from '@/lib/console-profiles'

/**
 * Hook to access the current console profile and feature flags.
 * Re-renders when the profile or any feature override changes (e.g. via debug menu).
 */
export function useConsoleProfile() {
  // Do not read debug localStorage during useState init — that diverges from SSR and
  // causes hydration mismatches (e.g. footer product links when feature flags differ).
  const [profile, setProfile] = useState<ConsoleProfile>(
    getActiveProfileWithoutDebugOverride,
  )

  useEffect(() => {
    const sync = () => setProfile(getActiveProfile())
    sync()
    return subscribeToProfileChange(sync)
  }, [])

  return {
    profileId: profile.id,
    profile,
    features: profile.features,
    isCloud: profile.id === 'cloud',
    isSelfHosted: profile.id === 'self-hosted',
  }
}

/**
 * Hook to check if a specific feature is enabled.
 */
export function useFeatureEnabled(
  feature: keyof ConsoleProfileFeatures,
): boolean {
  const { features } = useConsoleProfile()
  return features[feature]
}
