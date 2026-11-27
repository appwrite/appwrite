import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  canSeeProjectNavItem,
  canWriteReviewRepositories,
} from '@/lib/console-access-checks'
import {
  useConsoleVariables,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'

/**
 * Whether code review shows in a project, by the same rule as the sidebar's
 * Review item, and whether the viewer may turn it on or off for repositories.
 */
export function useReviewsAccess(projectId: string | null | undefined) {
  const { project } = useProject(projectId ?? undefined)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const { reviewsEnabled } = useConsoleVariables(project?.region)

  const visible =
    features.reviews &&
    reviewsEnabled &&
    canSeeProjectNavItem(access, features, 'reviews')

  return {
    visible,
    canWrite: visible && canWriteReviewRepositories(access, features),
  }
}
