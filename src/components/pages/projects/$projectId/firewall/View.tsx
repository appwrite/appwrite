import { useNavigate, useParams } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { Shield } from 'lucide-react'
import {
  firewallRulesQueryOptions,
  useProject,
  useOrganizationPlan,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { canWriteRules } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getBillingPlanResourceLimit } from '@/lib/billing/project-breakdown-resources'
import { resolveOrganizationPlanDisplayLabel } from '@/lib/utils/plan-filter'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { ServiceHeader } from '../shared/ServiceHeader'
import { PlanLimitWarning } from '../shared/PlanLimitWarning'
import { TrafficOverview } from './TrafficOverview'
import { RulesList } from './Rules'
import { Route } from '@/routes/_public/projects.$projectId.firewall.index'
import type { FirewallResourceSelection } from './_components/FirewallResourceSelector'

function firewallListSearch(selection: FirewallResourceSelection) {
  if (selection.resourceType === 'api') {
    return { resourceType: 'api' as const }
  }
  return {
    resourceType: selection.resourceType,
    resourceId: selection.resourceId,
  }
}

export function View() {
  const t = useT()
  const navigate = useNavigate()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const search = Route.useSearch()
  const resourceSelection: FirewallResourceSelection = {
    resourceType: search.resourceType ?? 'api',
    resourceId: search.resourceId,
  }

  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canWriteRules(access, features)

  // Unfiltered total for plan limit checks (independent of the rules list search).
  const { data: totalRulesData } = useQuery(
    firewallRulesQueryOptions(projectId, 0, DEFAULT_PAGE_SIZE, undefined),
  )
  const totalRulesCount = totalRulesData?.total || 0

  const wafRulesLimit =
    getBillingPlanResourceLimit(organizationPlan, 'wafRules') ?? 0

  const noCreatePermission = !canWrite
  const isAtPlanLimit = wafRulesLimit > 0 && totalRulesCount >= wafRulesLimit
  const isCreateDisabled = noCreatePermission || isAtPlanLimit
  const createDisabledTooltip = noCreatePermission
    ? t("You don't have permission to create firewall rules.")
    : isAtPlanLimit
      ? t("You've reached the limit for this resource on your plan")
      : undefined

  const planName = resolveOrganizationPlanDisplayLabel({
    planName: organizationPlan?.name ?? null,
    planId: organizationPlan?.$id,
  })

  const rulesLimitIndicator =
    wafRulesLimit > 0 ? (
      <TooltipProvider delayDuration={0}>
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              className={cn(
                'inline-flex max-w-[10.5rem] shrink-0 items-center gap-1.5 rounded-md px-1.5 py-1 text-start sm:max-w-[13rem]',
                'border border-transparent text-muted-foreground',
                'hover:border-border hover:bg-muted/50 hover:text-foreground',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
              )}
            >
              <Shield className="h-3.5 w-3.5 shrink-0 text-blue-600 dark:text-blue-400" />
              <span className="min-w-0 truncate text-[11px] leading-tight">
                <span className="text-muted-foreground">{t('Rules')} </span>
                <span className="font-medium text-foreground">
                  {totalRulesCount.toLocaleString()}/
                  {wafRulesLimit.toLocaleString()}
                </span>
              </span>
            </button>
          </TooltipTrigger>
          <TooltipContent
            side="bottom"
            align="end"
            className="max-w-sm text-[12px] leading-snug text-balance"
          >
            <p className="font-medium text-background">
              {t('Firewall rules')}
            </p>
            <p className="mt-1.5 text-background/85">
              {t('Your plan')} ({planName}) {t('includes up to')}{' '}
              <span className="font-medium text-background">
                {wafRulesLimit.toLocaleString()}
              </span>{' '}
              {t('firewall rules')}.
            </p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
    ) : null

  return (
    <div className="flex h-full flex-col">
      <ServiceHeader
        title={t('Firewall')}
        showFilters={false}
        fullWidthBorder
        fullWidth
        titleRightContent={rulesLimitIndicator}
        contentAfterBorder={
          project &&
          organizationPlan !== undefined &&
          totalRulesData !== undefined ? (
            <PlanLimitWarning
              currentCount={totalRulesCount}
              limit={wafRulesLimit}
              planName={planName}
              resourceName="firewall rules"
              orgId={project?.teamId}
              fullWidth={false}
            />
          ) : undefined
        }
      />

      <div className="flex-1 overflow-y-auto">
        <TrafficOverview />

        <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6">
          <RulesList
            projectId={projectId}
            canWrite={canWrite}
            resourceSelection={resourceSelection}
            onResourceSelectionChange={(next) => {
              void navigate({
                to: '/projects/$projectId/firewall',
                params: { projectId },
                search: firewallListSearch(next),
                replace: true,
              })
            }}
            createDisabled={isCreateDisabled}
            createDisabledTooltip={createDisabledTooltip}
            onCreate={() =>
              navigate({
                to: '/projects/$projectId/firewall/create',
                params: { projectId },
                search: firewallListSearch(resourceSelection),
              })
            }
          />
        </div>
      </div>
    </div>
  )
}
