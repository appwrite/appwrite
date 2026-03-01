import { useState, useEffect } from 'react'
import {
  getActiveProfileId,
  getActiveProfile,
  subscribeToProfileChange,
  type ConsoleProfileId,
  type ConsoleProfile,
  type ConsoleProfileFeatures,
} from '@/lib/console-profiles'

/**
 * Hook to access the current console profile and feature flags.
 * Re-renders when the profile changes (e.g. via debug menu override).
 */
export function useConsoleProfile() {
  const [profileId, setProfileId] = useState<ConsoleProfileId>(getActiveProfileId)

  useEffect(() => {
    return subscribeToProfileChange(setProfileId)
  }, [])

  const profile = getActiveProfile()
  const features = profile.features

  return {
    profileId,
    profile,
    features,
    isCloud: profileId === 'cloud',
    isSelfHosted: profileId === 'self-hosted',
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
