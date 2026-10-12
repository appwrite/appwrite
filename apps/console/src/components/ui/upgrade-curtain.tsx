import { ReactNode } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Lock } from 'lucide-react'
import {
  getOrgIdFromPathname,
  navigateToUpgradeWizard,
} from '@/lib/open-upgrade-wizard'
import { cn } from '@/lib/utils'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'

interface UpgradeCurtainProps {
  /**
   * Whether the feature is available (if false, shows the curtain)
   */
  isLocked: boolean
  /**
   * The content to overlay when locked
   */
  children: ReactNode
  /**
   * Optional organization ID for navigation to billing
   * If not provided, will try to get from URL params
   */
  orgId?: string | null
  /**
   * Optional custom title to display
   */
  title?: string
  /**
   * Optional custom message to display
   */
  message?: string
  /**
   * Optional CTA button label
   */
  ctaLabel?: string
  /**
   * Optional CTA click handler. Defaults to navigating to the upgrade wizard.
   */
  onCtaClick?: () => void
  /**
   * When false, hides the CTA button.
   */
  showCta?: boolean
  /**
   * Optional className for the curtain container
   */
  className?: string
}

/**
 * UpgradeCurtain Component
 *
 * A generic component that creates a "curtain" overlay above content
 * when a feature is locked, with a CTA to upgrade.
 *
 * Usage:
 * ```tsx
 * <UpgradeCurtain isLocked={!plan.supportsFeature} orgId={orgId}>
 *   <YourContent />
 * </UpgradeCurtain>
 * ```
 */
export function UpgradeCurtain({
  isLocked,
  children,
  orgId,
  title,
  message = 'This feature requires an upgrade to access.',
  ctaLabel,
  onCtaClick,
  showCta = true,
  className,
}: UpgradeCurtainProps) {
  const t = useT()
  const navigate = useNavigate()
  const resolvedTitle = title ?? t('Upgrade required')
  const resolvedCtaLabel = ctaLabel ?? t('Upgrade plan')

  // If not locked, just render children
  if (!isLocked) {
    return <>{children}</>
  }

  const handleUpgrade = () => {
    if (onCtaClick) {
      onCtaClick()
      return
    }
    navigateToUpgradeWizard(navigate, orgId ?? getOrgIdFromPathname())
  }

  return (
    <div className={cn('relative @container', className)}>
      {/* Content with blur and overlay */}
      <div
        className={cn(
          'transition-opacity',
          isLocked && 'opacity-40 pointer-events-none select-none blur-sm',
        )}
      >
        {children}
      </div>

      {/* Curtain overlay */}
      {isLocked && (
        <div className="absolute inset-0 flex items-center justify-center bg-background/20 backdrop-blur-lg z-10 p-1.5 @[200px]:p-2 @[300px]:p-3 @[400px]:p-4 @[500px]:p-6 overflow-hidden">
          <div className="flex flex-col items-center justify-center gap-1.5 @[200px]:gap-2 @[300px]:gap-2.5 @[400px]:flex-row @[400px]:gap-3 @[500px]:gap-4 w-full max-w-full @[400px]:max-w-lg max-h-full">
            {/* Icon - hidden on very small containers */}
            <div className="hidden @[200px]:flex shrink-0">
              <div className="flex h-6 w-6 @[250px]:h-7 @[250px]:w-7 @[300px]:h-8 @[300px]:w-8 @[400px]:h-10 @[400px]:w-10 @[500px]:h-12 @[500px]:w-12 items-center justify-center rounded-full bg-muted">
                <Lock className="h-3 w-3 @[250px]:h-3.5 @[250px]:w-3.5 @[300px]:h-4 @[300px]:w-4 @[400px]:h-5 @[400px]:w-5 @[500px]:h-6 @[500px]:w-6 text-muted-foreground" />
              </div>
            </div>

            {/* Content */}
            <div className="flex-1 text-center @[400px]:text-start space-y-0.5 @[200px]:space-y-1 @[300px]:space-y-1.5 @[400px]:space-y-2 min-w-0">
              <h4 className="text-[12px] @[200px]:text-[13px] @[250px]:text-[14px] @[300px]:text-[15px] font-semibold text-foreground leading-tight">
                {resolvedTitle}
              </h4>
              <p className="text-[10px] @[200px]:text-[11px] @[250px]:text-[12px] @[300px]:text-[13px] text-muted-foreground line-clamp-1 @[300px]:line-clamp-2 leading-tight">
                {message}
              </p>
            </div>

            {/* Button */}
            {showCta ? (
              <div className="flex shrink-0 w-full @[400px]:w-auto">
                <Button
                  size="sm"
                  className="h-6 @[200px]:h-7 @[250px]:h-8 @[300px]:h-9 text-[10px] @[200px]:text-[11px] @[250px]:text-[12px] @[300px]:text-[13px] w-full @[400px]:w-auto px-2 @[200px]:px-3 @[250px]:px-4"
                  onClick={handleUpgrade}
                  {...analyticsAttrs('upgrade-clicked')}
                >
                  {resolvedCtaLabel}
                </Button>
              </div>
            ) : null}
          </div>
        </div>
      )}
    </div>
  )
}
