import {
  ArtAppwriteMark,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import {
  BeepCard,
  BeepPhone,
  BeepScreenHeader,
} from '@/components/pages/products/features/beep/BeepArtParts'
import { useT } from '@/lib/i18n/translate'

const CHANGELOG = [
  {
    date: 'Nov 19, 2026',
    title: 'Set up APNs for an iOS app with one CLI command',
    body: 'Run appwrite init apns in your iOS project and the CLI sets up push notifications in one command.',
  },
  {
    date: 'Nov 17, 2026',
    title: 'Usage monitors for every project',
    body: 'Get notified when a project crosses a usage threshold, by email or on your phone.',
  },
] as const

/** The Updates tab: Appwrite Cloud status first, then the changelog. */
export function BeepUpdatesVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto flex w-full max-w-[540px] justify-center py-4">
      <div className="product-hero-rise" style={riseStyle(40)}>
        <BeepPhone tab="updates" alertCount={3}>
          <BeepScreenHeader title="Updates" pill={null} />
          <div className="mt-3 px-3.5">
            <p className="text-[11px] font-semibold text-foreground">
              {t('Cloud status')}
            </p>
            <BeepCard className="mt-1.5">
              <p className="flex items-center gap-1.5 text-[9.5px] text-muted-foreground">
                <span
                  className="size-1.5 rounded-full bg-amber-500"
                  aria-hidden
                />
                {t('Ongoing · Nov 19 at 4:14 PM')}
              </p>
              <p className="mt-0.5 text-[11px] font-medium text-foreground">
                {t('Delayed Realtime events')}
              </p>
            </BeepCard>

            <p className="mt-3.5 text-[11px] font-semibold text-foreground">
              {t('Changelog')}
            </p>
            <div className="mt-1.5 space-y-2">
              {CHANGELOG.map((entry, index) => (
                <BeepCard key={entry.title} className="product-hero-rise">
                  {index === 0 ? (
                    <div
                      className="relative mb-2 flex h-[86px] flex-col justify-between overflow-hidden rounded-xl bg-[#19191c] p-2.5"
                      style={riseStyle(200)}
                    >
                      <span
                        className="absolute -end-6 -top-10 size-32 rounded-full bg-[radial-gradient(closest-side,rgb(253_54_110/0.45),transparent)]"
                        aria-hidden
                      />
                      <span className="relative flex items-center gap-1 text-[8px] font-medium text-white/80">
                        <ArtAppwriteMark className="size-3.5" />
                        appwrite
                      </span>
                      <span className="relative max-w-[80%] font-aeonik-pro text-[11px] leading-[13px] text-white">
                        {t('One-click APNs setup with the Appwrite CLI')}
                        <span className="text-[var(--brand-cta)]">_</span>
                      </span>
                    </div>
                  ) : null}
                  <p className="flex items-center gap-1.5 text-[9px] text-muted-foreground">
                    {index === 0 ? (
                      <span
                        className="size-1.5 rounded-full bg-[var(--brand-cta)]"
                        aria-hidden
                      />
                    ) : null}
                    {t(entry.date)}
                  </p>
                  <p className="mt-0.5 text-[11px] font-semibold leading-[14px] text-foreground">
                    {t(entry.title)}
                  </p>
                  <p className="mt-0.5 line-clamp-2 text-[9.5px] leading-[13px] text-muted-foreground">
                    {t(entry.body)}
                  </p>
                </BeepCard>
              ))}
            </div>
          </div>
        </BeepPhone>
      </div>
    </div>
  )
}
