import { AlertCircle, CheckCircle2, Loader2, RefreshCw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import type { DistributionTarget } from '@/lib/appwrite/distribution'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  checkKey,
  type TargetCheck,
  type TargetSelection,
  type TargetsState,
} from '@/lib/distribution/publish'
import { useT } from '@/lib/i18n/translate'
import { CheckIssues } from '../../_components/CheckIssues'
import { PlatformIcon, platformLabel } from '../../_components/platform'
import { trackLabel } from '../../_components/target-options'

const STATE_HINTS: Partial<Record<TargetsState, string>> = {
  empty: 'Select at least one target to publish.',
  unchecked: 'Run the checks to continue.',
  failed: 'Some checks could not run. Run them again to continue.',
  blocked: 'Resolve the blockers or deselect those targets to continue.',
}

type PublishTargetsProps = {
  targets: DistributionTarget[]
  selections: Record<string, TargetSelection>
  tracks: Record<string, string>
  checks: Record<string, TargetCheck>
  state: TargetsState
  onChange: (targetId: string, changes: Partial<TargetSelection>) => void
  onRunChecks: () => void
}

export function PublishTargets({
  targets,
  selections,
  tracks,
  checks,
  state,
  onChange,
  onRunChecks,
}: PublishTargetsProps) {
  const t = useT()
  const checking = state === 'checking'
  const hint = STATE_HINTS[state]

  return (
    <div className="space-y-4">
      <ul className="divide-y divide-border rounded-lg border border-border">
        {targets.map((target) => {
          const selection = selections[target.$id]
          const id = `publish-target-${target.platform}`
          const track = tracks[target.$id]
          return (
            <li
              key={target.$id}
              data-testid={id}
              className="space-y-3 px-4 py-3"
            >
              <div className="flex flex-wrap items-center gap-3">
                <Checkbox
                  id={id}
                  checked={selection.selected}
                  onCheckedChange={(checked) =>
                    onChange(target.$id, { selected: checked === true })
                  }
                  disabled={checking}
                />
                <Label
                  htmlFor={id}
                  className="flex min-w-0 flex-1 cursor-pointer items-center gap-2 text-[13px] font-medium"
                >
                  <PlatformIcon platform={target.platform} />
                  <span>{platformLabel(target.platform)}</span>
                  <span
                    dir="ltr"
                    className="truncate font-mono text-[12px] font-normal text-muted-foreground"
                  >
                    {target.identifier}
                  </span>
                </Label>
                <p
                  data-testid={`${id}-track`}
                  className="flex shrink-0 items-center gap-1.5 text-[12px]"
                >
                  <span className="text-muted-foreground">{t('Track')}</span>
                  <span className="font-medium text-foreground">
                    {t(trackLabel(track))}
                  </span>
                </p>
              </div>
              {target.connectionId ? null : (
                <p className="ps-7 text-[12px] text-muted-foreground">
                  {t('This target has no store connection.')}
                </p>
              )}
              <CheckResult check={checks[checkKey(target.$id, track)]} />
            </li>
          )
        })}
      </ul>
      <p className="text-[12px] text-muted-foreground">
        {t(
          'The checks use the track shown for each target. Choose the track for an artifact in the Source step.',
        )}
      </p>
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 gap-1.5 text-[13px]"
          onClick={onRunChecks}
          disabled={checking || state === 'empty'}
          {...analyticsAttrs('distribution-run-checks')}
        >
          <RefreshCw className="h-3.5 w-3.5" />
          {t('Run checks')}
        </Button>
        {hint ? (
          <p className="text-[12px] text-muted-foreground">{t(hint)}</p>
        ) : null}
      </div>
    </div>
  )
}

function CheckResult({ check }: { check: TargetCheck | undefined }) {
  const t = useT()
  if (!check) return null
  if (check.status === 'running') {
    return (
      <p className="flex items-center gap-1.5 ps-7 text-[13px] text-muted-foreground">
        <Loader2 className="h-3.5 w-3.5 animate-spin" />
        {t('Running checks...')}
      </p>
    )
  }
  if (check.status === 'failed') {
    return (
      <p className="flex items-start gap-1.5 ps-7 text-[13px] text-destructive">
        <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
        <span className="break-words">{check.error}</span>
      </p>
    )
  }
  if (check.check.issues.length === 0) {
    return (
      <p className="flex items-center gap-1.5 ps-7 text-[13px] text-foreground">
        <CheckCircle2 className="h-3.5 w-3.5 text-green-600" />
        {t('Ready to submit')}
      </p>
    )
  }
  return (
    <div className="ms-7 overflow-hidden rounded-lg border border-border">
      <CheckIssues issues={check.check.issues} />
    </div>
  )
}
