import {
  getActiveProfileId,
  getCanonicalProfileFeatures,
  resetDebugProfileFeatureOverride,
  setDebugProfileFeatureOverride,
  type ConsoleProfileFeatures,
} from '@/lib/console-profiles'

/** Console account label that can open the previews menu. */
export const QUALITY_ACCOUNT_LABEL = 'quality'

export type Experiment = {
  flag: keyof ConsoleProfileFeatures
  name: string
  description: string
  /** Day the experiment reached the popover, as `YYYY-MM-DD`. */
  introducedAt: string
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
    introducedAt: '2026-10-23',
  },
  {
    flag: 'projectEnvironments',
    name: 'Project environments',
    description:
      'Switch between environments of a project, such as staging and production, and create one as a copy of the project.',
    introducedAt: '2026-10-29',
  },
  {
    flag: 'projectTransfers',
    name: 'Project transfers',
    description:
      'Transfer a project with a link that another organization claims, in place of moving it to one of your organizations.',
    introducedAt: '2026-10-29',
  },
]

export function hasQualityLabel(
  labels: readonly string[] | null | undefined,
): boolean {
  return labels?.includes(QUALITY_ACCOUNT_LABEL) === true
}

/** Local midnight of the introduction day, so the date reads the same in every timezone. */
export function getExperimentIntroducedDate(experiment: Experiment): Date {
  const [year, month, day] = experiment.introducedAt.split('-').map(Number)
  return new Date(year, month - 1, day)
}

/** An experiment is new when it was introduced after the user last opened the popover. */
function isExperimentUnseen(
  experiment: Experiment,
  seenAt: Date | null,
): boolean {
  if (!seenAt) return true
  return getExperimentIntroducedDate(experiment) > seenAt
}

export function countUnseenExperiments(seenAt: Date | null): number {
  return EXPERIMENTS.filter((experiment) =>
    isExperimentUnseen(experiment, seenAt),
  ).length
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
