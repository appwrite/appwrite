import { useState, useEffect } from 'react'
import {
  getActiveProfileId,
  getActiveProfile,
  subscribeToProfileChange,
  type ConsoleProfileId,
  type ConsoleProfileFeatures,
} from '@/lib/console-profiles'

/**
 * Hook to access the current console profile and feature flags.
 * Re-renders when the profile or any feature override changes (e.g. via debug menu).
 */
export function useConsoleProfile() {
  const [profileId, setProfileId] =
    useState<ConsoleProfileId>(getActiveProfileId)
  const [, setProfileVersion] = useState(0)

  useEffect(() => {
    return subscribeToProfileChange(() => {
      setProfileId(getActiveProfileId())
      setProfileVersion((v) => v + 1)
    })
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
