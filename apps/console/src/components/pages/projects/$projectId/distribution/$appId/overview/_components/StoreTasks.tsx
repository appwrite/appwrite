import type { DistributionPlatform } from '@/lib/appwrite/distribution'
import { PLAY_CONSOLE_URL } from '@/lib/distribution/credentials'
import { useT } from '@/lib/i18n/translate'
import { ConsoleLink } from '../../../_components/ConsoleLink'
import {
  PLATFORM_PROVIDERS,
  PlatformIcon,
  providerLabel,
} from '../../../_components/platform'

const APP_STORE_CONNECT_APPS_URL = 'https://appstoreconnect.apple.com/apps'

type StoreConsole = {
  href: string
  label: string
}

const STORE_CONSOLES: Record<DistributionPlatform, StoreConsole> = {
  ios: { href: APP_STORE_CONNECT_APPS_URL, label: 'Open App Store Connect' },
  android: { href: PLAY_CONSOLE_URL, label: 'Open Play Console' },
}

const STORE_TASKS: Record<DistributionPlatform, readonly string[]> = {
  ios: [
    'Create the app record in App Store Connect with the bundle ID of the target.',
    'Accept the latest agreements in App Store Connect.',
    'Complete the app privacy labels.',
    'Answer the export compliance questions.',
  ],
  android: [
    'Create the app in Play Console with the package name of the target.',
    'Accept the latest agreements in Play Console.',
    'Complete the data safety form.',
    'Upload the first build in Play Console yourself. Google Play accepts uploads from Appwrite after that.',
    'New personal developer accounts must run a closed test before releasing to production.',
  ],
}

export function StoreTasks({
  platforms,
}: {
  platforms: readonly DistributionPlatform[]
}) {
  const t = useT()
  if (platforms.length === 0) return null
  return (
    <section
      aria-labelledby="overview-store-tasks-title"
      data-testid="overview-store-tasks"
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3
          id="overview-store-tasks-title"
          className="text-[15px] font-semibold text-foreground"
        >
          {t('Store tasks')}
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {t(
            'Appwrite cannot do these for you. Complete them in each store before the first submission.',
          )}
        </p>
      </div>
      {platforms.map((platform) => (
        <div
          key={platform}
          data-testid={`overview-store-tasks-${platform}`}
          className="border-t border-border px-6 py-4"
        >
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h4 className="flex items-center gap-2 text-[13px] font-semibold text-foreground">
              <PlatformIcon platform={platform} />
              {providerLabel(PLATFORM_PROVIDERS[platform])}
            </h4>
            <ConsoleLink
              href={STORE_CONSOLES[platform].href}
              label={t(STORE_CONSOLES[platform].label)}
            />
          </div>
          <ul className="mt-3 list-disc space-y-1.5 ps-5 text-[13px] text-muted-foreground marker:text-muted-foreground/60">
            {STORE_TASKS[platform].map((task) => (
              <li key={task}>{t(task)}</li>
            ))}
          </ul>
        </div>
      ))}
    </section>
  )
}
