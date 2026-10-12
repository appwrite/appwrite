import { Check, ChevronRight } from 'lucide-react'
import { SiteTemplateGallery } from '@/components/pages/projects/$projectId/sites/_components/SiteTemplateGallery'
import {
  ArtChip,
  ArtIconBadge,
  ArtWindow,
  floatStyle,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import {
  MARKETING_SITE_TEMPLATES_COLUMNS,
  MARKETING_SITE_TEMPLATES_PAGE_SIZE,
  MARKETING_SITE_TEMPLATES_PROJECT_ID,
} from '@/lib/sites/site-template-wizard'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Framework logos floating in the side gutters on wide screens; `fade` dims the outer ones. */
const SCATTERED_FRAMEWORKS: { id: string; icon: string; className: string; fade?: boolean }[] = [
  { id: 'nextjs', icon: '/icons/nextjs.svg', className: 'start-[2%] top-[10%]' },
  { id: 'astro', icon: '/icons/astro.svg', className: 'start-0 top-[34%]', fade: true },
  { id: 'svelte', icon: '/icons/svelte.svg', className: 'start-[3%] top-[58%]' },
  { id: 'vue', icon: '/icons/vue.svg', className: 'start-[1%] top-[82%]', fade: true },
  { id: 'nuxt', icon: '/icons/nuxt.svg', className: 'end-[2%] top-[16%]', fade: true },
  { id: 'react', icon: '/icons/react.svg', className: 'end-0 top-[40%]' },
  { id: 'tanstack', icon: '/icons/tanstack.svg', className: 'end-[3%] top-[64%]', fade: true },
  { id: 'angular', icon: '/icons/angular.svg', className: 'end-[1%] top-[86%]' },
]

const DEPLOY_PROVIDERS = ['/icons/github.svg', '/icons/origin.svg'] as const

export function SitesTemplatesVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[1180px] pb-10 pt-6 xl:px-24">
      {SCATTERED_FRAMEWORKS.map((framework, index) => (
        <span
          key={framework.id}
          className={cn('product-hero-rise absolute hidden xl:block', framework.className)}
          style={riseStyle(300 + index * 70)}
          aria-hidden
        >
          <span
            className={cn(
              'product-hero-float flex size-11 items-center justify-center rounded-xl border border-border bg-background shadow-sm dark:bg-card',
              framework.fade && 'opacity-55',
            )}
            style={floatStyle(index * 380)}
          >
            <ProductFeaturePublicIcon src={framework.icon} className="size-[18px]" />
          </span>
        </span>
      ))}

      <ArtWindow
        className="product-hero-rise"
        style={riseStyle(60)}
        bodyClassName="p-3 sm:p-4"
        title={
          <span className="flex items-center gap-1">
            {t('Create site')}
            <ChevronRight className="size-3 rtl:-scale-x-100" aria-hidden />
            <span className="text-foreground">{t('Clone template')}</span>
          </span>
        }
      >
        <SiteTemplateGallery
          projectId={MARKETING_SITE_TEMPLATES_PROJECT_ID}
          columns={MARKETING_SITE_TEMPLATES_COLUMNS}
          compact
          maintainGridHeight
          scrollToTopOnPageChange={false}
          defaultPageSize={MARKETING_SITE_TEMPLATES_PAGE_SIZE}
          pageSizeOptions={[MARKETING_SITE_TEMPLATES_PAGE_SIZE]}
        />
      </ArtWindow>

      <ArtChip className="end-2 top-0 hidden sm:block xl:end-20" delayMs={900}>
        <div className="flex items-center gap-2">
          <span className="flex -space-x-1 rtl:space-x-reverse" aria-hidden>
            {DEPLOY_PROVIDERS.map((icon) => (
              <span
                key={icon}
                className="flex size-6 items-center justify-center rounded-full border border-border bg-background ring-2 ring-background dark:bg-card"
              >
                <ProductFeaturePublicIcon src={icon} className="size-3" />
              </span>
            ))}
          </span>
          <span className="text-[11px] font-medium text-foreground">{t('Deploy from Git')}</span>
        </div>
      </ArtChip>

      <ArtChip className="bottom-0 start-2 hidden sm:block xl:start-20" delayMs={1100} floatDelayMs={700}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Check} tone="success" />
          <div>
            <p className="text-[10px] text-muted-foreground">{t('Build command')}</p>
            <p dir="ltr" className="text-start font-mono text-[11px] text-foreground">npm run build</p>
          </div>
        </div>
      </ArtChip>
    </div>
  )
}
