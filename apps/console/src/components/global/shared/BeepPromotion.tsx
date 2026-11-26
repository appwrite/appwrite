import { useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { ArrowUpRight, X } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { BEEP_PROMO_BANNER_ID } from '@/lib/console-banners/catalog'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { useDismissConsoleBanner } from '@/lib/react-query/hooks'
import { isConsoleBannerDismissed, type UserPrefs } from '@/lib/user-prefs-keys'

export const BEEP_APP_STORE_URL = 'https://apps.apple.com/app/id6831213827'
export const BEEP_GOOGLE_PLAY_URL =
  'https://play.google.com/store/apps/details?id=io.appwrite.beep'

export const BEEP_ICON_SRC = '/images/console/beep/icon.avif'

/** Beep registers every phone it runs on as a push target on the account. */
function usesBeep(account: Models.User | undefined): boolean {
  return (
    account?.targets?.some((target) => target.providerType === 'push') ?? false
  )
}

/** The App Store and Google Play links to Beep, as one line. */
export function BeepStoreLinks({ className }: { className?: string }) {
  return (
    <div className={className}>
      <a
        href={BEEP_APP_STORE_URL}
        target="_blank"
        rel="noopener noreferrer"
        className="text-foreground hover:underline"
        {...analyticsAttrs('notifications-beep-app-store')}
      >
        App Store
      </a>
      <span aria-hidden className="text-muted-foreground">
        ·
      </span>
      <a
        href={BEEP_GOOGLE_PLAY_URL}
        target="_blank"
        rel="noopener noreferrer"
        className="text-foreground hover:underline"
        {...analyticsAttrs('notifications-beep-google-play')}
      >
        Google Play
      </a>
    </div>
  )
}

/**
 * Whether to offer Beep: on Cloud, until dismissed anywhere, and never to
 * someone whose account already gets Beep's pushes.
 */
function useBeepPromotion() {
  const { account } = useAuth()
  const { isCloud } = useConsoleProfile()
  const dismissBanner = useDismissConsoleBanner()
  const [dismissed, setDismissed] = useState(false)
  const user = account as Models.User | undefined
  const show =
    isCloud &&
    !!user &&
    !dismissed &&
    !usesBeep(user) &&
    !isConsoleBannerDismissed(user.prefs as UserPrefs, BEEP_PROMO_BANNER_ID)
  return {
    show,
    dismiss: () => {
      setDismissed(true)
      dismissBanner.mutate(BEEP_PROMO_BANNER_ID)
    },
  }
}

/**
 * Appwrite Beep, at the foot of the notifications popover: alerts on your
 * phone. Shown on Cloud until dismissed, and never to someone whose account
 * already gets Beep's pushes.
 */
export function BeepPromotion() {
  const t = useT()
  const promotion = useBeepPromotion()
  if (!promotion.show) return null

  return (
    <div className="flex shrink-0 items-start gap-3 border-t border-border bg-muted/30 px-5 py-3">
      <img
        src={BEEP_ICON_SRC}
        alt=""
        width={32}
        height={32}
        className="size-8 shrink-0 rounded-lg ring-1 ring-black/5 dark:ring-white/10"
      />
      <div className="min-w-0 flex-1">
        <p className="text-[13px] font-medium leading-snug text-foreground">
          {t('Get alerts on your phone')}
        </p>
        <p className="mt-0.5 text-[12px] leading-relaxed text-muted-foreground">
          {t(
            'Appwrite Beep pushes your alerts as they fire, with Acknowledge, Snooze and Mute.',
          )}
        </p>
        <BeepStoreLinks className="mt-1.5 flex items-center gap-1.5 text-[12px] font-medium" />
      </div>
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="-me-2 -mt-1 h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground"
        aria-label={t('Dismiss')}
        onClick={promotion.dismiss}
        {...analyticsAttrs('notifications-beep-dismiss')}
      >
        <X className="h-4 w-4" />
      </Button>
    </div>
  )
}

/**
 * Appwrite Beep, as a panel on the monitors pages: the alerts a monitor raises,
 * on your phone. The store links open in a new tab.
 */
export function BeepCallout({
  className,
  source,
}: {
  className?: string
  /** Where it is shown, for analytics. */
  source: 'monitors' | 'create-monitor'
}) {
  const t = useT()
  const promotion = useBeepPromotion()
  if (!promotion.show) return null

  return (
    <div
      className={cn(
        'flex flex-wrap items-start gap-x-3 gap-y-2 rounded-lg border border-border bg-card/50 px-4 py-3',
        className,
      )}
    >
      <img
        src={BEEP_ICON_SRC}
        alt=""
        width={36}
        height={36}
        className="size-9 shrink-0 rounded-[10px] ring-1 ring-black/5 dark:ring-white/10"
      />
      <div className="min-w-0 flex-1 basis-60">
        <p className="text-[13px] font-medium text-foreground">
          {t('Get these alerts on your phone')}
        </p>
        <p className="text-[12px] text-muted-foreground">
          {t(
            'Appwrite Beep pushes alerts as they fire, so you can acknowledge one from anywhere. Critical alerts ring through silent mode.',
          )}
        </p>
      </div>
      <div className="flex shrink-0 gap-2">
        {(
          [
            ['App Store', BEEP_APP_STORE_URL, 'app-store'],
            ['Google Play', BEEP_GOOGLE_PLAY_URL, 'google-play'],
          ] as const
        ).map(([label, href, store]) => (
          <Button
            key={store}
            asChild
            variant="outline"
            size="sm"
            className="h-8 text-[12px]"
          >
            <a
              href={href}
              target="_blank"
              rel="noopener noreferrer"
              {...analyticsAttrs(`${source}-beep-${store}`)}
            >
              {label}
              <ArrowUpRight aria-hidden className="ms-1 h-3.5 w-3.5" />
            </a>
          </Button>
        ))}
      </div>
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="-me-2 mt-0.5 h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground"
        aria-label={t('Dismiss')}
        onClick={promotion.dismiss}
        {...analyticsAttrs(`${source}-beep-dismiss`)}
      >
        <X className="h-4 w-4" />
      </Button>
    </div>
  )
}
