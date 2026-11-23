import type { Models } from '@appwrite.io/console'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { getRelativeTimeString } from '@/components/global/shared/DateTooltip'
import { useT } from '@/lib/i18n/translate'

interface DeploymentSelectProps {
  deployments: Models.Deployment[]
  /** The resource's live deployment, marked in the list. */
  activeDeploymentId?: string
  value: string | undefined
  onChange: (deploymentId: string) => void
  disabled?: boolean
}

/** Picks one of a function's or site's deployments, newest first. */
export function DeploymentSelect({
  deployments,
  activeDeploymentId,
  value,
  onChange,
  disabled,
}: DeploymentSelectProps) {
  const t = useT()
  // Keep a value from the URL selectable even when it is not on the first page.
  const options =
    value && !deployments.some((deployment) => deployment.$id === value)
      ? [{ $id: value } as Models.Deployment, ...deployments]
      : deployments

  return (
    <Select value={value} onValueChange={onChange} disabled={disabled}>
      <SelectTrigger
        className="h-9 w-[340px] max-w-full text-[13px]"
        aria-label={t('Deployment')}
      >
        <SelectValue placeholder={t('Select deployment')} />
      </SelectTrigger>
      <SelectContent>
        {options.map((deployment) => {
          const commit = deployment.providerCommitMessage?.split('\n')[0]
          return (
            <SelectItem key={deployment.$id} value={deployment.$id}>
              <span className="font-mono">{deployment.$id}</span>
              {deployment.$id === activeDeploymentId && (
                <span className="ms-2 text-emerald-600 dark:text-emerald-400">
                  {t('Active')}
                </span>
              )}
              {deployment.$createdAt && (
                <span className="ms-2 text-muted-foreground">
                  {commit || getRelativeTimeString(deployment.$createdAt)}
                </span>
              )}
            </SelectItem>
          )
        })}
      </SelectContent>
    </Select>
  )
}
