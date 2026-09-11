import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  downgradeAddonsQueryOptions,
  getUnsupportedAddonRemovals,
} from '@/lib/billing/downgrade-addons'
import { useT } from '@/lib/i18n/translate'

interface DowngradeAddonWarningProps {
  organizationId: string
  targetPlan: Record<string, unknown> | null | undefined
}

export function DowngradeAddonWarning({
  organizationId,
  targetPlan,
}: DowngradeAddonWarningProps) {
  const t = useT()

  const { data: snapshot, error } = useQuery(
    downgradeAddonsQueryOptions(organizationId),
  )

  const removals = useMemo(
    () => getUnsupportedAddonRemovals(snapshot, targetPlan),
    [snapshot, targetPlan],
  )

  if (error || removals.length === 0) return null

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Addons not available on the selected plan')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'These addons will be disabled as part of this plan change. You do not need to turn them off first.',
          )}
        </p>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4 space-y-4">
        <ul className="space-y-1.5">
          {removals.map((removal) => (
            <li
              key={`${removal.scope}-${removal.resourceId}-${removal.key}`}
              className="flex items-start justify-between gap-3 text-[13px] leading-normal"
            >
              <span className="text-foreground">{removal.label}</span>
              {removal.projectName ? (
                <span className="shrink-0 text-muted-foreground">
                  {removal.projectName}
                </span>
              ) : null}
            </li>
          ))}
        </ul>

        <p className="text-[13px] text-muted-foreground">
          {t(
            'They stay active until the end of your current billing cycle, then they are removed.',
          )}
        </p>
      </div>
    </div>
  )
}
