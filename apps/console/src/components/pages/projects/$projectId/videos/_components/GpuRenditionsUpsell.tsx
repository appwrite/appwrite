import { useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { Cpu, X } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { VIDEOS_GPU_BANNER_ID } from '@/lib/console-banners/catalog'
import { useT } from '@/lib/i18n/translate'
import { useDismissConsoleBanner, useProject } from '@/lib/react-query/hooks'
import { isConsoleBannerDismissed, type UserPrefs } from '@/lib/user-prefs-keys'
import { cn } from '@/lib/utils'

/**
 * GPU compute for renditions, offered through sales on a video's Renditions
 * page. Shown on Cloud until dismissed.
 */
export function GpuRenditionsUpsell({
  projectId,
  className,
}: {
  projectId: string
  className?: string
}) {
  const t = useT()
  const { account } = useAuth()
  const { isCloud } = useConsoleProfile()
  const { project } = useProject(projectId)
  const dismissBanner = useDismissConsoleBanner()
  const [dismissed, setDismissed] = useState(false)
  const user = account as Models.User | undefined

  if (
    !isCloud ||
    !user ||
    dismissed ||
    isConsoleBannerDismissed(user.prefs as UserPrefs, VIDEOS_GPU_BANNER_ID)
  ) {
    return null
  }

  return (
    <div
      className={cn(
        'flex flex-wrap items-start gap-x-3 gap-y-2 rounded-lg border border-border bg-card/50 px-4 py-3',
        className,
      )}
    >
      <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
        <Cpu aria-hidden className="h-4 w-4 text-muted-foreground" />
      </div>
      <div className="min-w-0 flex-1 basis-60">
        <p className="text-[13px] font-medium text-foreground">
          {t('Faster renditions on GPU compute')}
        </p>
        <p className="text-[12px] text-muted-foreground">
          {t(
            'Encode on dedicated GPUs to get long and 4K videos ready in a fraction of the time, with no queue behind other uploads.',
          )}
        </p>
      </div>
      <Button
        asChild
        variant="outline"
        size="sm"
        className="h-8 shrink-0 text-[12px]"
      >
        <ContactSalesLink source="videos" orgId={project?.teamId}>
          {t('Contact sales')}
        </ContactSalesLink>
      </Button>
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="-me-2 mt-0.5 h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground"
        aria-label={t('Dismiss')}
        onClick={() => {
          setDismissed(true)
          dismissBanner.mutate(VIDEOS_GPU_BANNER_ID)
        }}
        {...analyticsAttrs('videos-gpu-dismiss')}
      >
        <X className="h-4 w-4" />
      </Button>
    </div>
  )
}
