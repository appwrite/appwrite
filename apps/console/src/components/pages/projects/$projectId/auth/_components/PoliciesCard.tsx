import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import { useOrganizationPlan, useProject } from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getPlanNameFromTier } from '@/lib/utils/plan-filter'
import { createTimeUnitPair } from '@/lib/utils/time-unit-converter'
import { useT } from '@/lib/i18n/translate'
import { SESSION_LENGTH_UNITS, useAuthSecuritySnapshot } from '../Security'

function PolicyStat({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex h-[52px] min-w-0 items-center rounded-lg border border-border/60 bg-background/40 px-3">
      <div className="min-w-0">
        <p className="truncate text-[11px] text-muted-foreground">{label}</p>
        <p className="truncate text-[13px] font-semibold tabular-nums text-foreground">
          {value}
        </p>
      </div>
    </div>
  )
}

export function PoliciesCard({ projectId }: { projectId: string }) {
  const t = useT()
  const security = useAuthSecuritySnapshot(projectId)
  const { project } = useProject(projectId)
  const { plan } = useOrganizationPlan(project?.teamId)
  const { features } = useConsoleProfile()
  // Self-hosted has no plan, which would otherwise read as Free.
  const freePlanUsers =
    features.billing &&
    plan &&
    getPlanNameFromTier(plan.$id) === 'free' &&
    plan.users > 0
      ? plan.users
      : null
  const sessionLength = createTimeUnitPair(security.authDuration, {
    units: SESSION_LENGTH_UNITS,
  })
  // `weeks` → `week` for one of a unit; both forms are translated keys.
  const sessionLengthUnit =
    sessionLength.value === 1
      ? sessionLength.unit.slice(0, -1)
      : sessionLength.unit
  const limitLabel = (limit: number) =>
    limit > 0 ? limit.toLocaleString() : t('Unlimited')
  const toggleLabel = (enabled: boolean) => (enabled ? t('On') : t('Off'))

  return (
    <div className="flex flex-col overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="flex h-16 items-center px-6">
        <div className="min-w-0">
          <h3 className="text-[15px] font-semibold leading-5 text-foreground">
            {t('Policies')}
          </h3>
          <p className="h-4 truncate text-[12px] leading-4 text-muted-foreground">
            {t('Sessions, passwords and limits')}
          </p>
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="grid flex-1 grid-cols-2 content-start gap-2 px-6 py-5">
        <PolicyStat
          label={t('Session length')}
          value={`${sessionLength.value.toLocaleString()} ${t(sessionLengthUnit)}`}
        />
        <PolicyStat
          label={t('Sessions limit')}
          value={limitLabel(security.authSessionsLimit)}
        />
        <PolicyStat
          label={t('Users limit')}
          value={limitLabel(security.authLimit)}
        />
        <PolicyStat
          label={t('Password history')}
          value={
            security.authPasswordHistory > 0
              ? security.authPasswordHistory.toLocaleString()
              : t('Off')
          }
        />
        <PolicyStat
          label={t('Password dictionary')}
          value={toggleLabel(security.authPasswordDictionary)}
        />
        <PolicyStat
          label={t('Session alerts')}
          value={toggleLabel(security.authSessionAlerts)}
        />
        {freePlanUsers ? (
          <p className="col-span-2 rounded-lg border border-border/60 bg-muted/30 px-3 py-2.5 text-[12px] text-muted-foreground">
            {t('The Free plan includes {count} monthly active users.').replace(
              '{count}',
              freePlanUsers.toLocaleString(),
            )}{' '}
            <UpgradePlanLink
              orgId={project?.teamId}
              className="font-medium text-foreground underline hover:no-underline"
            >
              {t('Upgrade for more')}
            </UpgradePlanLink>
          </p>
        ) : null}
      </div>
      <div className="flex h-[68px] items-center justify-end border-t border-border bg-muted/30 px-6">
        <Button
          variant="secondary"
          size="sm"
          className="h-9 text-[13px]"
          asChild
        >
          <Link
            to="/projects/$projectId/auth/policies/sessions"
            params={{ projectId }}
          >
            {t('Configure policies')}
          </Link>
        </Button>
      </div>
    </div>
  )
}
