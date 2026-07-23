import { ReactNode, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Bell, Check } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useToggleFeatureNotification } from '@/lib/react-query/hooks'
import { USER_PREFS_KEY_FEATURE_NOTIFICATIONS } from '@/lib/user-prefs-keys'
import { toast } from 'sonner'

interface ComingSoonCurtainProps {
  /**
   * The content to overlay
   */
  children: ReactNode
  /**
   * Unique feature ID for tracking notifications
   */
  featureId: string
  /**
   * Optional custom message to display
   */
  message?: string
  /**
   * Optional className for the curtain container
   */
  className?: string
}

/**
 * ComingSoonCurtain Component
 *
 * A component that creates a "curtain" overlay above content for features
 * that are coming soon, with a CTA to get notified when available.
 *
 * When clicked, it saves the feature ID in the user's preferences under
 * the featureNotifications key (see USER_PREFS_KEY_FEATURE_NOTIFICATIONS).
 *
 * Usage:
 * ```tsx
 * <ComingSoonCurtain featureId="database-insights">
 *   <YourContent />
 * </ComingSoonCurtain>
 * ```
 */
export function ComingSoonCurtain({
  children,
  featureId,
  message = 'This feature is coming soon. Get notified when it launches.',
  className,
}: ComingSoonCurtainProps) {
  const { account } = useAuth()
  const toggleNotification = useToggleFeatureNotification()
  const [isAnimating, setIsAnimating] = useState(false)

  // Check if user has already requested notification for this feature
  const featureNotificationsRaw =
    account?.prefs?.[USER_PREFS_KEY_FEATURE_NOTIFICATIONS]

  // Handle different data types (string, array, or undefined)
  let featureNotifications: string[] = []
  if (typeof featureNotificationsRaw === 'string') {
    featureNotifications = featureNotificationsRaw
      ? featureNotificationsRaw.split(',').filter(Boolean)
      : []
  } else if (Array.isArray(featureNotificationsRaw)) {
    // Handle legacy array format
    featureNotifications = featureNotificationsRaw
  }

  const isNotifying = featureNotifications.includes(featureId)

  const handleNotifyClick = async () => {
    if (!account) {
      toast.error('Please sign in to get notified')
      return
    }

    setIsAnimating(true)
    try {
      await toggleNotification.mutateAsync(featureId)

      if (isNotifying) {
        toast.success(
          'You will no longer receive notifications for this feature',
        )
      } else {
        toast.success('You will be notified when this feature launches!')
      }
    } catch (error) {
      toast.error('Failed to update notification preferences')
    } finally {
      // Keep animation state for a bit to show visual feedback
      setTimeout(() => setIsAnimating(false), 300)
    }
  }

  return (
    <div
      className={cn(
        'relative @container max-h-[600px] overflow-hidden',
        className,
      )}
    >
      {/* Content with blur and overlay */}
      <div className="opacity-40 pointer-events-none select-none blur-sm">
        {children}
      </div>

      {/* Curtain overlay - edges fade into background via soft radial mask */}
      <div
        className="absolute inset-0 flex items-center justify-center bg-background/20 backdrop-blur-lg z-10 p-1.5 @[200px]:p-2 @[300px]:p-3 @[400px]:p-4 @[500px]:p-6 overflow-hidden"
        style={{
          maskImage:
            'radial-gradient(ellipse 120% 120% at 50% 50%, black 0%, black 22%, transparent 72%)',
          WebkitMaskImage:
            'radial-gradient(ellipse 120% 120% at 50% 50%, black 0%, black 22%, transparent 72%)',
        }}
      >
        <div className="flex flex-col items-center justify-center gap-1.5 @[200px]:gap-2 @[300px]:gap-2.5 @[400px]:flex-row @[400px]:gap-3 @[500px]:gap-4 w-full max-w-full @[400px]:max-w-lg max-h-full">
          {/* Icon - hidden on very small containers */}
          <div className="hidden @[200px]:flex shrink-0">
            <div className="flex h-6 w-6 @[250px]:h-7 @[250px]:w-7 @[300px]:h-8 @[300px]:w-8 @[400px]:h-10 @[400px]:w-10 @[500px]:h-12 @[500px]:w-12 items-center justify-center rounded-full bg-muted">
              <Bell className="h-3 w-3 @[250px]:h-3.5 @[250px]:w-3.5 @[300px]:h-4 @[300px]:w-4 @[400px]:h-5 @[400px]:w-5 @[500px]:h-6 @[500px]:w-6 text-muted-foreground" />
            </div>
          </div>

          {/* Content */}
          <div className="flex-1 text-center @[400px]:text-start space-y-0.5 @[200px]:space-y-1 @[300px]:space-y-1.5 @[400px]:space-y-2 min-w-0">
            <h4 className="text-[12px] @[200px]:text-[13px] @[250px]:text-[14px] @[300px]:text-[15px] font-semibold text-foreground leading-tight">
              Coming soon
            </h4>
            <p className="text-[10px] @[200px]:text-[11px] @[250px]:text-[12px] @[300px]:text-[13px] text-muted-foreground line-clamp-1 @[300px]:line-clamp-2 leading-tight">
              {message}
            </p>
          </div>

          {/* Button */}
          <div className="flex shrink-0 w-full @[400px]:w-auto">
            <Button
              size="sm"
              variant={isNotifying ? 'outline' : 'default'}
              className={cn(
                'h-6 @[200px]:h-7 @[250px]:h-8 @[300px]:h-9 text-[10px] @[200px]:text-[11px] @[250px]:text-[12px] @[300px]:text-[13px] w-full @[400px]:w-auto px-2 @[200px]:px-3 @[250px]:px-4 gap-1.5',
                isAnimating && 'scale-95 transition-transform',
              )}
              onClick={handleNotifyClick}
              disabled={toggleNotification.isPending}
            >
              {isNotifying ? (
                <>
                  <Check className="h-3 w-3 @[300px]:h-3.5 @[300px]:w-3.5" />
                  Notifying
                </>
              ) : (
                'Notify me'
              )}
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}
