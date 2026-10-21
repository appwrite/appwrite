import type { Models } from '@appwrite.io/console'
import { isProjectAgentsLandingDismissed } from '@/lib/user-prefs-keys'
import type { UserPrefs } from '@/lib/user-prefs-keys'

export type ProjectRootLandingTarget = 'overview' | 'agents'

/**
 * Empty/missing `firstAccessedAt` means the project has not received a
 * non-console API request yet. A value means the project has been used.
 */
export function hasProjectFirstAccessedAt(
  project: Models.Project | null | undefined,
): boolean {
  if (!project) return false
  const value = project.firstAccessedAt
  return typeof value === 'string' && value.trim() !== ''
}

export function resolveProjectRootLanding(options: {
  project: Models.Project | null | undefined
  prefs: UserPrefs | null | undefined
  projectId: string
  canAccessAgents: boolean
}): ProjectRootLandingTarget {
  if (hasProjectFirstAccessedAt(options.project)) {
    return 'overview'
  }
  if (isProjectAgentsLandingDismissed(options.prefs, options.projectId)) {
    return 'overview'
  }
  if (!options.canAccessAgents) {
    return 'overview'
  }
  return 'agents'
}
