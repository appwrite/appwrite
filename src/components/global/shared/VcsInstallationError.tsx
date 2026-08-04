/**
 * Shared rendering for a VCS call that failed because of the installation
 * itself, rather than because the repository/branch/directory is genuinely
 * empty.
 *
 * Without this, every one of these failures reads as a successful empty
 * result ("No repositories found", "No branches available", a spinner that
 * never resolves), which is the opposite of what happened.
 *
 * Two layouts, same copy:
 * - `VcsInstallationErrorState` for list positions (replaces an EmptyState).
 * - `VcsInstallationErrorAlert` for form and card positions (inline).
 */

import type { ReactNode } from 'react'
import { AlertTriangle, PlugZap, RefreshCw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { WarningAlert } from '@/components/global/shared/WarningAlert'
import { getKnownVcsProvider } from '@/lib/vcs/providers'
import type { VcsInstallationErrorKind } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export interface VcsInstallationErrorProps {
  kind: VcsInstallationErrorKind
  /** Installation provider (`github`, `gitlab`); unknown values hide the reconnect link. */
  provider?: string
  /** Installation owner, shown so the user knows which account to reconnect. */
  organization?: string
  /**
   * OAuth authorize URL for this provider in `update` mode. Reconnecting is a
   * full-page redirect to the provider, so this is an anchor, never a fetch.
   */
  reconnectUrl?: string
  /** Retry the failed request. Shown as the primary action for transient kinds. */
  onRetry?: () => void
  isRetrying?: boolean
  className?: string
}

type VcsInstallationErrorCopy = {
  title: string
  description: string
  /** Reconnect is primary only when the token is what broke. */
  reconnectIsPrimary: boolean
}

function useVcsInstallationErrorCopy(
  kind: VcsInstallationErrorKind,
): VcsInstallationErrorCopy {
  const t = useT()

  if (kind === 'reconnect') {
    return {
      title: t('Reconnect this Git installation'),
      description: t(
        'Appwrite can no longer access this Git provider on your behalf. Reconnect the installation to restore access to your repositories.',
      ),
      reconnectIsPrimary: true,
    }
  }

  if (kind === 'locked') {
    return {
      title: t('This installation is being refreshed'),
      description: t(
        'Another request is already refreshing this installation. Try again in a moment.',
      ),
      reconnectIsPrimary: false,
    }
  }

  return {
    title: t('Could not reach the Git provider'),
    description: t('This is usually temporary. Try again in a moment.'),
    reconnectIsPrimary: false,
  }
}

/**
 * Which account is affected. Deliberately not translated: these are a provider
 * name and an owner handle, both proper nouns.
 */
function VcsInstallationIdentity({
  provider,
  organization,
  className,
}: {
  provider?: string
  organization?: string
  className?: string
}) {
  const providerLabel = getKnownVcsProvider(provider)?.label
  const parts = [providerLabel, organization].filter(Boolean)
  if (parts.length === 0) return null

  return (
    <p className={cn('text-[12px] text-muted-foreground', className)}>
      {parts.join(' / ')}
    </p>
  )
}

/**
 * Reconnect anchor plus retry button, ordered by which one actually fixes the
 * current failure. Reconnect is omitted for providers we cannot build an
 * authorize URL for, rather than pointing at a fabricated one.
 */
function VcsInstallationErrorActions({
  kind,
  reconnectUrl,
  onRetry,
  isRetrying,
  size = 'sm',
}: Pick<
  VcsInstallationErrorProps,
  'kind' | 'reconnectUrl' | 'onRetry' | 'isRetrying'
> & { size?: 'sm' | 'default' }) {
  const t = useT()
  const { reconnectIsPrimary } = useVcsInstallationErrorCopy(kind)

  // A locked installation is healthy, it is just mid-refresh. Offering a
  // reconnect there would send the user to re-authorize for no reason.
  const reconnect =
    reconnectUrl && kind !== 'locked' ? (
      <Button
        key="reconnect"
        asChild
        size={size}
        variant={reconnectIsPrimary ? 'default' : 'outline'}
        className="text-[13px]"
      >
        <a href={reconnectUrl}>
          <PlugZap className="me-1.5 h-3.5 w-3.5" />
          {t('Reconnect installation')}
        </a>
      </Button>
    ) : null

  const retry = onRetry ? (
    <Button
      key="retry"
      type="button"
      size={size}
      variant={reconnectIsPrimary ? 'outline' : 'default'}
      onClick={onRetry}
      disabled={isRetrying}
      className="text-[13px]"
    >
      <RefreshCw
        className={cn('me-1.5 h-3.5 w-3.5', isRetrying && 'animate-spin')}
      />
      {t('Try again')}
    </Button>
  ) : null

  const actions: ReactNode[] = reconnectIsPrimary
    ? [reconnect, retry]
    : [retry, reconnect]
  const visible = actions.filter(Boolean)
  if (visible.length === 0) return null

  return <>{visible}</>
}

/**
 * Block layout for list positions - use anywhere an EmptyState would otherwise
 * claim the list is empty.
 */
export function VcsInstallationErrorState({
  kind,
  provider,
  organization,
  reconnectUrl,
  onRetry,
  isRetrying,
  className,
}: VcsInstallationErrorProps) {
  const { title, description } = useVcsInstallationErrorCopy(kind)

  return (
    <EmptyState
      icon={AlertTriangle}
      title={title}
      description={description}
      className={className}
      action={
        <div className="flex flex-col items-center gap-3">
          <VcsInstallationIdentity
            provider={provider}
            organization={organization}
          />
          <div className="flex flex-wrap justify-center gap-2">
            <VcsInstallationErrorActions
              kind={kind}
              reconnectUrl={reconnectUrl}
              onRetry={onRetry}
              isRetrying={isRetrying}
            />
          </div>
        </div>
      }
    />
  )
}

/**
 * Inline layout for form and card positions, where the surrounding controls
 * stay on screen and only need to be explained.
 */
export function VcsInstallationErrorAlert({
  kind,
  provider,
  organization,
  reconnectUrl,
  onRetry,
  isRetrying,
  className,
  children,
}: VcsInstallationErrorProps & {
  /** Replaces the default description, e.g. to name the affected control. */
  children?: ReactNode
}) {
  const { title, description } = useVcsInstallationErrorCopy(kind)

  return (
    <WarningAlert title={title} className={className}>
      <div className="flex flex-col gap-3">
        <span>{children ?? description}</span>
        <VcsInstallationIdentity
          provider={provider}
          organization={organization}
        />
        <div className="flex flex-wrap gap-2">
          <VcsInstallationErrorActions
            kind={kind}
            reconnectUrl={reconnectUrl}
            onRetry={onRetry}
            isRetrying={isRetrying}
          />
        </div>
      </div>
    </WarningAlert>
  )
}
