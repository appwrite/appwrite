import { useMemo } from 'react'
import type { Models } from '@appwrite.io/console'
import { GitCommitHorizontal, Terminal, Upload } from 'lucide-react'
import {
  SearchableSelect,
  type SearchableSelectItem,
} from '@/components/global/shared/SearchableSelect'
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

/** How the deployment was made, for deployments without a commit message. */
function sourceLabel(type: string | undefined): string {
  if (type === 'cli') return 'CLI deployment'
  if (type === 'manual') return 'Manual deployment'
  return 'Deployment'
}

function sourceIcon(deployment: Models.Deployment) {
  if (deployment.type === 'cli') return Terminal
  if (deployment.type === 'manual') return Upload
  return GitCommitHorizontal
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

  // Keep a value from the URL selectable even when it is not among the newest
  const options = useMemo(
    () =>
      value && !deployments.some((deployment) => deployment.$id === value)
        ? [{ $id: value } as Models.Deployment, ...deployments]
        : deployments,
    [value, deployments],
  )

  const items = useMemo<SearchableSelectItem[]>(
    () =>
      options.map((deployment) => {
        const commit = deployment.providerCommitMessage?.split('\n')[0].trim()
        const label = commit || t(sourceLabel(deployment.type))
        const status = deployment.status
          ? t(
              deployment.status.charAt(0).toUpperCase() +
                deployment.status.slice(1),
            )
          : undefined
        return {
          value: deployment.$id,
          label,
          description: [
            deployment.$id,
            deployment.$id === activeDeploymentId ? t('Active') : status,
            deployment.$createdAt
              ? getRelativeTimeString(deployment.$createdAt)
              : undefined,
          ]
            .filter(Boolean)
            .join(' · '),
          searchText: [
            deployment.providerCommitMessage || label,
            deployment.$id,
            deployment.providerBranch,
            deployment.providerCommitHash,
          ]
            .filter(Boolean)
            .join(' '),
          icon: sourceIcon(deployment),
        }
      }),
    [t, options, activeDeploymentId],
  )

  return (
    <SearchableSelect
      value={value ?? ''}
      onValueChange={onChange}
      items={items}
      disabled={disabled}
      ariaLabel={t('Deployment')}
      placeholder="Select deployment"
      searchPlaceholder="Search deployments"
      emptyMessage="No deployments found"
      // A deployment from the URL that is not among the newest is shown by id
      selectedName={value}
      triggerClassName="w-[240px] max-w-full"
      contentClassName="w-[320px] max-w-[calc(100vw-2rem)]"
    />
  )
}
