import { useMemo, useState } from 'react'
import { Link, useLocation, useParams } from '@tanstack/react-router'
import { Shield, X } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useScreenshotMode } from '@/components/global/providers/ScreenshotMode'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { canSeeProjectNavItem } from '@/lib/console-access-checks'
import {
  FIREWALL_MOVED_BANNER_ID,
  getConsoleBannerById,
  isConsoleBannerVisible,
  isFirewallMovedProject,
  isFullscreenWizardPath,
  useDebugConsoleBannerPreviews,
} from '@/lib/console-banners'
import { useT } from '@/lib/i18n/translate'
import {
  useDismissConsoleBanner,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { isConsoleBannerDismissed, type UserPrefs } from '@/lib/user-prefs-keys'

const FIREWALL_MOVED_BANNER = getConsoleBannerById(FIREWALL_MOVED_BANNER_ID)!

/**
 * Tells people who knew Firewall from the sidebar where it went: tabs on
 * functions, sites and project settings. Shown once per user, in projects old
 * enough to have had the sidebar entry.
 */
export function FirewallMovedBanner() {
  const t = useT()
  const location = useLocation()
  const { projectId } = useParams({ strict: false })
  const { account } = useAuth()
  const { isScreenshotModeActive } = useScreenshotMode()
  const { isPreviewEnabled } = useDebugConsoleBannerPreviews()
  const { project, projectData } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const dismissBanner = useDismissConsoleBanner()
  const [optimisticDismissed, setOptimisticDismissed] = useState(false)

  const prefs = (account as { prefs?: UserPrefs } | null | undefined)?.prefs
  const dismissedFromPrefs = useMemo(
    () => isConsoleBannerDismissed(prefs, FIREWALL_MOVED_BANNER_ID),
    [prefs],
  )
  const preview = isPreviewEnabled(FIREWALL_MOVED_BANNER_ID)
  const visible = isConsoleBannerVisible(FIREWALL_MOVED_BANNER, {
    preview,
    dismissed: optimisticDismissed || dismissedFromPrefs,
  })
  const eligible =
    preview ||
    (isFirewallMovedProject(project?.createdAt ?? projectData?.$createdAt) &&
      canSeeProjectNavItem(access, features, 'firewall'))

  if (
    !visible ||
    !eligible ||
    isScreenshotModeActive ||
    !projectId ||
    isFullscreenWizardPath(location.pathname)
  ) {
    return null
  }

  const dismiss = () => {
    setOptimisticDismissed(true)
    dismissBanner.mutate(FIREWALL_MOVED_BANNER_ID)
  }

  return (
    <div
      role="status"
      className="fixed bottom-4 start-4 z-50 w-[min(22rem,calc(100vw-2rem))] rounded-lg border border-border bg-background p-4 shadow-lg"
    >
      <div className="flex items-start gap-3">
        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted">
          <Shield className="h-4 w-4 text-muted-foreground" aria-hidden />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-[14px] font-semibold text-foreground">
            {t('Firewall has moved')}
          </p>
          <p className="mt-1 text-[13px] leading-relaxed text-muted-foreground">
            {t(
              'Firewall rules now sit next to what they protect: a Firewall tab on each function and site, and one in project settings for the API.',
            )}
          </p>
        </div>
        <button
          type="button"
          onClick={dismiss}
          disabled={dismissBanner.isPending}
          aria-label={t('Dismiss banner')}
          className="-me-1 -mt-1 flex h-7 w-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-50"
        >
          <X className="h-4 w-4" aria-hidden />
        </button>
      </div>
      <div className="mt-3 flex justify-end gap-2">
        <Button
          variant="outline"
          size="sm"
          className="h-8 text-[13px]"
          onClick={dismiss}
        >
          {t('Got it')}
        </Button>
        <Button size="sm" className="h-8 text-[13px]" asChild>
          <Link
            to="/projects/$projectId/settings/firewall"
            params={{ projectId }}
            onClick={dismiss}
            {...analyticsAttrs(FIREWALL_MOVED_BANNER.event)}
          >
            {t('Open API firewall')}
          </Link>
        </Button>
      </div>
    </div>
  )
}
