import { useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canUseAccountPasskeys, canUseProjectPasskeys } from '@/lib/passkeys'
import { organizationQueryOptions, useProject } from '@/lib/react-query/hooks'

/** Whether the project's organization may use passkeys (see `canUseProjectPasskeys`). */
export function useProjectPasskeysAllowed(
  projectId: string | null | undefined,
): boolean {
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId ?? undefined)
  const { data: organization } = useQuery({
    ...organizationQueryOptions(project?.teamId),
    enabled: features.passkeysFlag && !!project?.teamId,
  })
  return canUseProjectPasskeys(features, organization?.prefs)
}

/** Whether the signed-in console user may use passkeys (see `canUseAccountPasskeys`). */
export function useAccountPasskeysAllowed(): boolean {
  const { features } = useConsoleProfile()
  const { account } = useAuth()
  return canUseAccountPasskeys(
    features,
    (account as Models.User | undefined)?.prefs,
  )
}
