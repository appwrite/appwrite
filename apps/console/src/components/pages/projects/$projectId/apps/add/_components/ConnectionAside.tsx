import { Check, Loader2 } from 'lucide-react'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { cn } from '@/lib/utils'
import type { WebFrameworkKey } from '@/lib/add-app-wizard/types'
import { useT } from '@/lib/i18n/translate'

type ConnectionAsideProps = {
  /** For web, pass framework key for FrameworkIcon */
  webFramework?: WebFrameworkKey | null
  /** Platform slug for PlatformIcon when not using web framework icon */
  platformSlug: string
  pingReceived: boolean
}

export function ConnectionAside({
  webFramework,
  platformSlug,
  pingReceived,
}: ConnectionAsideProps) {
  const t = useT()
  const connected = pingReceived
  const waiting = !pingReceived

  return (
    <div className="rounded-xl border border-border bg-card/50 p-5">
      <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        {t('Connection')}
      </p>
      <p className="mt-1 text-[13px] text-muted-foreground">
        {t(
          'Your app talks to Appwrite from the hostname or bundle you register, using the project API endpoint.', // pragma: allowlist secret
        )}
      </p>

      <div className="mt-6 flex flex-col items-center gap-3">
        <div className="flex w-full max-w-[220px] items-center justify-between gap-2">
          <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl border border-border bg-muted/50">
            {webFramework ? (
              <FrameworkIcon
                framework={webFramework === 'js' ? 'vanilla' : webFramework}
                size="lg"
                className="!h-8 !w-8"
              />
            ) : (
              <PlatformIcon platform={platformSlug} size="md" />
            )}
          </div>

          <ConnectionLine active={connected} waiting={waiting} />

          <div
            className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl border border-[hsl(343_98%_58%_/0.35)] bg-[hsl(343_98%_58%_/0.08)]"
            aria-hidden
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              viewBox="-12 -19 136 136"
              fill="none"
              className="h-8 w-8"
            >
              <path
                d="M111.1 73.4729V97.9638H48.8706C30.7406 97.9638 14.9105 88.114 6.44112 73.4729C5.2099 71.3444 4.13229 69.1113 3.22835 66.7935C1.45387 62.2516 0.338421 57.3779 0 52.2926V45.6712C0.0734729 44.5379 0.189248 43.4135 0.340647 42.3025C0.650124 40.0227 1.11768 37.7918 1.73218 35.6232C7.54544 15.0641 26.448 0 48.8706 0C71.2932 0 90.1935 15.0641 96.0068 35.6232H69.3985C65.0302 28.9216 57.4692 24.491 48.8706 24.491C40.272 24.491 32.711 28.9216 28.3427 35.6232C27.0113 37.6604 25.9782 39.9069 25.3014 42.3025C24.7002 44.4266 24.3796 46.6664 24.3796 48.9819C24.3796 56.0019 27.3319 62.3295 32.0653 66.7935C36.4515 70.9369 42.3649 73.4729 48.8706 73.4729H111.1Z"
                fill="hsl(343 98% 58%)"
              />
              <path
                d="M111.1 42.3027V66.7937H65.6759C70.4094 62.3297 73.3616 56.0021 73.3616 48.9821C73.3616 46.6666 73.041 44.4268 72.4399 42.3027H111.1Z"
                fill="hsl(343 98% 58%)"
              />
            </svg>
          </div>
        </div>

        <div
          className={cn(
            'mt-6 w-full rounded-lg border px-3 py-2.5 text-center text-[12px]',
            connected &&
              'border-green-500/40 bg-green-500/10 text-green-800 dark:text-green-300',
            waiting && 'border-primary/30 bg-primary/5 text-foreground',
          )}
        >
          {waiting && <span>{t('Waiting for your app to ping Appwrite…')}</span>} {/* pragma: allowlist secret */}
          {connected && (
            <span className="inline-flex items-center justify-center gap-2 font-medium">
              <Check className="h-3.5 w-3.5 shrink-0" />
              {t('Connected - your SDK reached this project.')}
            </span>
          )}
        </div>
      </div>

      <p className="mt-4 text-[11px] leading-relaxed text-muted-foreground">
        {t(
          'Keep this tab open while your app is running so Appwrite can confirm the connection.', // pragma: allowlist secret
        )}
      </p>
    </div>
  )
}

function ConnectionLine({
  active,
  waiting,
}: {
  active: boolean
  waiting: boolean
}) {
  return (
    <div className="flex min-w-0 flex-1 items-center gap-0">
      <div
        className={cn(
          'h-px min-w-[12px] flex-1 transition-all duration-500',
          active
            ? 'bg-gradient-to-l from-[hsl(343_98%_58%)] to-transparent'
            : waiting
              ? 'animate-pulse bg-gradient-to-r from-muted via-primary/40 to-muted'
              : 'border-t border-dashed border-border bg-transparent',
        )}
      />
      <div
        className={cn(
          'mx-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border transition-colors',
          active
            ? 'border-[hsl(343_98%_58%_/0.5)] bg-[hsl(343_98%_58%_/0.12)] text-[hsl(343_98%_48%)]'
            : 'border-border bg-muted/80 text-muted-foreground',
        )}
      >
        {active ? (
          <Check className="h-3.5 w-3.5" strokeWidth={2.5} />
        ) : waiting ? (
          <Loader2 className="h-3.5 w-3.5 animate-spin" />
        ) : (
          <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/50" />
        )}
      </div>
      <div
        className={cn(
          'h-px min-w-[12px] flex-1 transition-all duration-500',
          active
            ? 'bg-gradient-to-r from-[hsl(343_98%_58%)] to-transparent'
            : waiting
              ? 'animate-pulse bg-gradient-to-r from-muted via-primary/40 to-muted'
              : 'border-t border-dashed border-border bg-transparent',
        )}
      />
    </div>
  )
}
