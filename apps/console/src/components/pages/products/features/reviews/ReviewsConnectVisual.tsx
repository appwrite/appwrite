import { ChevronRight, Globe } from 'lucide-react'
import {
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { ReviewsProviderMark } from '@/components/pages/products/features/reviews/ReviewsArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const REPOSITORIES = [
  { name: 'acme/storefront', icon: '/icons/github.svg', on: true },
  { name: 'acme/checkout-api', icon: '/icons/gitlab.svg', on: true },
  { name: 'acme/billing', icon: '/icons/bitbucket.svg', on: false },
  { name: 'acme/docs', icon: '/icons/github.svg', on: true },
] as const

function Switch({ on }: { on: boolean }) {
  return (
    <span
      className={cn(
        'relative inline-flex h-[18px] w-[30px] shrink-0 rounded-full',
        on ? 'bg-[var(--tone-ink)]' : 'bg-muted-foreground/30',
      )}
      aria-hidden
    >
      <span
        className={cn(
          'absolute top-[2px] size-[14px] rounded-full bg-white shadow-sm',
          on ? 'start-[14px]' : 'start-[2px]',
        )}
      />
    </span>
  )
}

/** The switch in the create-site wizard, on by default, and the Repositories tab for everything else. */
export function ReviewsConnectVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] py-4 text-start">
      <ArtPanel delayMs={60} innerClassName="product-tone-shadow p-0">
        <p className="flex items-center gap-1.5 border-b border-border px-3.5 py-2.5 text-[11px] text-muted-foreground">
          <Globe className="size-3.5" aria-hidden />
          {t('Create site')}
          <ChevronRight className="size-3 rtl:rotate-180" aria-hidden />
          <span className="text-foreground">{t('Configure')}</span>
        </p>
        <div className="flex items-start gap-3 px-3.5 py-3">
          <div className="min-w-0 flex-1">
            <p className="text-[12.5px] font-semibold text-foreground">
              {t('Review pull requests')}
            </p>
            <p className="mt-1 text-[11.5px] leading-[17px] text-muted-foreground">
              {t(
                'Appwrite reviews each pull request when it is opened or gets new commits, and comments on it.',
              )}
            </p>
            <p className="mt-1.5 text-[10.5px] text-muted-foreground/80">
              {t('Reviews use your organization’s review credits.')}
            </p>
          </div>
          <Switch on />
        </div>
      </ArtPanel>

      <ArtPanel className="mt-4 sm:ms-14" delayMs={360} innerClassName="p-0">
        <div className="flex items-center justify-between border-b border-border px-3.5 py-2.5">
          <p className="text-[12px] font-semibold text-foreground">
            {t('Repositories')}
          </p>
          <span className="rounded-md border border-border px-2 py-0.5 text-[10.5px] font-medium text-foreground">
            {t('Review all')}
          </span>
        </div>
        <ul className="divide-y divide-border/70">
          {REPOSITORIES.map((repository, index) => (
            <li
              key={repository.name}
              className="product-hero-rise flex items-center gap-2.5 px-3.5 py-2.5"
              style={riseStyle(480 + index * 110)}
            >
              <ReviewsProviderMark src={repository.icon} />
              <span
                dir="ltr"
                className="min-w-0 flex-1 truncate font-mono text-[11.5px] text-foreground"
              >
                {repository.name}
              </span>
              <span
                className={cn(
                  'text-[10.5px]',
                  repository.on ? 'text-foreground' : 'text-muted-foreground',
                )}
              >
                {repository.on ? t('Reviewed') : t('Off')}
              </span>
              <Switch on={repository.on} />
            </li>
          ))}
        </ul>
      </ArtPanel>
    </div>
  )
}
