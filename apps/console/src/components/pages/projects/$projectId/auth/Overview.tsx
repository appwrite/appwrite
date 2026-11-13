import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  useOrganizationScopes,
  useProject,
  useProjectAuthMauChart,
  useProjectTeams,
  usersQueryOptions,
} from '@/lib/react-query/hooks'
import {
  formatAuthMauTotal,
  getAuthMauDisplayTotal,
} from '@/lib/usage/auth-usage'
import { getStableUsageChartDateRange } from '@/lib/usage/usage-date-range'
import { useProjectOAuth2Providers } from '@/lib/react-query/hooks/oauth2-providers'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { authMethodsRecordFromProject } from '@/lib/project-settings'
import {
  canCreateUser,
  canSeeUsageNav,
  canShowAuthSecuritySettings,
} from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { OverviewStatTiles } from '../shared/OverviewStatTiles'
import { AUTH_METHODS } from './Settings'
import { AuthMethodsCard } from './_components/AuthMethodsCard'
import { RecentUsersCard } from './_components/RecentUsersCard'
import { UsersActivityCard } from './_components/UsersActivityCard'
import { PoliciesCard } from './_components/PoliciesCard'

/** List totals stop counting here (APP_LIMIT_COUNT on the server). */
const LIST_TOTAL_LIMIT = 5000

function formatListTotal(total: number) {
  return total >= LIST_TOTAL_LIMIT
    ? `${LIST_TOTAL_LIMIT.toLocaleString()}+`
    : total.toLocaleString()
}

export function Overview({
  projectId,
  onCreateUser,
}: {
  projectId: string
  /** Opens the Auth view's create user drawer. */
  onCreateUser: () => void
}) {
  const t = useT()
  const { project, projectData } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canManage = canShowAuthSecuritySettings(access, features)
  const showUsage = features.usageStats && canSeeUsageNav(access, features)

  // Same pages the Users and Teams tabs open on, newest users first.
  const { data: usersData, isLoading: usersLoading } = useQuery(
    usersQueryOptions(projectId, 0, GRID_DEFAULT_PAGE_SIZE),
  )
  const users = usersData?.users ?? []
  const usersTotal = usersData?.total ?? 0
  const { total: teamsTotal, isLoading: teamsLoading } = useProjectTeams(
    projectId,
    0,
    GRID_DEFAULT_PAGE_SIZE,
  )
  const { data: providerList } = useProjectOAuth2Providers(
    canManage ? projectId : null,
  )
  // Same query as the Activity card, so the tile adds no request.
  const usageDateRange = useMemo(() => getStableUsageChartDateRange(), [])
  const mauQuery = useProjectAuthMauChart(projectId, usageDateRange, showUsage)
  const mauPoints = mauQuery.data?.chartPoints ?? []
  const mau = getAuthMauDisplayTotal(mauPoints)

  const methods = authMethodsRecordFromProject(projectData)
  const enabledMethods = AUTH_METHODS.filter((method) => methods[method.key])
  const providerIds = canManage
    ? (providerList?.providers ?? [])
        .filter((provider) => provider.enabled)
        .map((provider) => provider.$id)
    : undefined
  const lastSignUp = users[0]?.$createdAt

  return (
    <div className="space-y-6">
      <OverviewStatTiles
        items={[
          {
            label: t('Users'),
            value: formatListTotal(usersTotal),
            loading: usersLoading,
          },
          ...(showUsage
            ? [
                {
                  label: t('Monthly active users'),
                  value: mauQuery.isError ? '-' : formatAuthMauTotal(mau),
                  change: mauQuery.isError
                    ? undefined
                    : mauQuery.data?.changePercent,
                  loading:
                    mauQuery.isPending && !mauQuery.data && !mauQuery.isError,
                },
              ]
            : []),
          {
            label: t('Teams'),
            value: formatListTotal(teamsTotal),
            loading: teamsLoading,
          },
          {
            label: t('Auth methods'),
            value: t('{enabled} of {total}')
              .replace('{enabled}', enabledMethods.length.toLocaleString())
              .replace('{total}', AUTH_METHODS.length.toLocaleString()),
            hint: providerIds
              ? providerIds.length === 1
                ? t('1 social provider')
                : t('{count} social providers').replace(
                    '{count}',
                    providerIds.length.toLocaleString(),
                  )
              : undefined,
          },
          {
            label: t('Last sign-up'),
            value: lastSignUp ? (
              <DateTooltip
                date={lastSignUp}
                live
                className="text-[20px] font-semibold text-foreground"
              />
            ) : (
              t('Never')
            ),
            loading: usersLoading,
          },
        ]}
      />

      <RecentUsersCard
        projectId={projectId}
        users={users}
        totalLabel={formatListTotal(usersTotal)}
        onCreateUser={onCreateUser}
        createDisabled={!canCreateUser(access, features)}
      />

      {showUsage || canManage ? (
        <div
          className={cn(
            'grid gap-6',
            showUsage && canManage && 'lg:grid-cols-2',
          )}
        >
          {showUsage ? <UsersActivityCard projectId={projectId} /> : null}
          {canManage ? <PoliciesCard projectId={projectId} /> : null}
        </div>
      ) : null}

      <AuthMethodsCard
        projectId={projectId}
        methods={methods}
        providerIds={providerIds}
        canManage={canManage}
      />
    </div>
  )
}
