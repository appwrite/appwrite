import {
  getActiveProfileId,
  getCanonicalProfileFeatures,
  resetDebugProfileFeatureOverride,
  setDebugProfileFeatureOverride,
  type ConsoleProfileFeatures,
} from '@/lib/console-profiles'

/** Console account label that can open the experiments menu. */
export const QUALITY_ACCOUNT_LABEL = 'quality'

export type Experiment = {
  flag: keyof ConsoleProfileFeatures
  name: string
  description: string
}

/**
 * Feature flags the quality team can turn on from the header.
 * Add a row here to expose another flag. Everything else stays in the debug menu.
 */
export const EXPERIMENTS: readonly Experiment[] = [
  {
    flag: 'analytics',
    name: 'Appwrite Analytics',
    description:
      'Show Analytics in the console and track visitors, pages, sources, campaigns, and custom events in a project.',
  },
]

export function hasQualityLabel(
  labels: readonly string[] | null | undefined,
): boolean {
  return labels?.includes(QUALITY_ACCOUNT_LABEL) === true
}

/** Persist one experiment on this browser, or drop the override when it matches the profile default. */
export function setExperimentEnabled(
  flag: keyof ConsoleProfileFeatures,
  enabled: boolean,
) {
  const canonical = getCanonicalProfileFeatures(getActiveProfileId())[flag]
  if (enabled === canonical) {
    resetDebugProfileFeatureOverride(flag)
    return
  }
  setDebugProfileFeatureOverride(flag, enabled)
}
