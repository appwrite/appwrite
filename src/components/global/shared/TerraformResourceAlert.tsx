import { AlertTriangle } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { TerraformIcon } from '@/components/global/shared/TerraformIcon'
import { useTerraformResourceOnMount } from '@/lib/react-query/hooks/terraform'
import { getUserAgentClient } from '@/lib/terraform/activity'
import type { TerraformDrift } from '@/lib/terraform/state'
import { useT } from '@/lib/i18n/translate'

type TerraformResourceAlertProps = {
  projectId: string | null | undefined
  /** Activity resource path, e.g. `function/api`. */
  resource: string | null | undefined
}

function DriftSource({ drift }: { drift: TerraformDrift }) {
  const client =
    drift.actorType === 'admin' ? null : getUserAgentClient(drift.userAgent)
  return (
    <>
      <span className="font-mono">{drift.event}</span>
      {' · '}
      {drift.actorName}
      {client ? <span className="font-mono"> ({client})</span> : null}
      {' · '}
      <DateTooltip date={drift.time} />
    </>
  )
}

/** Banner for resource pages: managed by Terraform, and whether it has drifted. */
export function TerraformResourceAlert({
  projectId,
  resource,
}: TerraformResourceAlertProps) {
  const t = useT()
  const managed = useTerraformResourceOnMount(projectId, resource)
  if (!managed) return null

  if (managed.drift) {
    return (
      <div className="border-b border-border bg-amber-500/5">
        <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
          <Alert
            variant="default"
            className="border-amber-500/30 bg-transparent"
          >
            <AlertTriangle className="h-4 w-4 text-amber-500" />
            <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
              {t('Changed outside Terraform')}
            </AlertTitle>
            <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
              <span>
                {t(
                  'This resource is managed by Terraform, but it changed after the last apply. Run terraform plan to see what differs from your configuration.',
                )}
              </span>
              <span className="block">
                {t('Last change:')} <DriftSource drift={managed.drift} />
              </span>
            </AlertDescription>
          </Alert>
        </div>
      </div>
    )
  }

  return (
    <div className="border-b border-border bg-muted/30">
      <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
        <Alert variant="default" className="border-border bg-transparent">
          <TerraformIcon
            variant="mark"
            className="h-4 w-4 text-violet-600 dark:text-violet-400"
          />
          <AlertTitle className="text-[13px] font-medium">
            {t('Managed by Terraform')}
          </AlertTitle>
          <AlertDescription className="text-[12px] text-muted-foreground">
            {t(
              'Changes you make here are not in your Terraform configuration. The next terraform apply may revert them.',
            )}
          </AlertDescription>
        </Alert>
      </div>
    </div>
  )
}
