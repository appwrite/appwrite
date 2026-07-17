import { useNavigate, useParams } from '@tanstack/react-router'
import {
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { canWriteRules } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useDebugMode } from '@/components/global/providers/DebugMode'
import { useT } from '@/lib/i18n/translate'
import { ServiceHeader } from '../shared/ServiceHeader'
import { TrafficOverview } from './TrafficOverview'
import { RulesList } from './Rules'
import { FirewallEnabledCard } from './_components/FirewallEnabledCard'

export function View() {
  const t = useT()
  const navigate = useNavigate()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const { isDebugModeOpen } = useDebugMode()
  const canWrite = canWriteRules(access, features)

  return (
    <div className="flex h-full flex-col">
      <ServiceHeader
        title={t('Firewall')}
        showFilters={false}
        fullWidthBorder
        fullWidth
      />

      <div className="flex-1 overflow-y-auto">
        <TrafficOverview />

        <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6">
          {isDebugModeOpen ? (
            <FirewallEnabledCard projectId={projectId} canWrite={canWrite} />
          ) : null}
          <RulesList
            projectId={projectId}
            canWrite={canWrite}
            onCreate={() =>
              navigate({
                to: '/projects/$projectId/firewall/create',
                params: { projectId },
              })
            }
          />
        </div>
      </div>
    </div>
  )
}
