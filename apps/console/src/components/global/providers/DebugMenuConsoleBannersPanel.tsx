import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { RotateCcw } from 'lucide-react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { useConsoleImpersonationRevision } from '@/hooks/use-console-impersonation-revision'
import { ANALYTICS_ACTIONS } from '@/lib/analytics-actions'
import {
  CONSOLE_BANNERS,
  formatConsoleBannerUtcRange,
  getConsoleBannerScheduleStatus,
  type ConsoleBannerScheduleStatus,
} from '@/lib/console-banners/catalog'
import { clearGuestConsoleBannerDismiss } from '@/lib/console-banners/guest-dismissed-banners'
import {
  clearAllDebugConsoleBannerPreviews,
  useDebugConsoleBannerPreviews,
} from '@/lib/console-banners/debug-preview'
import {
  CONSOLE_ACCOUNT_STALE_TIME_MS,
  fetchConsoleAccount,
  useClearConsoleBannerDismissal,
} from '@/lib/react-query/hooks'
import {
  isConsoleBannerDismissed,
  USER_PREFS_KEY_DISMISSED_BANNERS,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import { cn } from '@/lib/utils'

const SCHEDULE_BADGE_VARIANT: Record<
  ConsoleBannerScheduleStatus,
  'info' | 'success' | 'warning'
> = {
  upcoming: 'info',
  active: 'success',
  expired: 'warning',
}

function scheduleStatusLabel(status: ConsoleBannerScheduleStatus): string {
  switch (status) {
    case 'upcoming':
      return 'Upcoming'
    case 'active':
      return 'Active'
    case 'expired':
      return 'Expired'
  }
}

export function DebugMenuConsoleBannersPanel() {
  const consoleImpersonationRevision = useConsoleImpersonationRevision()
  const { isPreviewEnabled, setPreviewEnabled } = useDebugConsoleBannerPreviews()
  const clearDismissal = useClearConsoleBannerDismissal()

  const { data: account, refetch: refetchAccount } = useQuery({
    queryKey: ['account', 'console', consoleImpersonationRevision],
    queryFn: () =>
      fetchConsoleAccount({ revision: consoleImpersonationRevision }),
    staleTime: CONSOLE_ACCOUNT_STALE_TIME_MS,
  })

  const accountPrefs = account?.prefs as UserPrefs | undefined
  const dismissedIds = useMemo(
    () =>
      CONSOLE_BANNERS.filter((banner) =>
        isConsoleBannerDismissed(accountPrefs, banner.id),
      ).map((banner) => banner.id),
    [accountPrefs],
  )

  const handleResetDismiss = async (bannerId: string) => {
    clearGuestConsoleBannerDismiss(bannerId)
    try {
      if (account) {
        await clearDismissal.mutateAsync(bannerId)
        await refetchAccount()
      }
      toast.success('Banner dismiss reset')
    } catch {
      toast.error('Failed to reset banner dismiss')
    }
  }

  const handleClearPreviews = () => {
    clearAllDebugConsoleBannerPreviews()
    toast.success('Banner previews cleared')
  }

  return (
    <div className="space-y-4 px-1 py-1" aria-label="Console banners">
      <div className="space-y-2 rounded-lg px-3 py-2.5">
        <p className="text-[13px] font-medium text-foreground">Console banners</p>
        <p className="text-[11px] leading-relaxed text-[var(--network-globe-edge)]/80">
          Scheduled promo banners (header strips and bottom-left cards). Preview
          ignores schedule and dismiss prefs. Dismiss reset clears{' '}
          <code className="rounded bg-muted px-1 py-0.5 text-[10px]">
            {USER_PREFS_KEY_DISMISSED_BANNERS}
          </code>{' '}
          for the selected banner.
        </p>
      </div>

      <div className="space-y-3">
        {CONSOLE_BANNERS.map((banner) => {
          const scheduleStatus = getConsoleBannerScheduleStatus(banner)
          const dismissed = dismissedIds.includes(banner.id)
          const previewOn = isPreviewEnabled(banner.id)

          return (
            <div
              key={banner.id}
              className="space-y-3 rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/20 px-3 py-3"
            >
              <div className="space-y-1">
                <p className="text-[13px] font-medium text-foreground">
                  {banner.title}
                </p>
                <p className="font-mono text-[10px] text-[var(--network-globe-edge)]/70">
                  {banner.id}
                </p>
              </div>

              <div className="flex flex-wrap items-center gap-2">
                <Badge
                  variant={SCHEDULE_BADGE_VARIANT[scheduleStatus]}
                  className="text-[10px] shrink-0"
                >
                  {scheduleStatusLabel(scheduleStatus)}
                </Badge>
                <Badge
                  variant={dismissed ? 'warning' : 'success'}
                  className="text-[10px] shrink-0"
                >
                  {dismissed ? 'Dismissed' : 'Not dismissed'}
                </Badge>
                <Badge variant="info" className="text-[10px] shrink-0">
                  {banner.scope}
                </Badge>
                {banner.cloudOnly ? (
                  <Badge variant="info" className="text-[10px] shrink-0">
                    Cloud only
                  </Badge>
                ) : null}
                {banner.placement ? (
                  <Badge variant="info" className="text-[10px] shrink-0">
                    {banner.placement}
                  </Badge>
                ) : null}
                {banner.cardSize === 'large' ? (
                  <Badge variant="info" className="text-[10px] shrink-0">
                    Large card
                  </Badge>
                ) : null}
                {banner.cardAspectRatio === 'square' ? (
                  <Badge variant="info" className="text-[10px] shrink-0">
                    1:1 card
                  </Badge>
                ) : null}
              </div>

              <p className="text-[11px] text-[var(--network-globe-edge)]/80">
                {formatConsoleBannerUtcRange(banner)}
              </p>

              <div className="space-y-0.5">
                <p className="text-[10px] uppercase tracking-wide text-[var(--network-globe-edge)]/60">
                  Event
                </p>
                <p className="font-mono text-[10px] text-[var(--network-globe-edge)]/80">
                  {banner.event}
                </p>
                <p className="text-[11px] text-foreground">
                  {ANALYTICS_ACTIONS[banner.event]}
                </p>
              </div>

              <div className="flex items-center justify-between gap-3 rounded-md border border-[color-mix(in_srgb,var(--network-globe-edge)_15%,var(--border))] bg-background/60 px-3 py-2">
                <div className="min-w-0">
                  <p className="text-[12px] font-medium text-foreground">
                    Preview
                  </p>
                  <p className="text-[10px] text-[var(--network-globe-edge)]/70">
                    Force-show regardless of schedule
                  </p>
                </div>
                <Switch
                  checked={previewOn}
                  onCheckedChange={(checked) =>
                    setPreviewEnabled(banner.id, checked)
                  }
                  aria-label={`Preview ${banner.title}`}
                />
              </div>

              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 w-full text-[12px]"
                disabled={!dismissed || clearDismissal.isPending}
                onClick={() => void handleResetDismiss(banner.id)}
              >
                <RotateCcw className="mr-1.5 h-3.5 w-3.5" />
                Reset dismiss
              </Button>
            </div>
          )
        })}
      </div>

      <Button
        type="button"
        variant="ghost"
        size="sm"
        className={cn(
          'h-8 w-full text-[12px] text-muted-foreground',
          CONSOLE_BANNERS.length === 0 && 'hidden',
        )}
        onClick={handleClearPreviews}
      >
        Clear all previews
      </Button>
    </div>
  )
}
