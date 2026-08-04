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
import type { LucideIcon } from 'lucide-react'
import { AlertTriangle, CloudOff, PlugZap, RefreshCw } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
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

/**
 * A dead token is the only kind the user must act on: it leads with the
 * reconnect and is the only one worth alarming red. The other two clear on
 * their own or on a retry, and dressing those as failures trains users to
 * ignore the one state that actually needs them.
 */
function isReconnectKind(kind: VcsInstallationErrorKind) {
  return kind === 'reconnect'
}

function useVcsInstallationErrorCopy(kind: VcsInstallationErrorKind): {
  title: string
  description: string
  icon: LucideIcon
} {
  const t = useT()

  if (kind === 'reconnect') {
    return {
      title: t('Reconnect this Git installation'),
      description: t(
        'Appwrite can no longer access this Git provider on your behalf. Reconnect the installation to restore access to your repositories.',
      ),
      icon: AlertTriangle,
    }
  }

  if (kind === 'locked') {
    return {
      title: t('This installation is being refreshed'),
      description: t(
        'Another request is already refreshing this installation. Try again in a moment.',
      ),
      icon: RefreshCw,
    }
  }

  return {
    title: t('Could not reach the Git provider'),
    description: t('This is usually temporary. Try again in a moment.'),
    icon: CloudOff,
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
}: Pick<
  VcsInstallationErrorProps,
  'kind' | 'reconnectUrl' | 'onRetry' | 'isRetrying'
>) {
  const t = useT()
  const reconnectIsPrimary = isReconnectKind(kind)

  // A locked installation is healthy, it is just mid-refresh. Offering a
  // reconnect there would send the user to re-authorize for no reason.
  const reconnect =
    reconnectUrl && kind !== 'locked' ? (
      <Button
        key="reconnect"
        asChild
        size="sm"
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
      size="sm"
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
  const { title, description, icon } = useVcsInstallationErrorCopy(kind)

  return (
    <EmptyState
      icon={icon}
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
  const { title, description, icon: Icon } = useVcsInstallationErrorCopy(kind)

  const body = (
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
  )

  if (isReconnectKind(kind)) {
    return (
      <WarningAlert title={title} icon={Icon} className={className}>
        {body}
      </WarningAlert>
    )
  }

  return (
    <Alert className={className}>
      <Icon className="h-4 w-4 text-muted-foreground" />
      <AlertTitle className="text-[13px] font-medium text-foreground">
        {title}
      </AlertTitle>
      <AlertDescription className="mt-2 text-[13px] leading-relaxed text-muted-foreground">
        {body}
      </AlertDescription>
    </Alert>
  )
}
