import { useMemo } from 'react'
import type { Tab } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canShowDistributionCredentials } from '@/lib/console-access-checks'
import { useT } from '@/lib/i18n/translate'
import { useOrganizationScopes, useProject } from '@/lib/react-query/hooks'

export function useTabs(projectId: string): Tab[] {
  const t = useT()
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showCredentials = canShowDistributionCredentials(access, features)
  return useMemo(
    () => [
      {
        id: 'apps',
        label: t('Apps'),
        to: '/projects/$projectId/distribution',
        params: { projectId },
      },
      ...(showCredentials
        ? [
            {
              id: 'connections',
              label: t('Connections'),
              to: '/projects/$projectId/distribution/connections',
              params: { projectId },
            },
            {
              id: 'signing',
              label: t('Code signing'),
              to: '/projects/$projectId/distribution/signing',
              params: { projectId },
            },
          ]
        : []),
    ],
    [projectId, showCredentials, t],
  )
}
