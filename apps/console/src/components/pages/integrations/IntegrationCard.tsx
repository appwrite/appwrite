import { Link } from '@tanstack/react-router'
import { BadgeCheck } from 'lucide-react'
import { getIntegrationCategoryHeading } from '@/lib/integrations/categories'
import type { IntegrationMeta } from '@/lib/integrations/types'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { IntegrationIcon } from './IntegrationIcon'
import { IntegrationPill } from './IntegrationPill'

type IntegrationCardProps = {
  integration: IntegrationMeta
  variant?: 'default' | 'featured'
  className?: string
}

export function IntegrationCard({
  integration,
  variant = 'default',
  className,
}: IntegrationCardProps) {
  const t = useT()
  const isFeatured = variant === 'featured'

  return (
    <Link
      to="/integrations/$slug"
      params={{ slug: integration.slug }}
      className={cn(
        'group link-unstyled flex h-full flex-col overflow-hidden rounded-xl border border-border bg-card/50 transition-colors hover:bg-accent/40',
        isFeatured ? 'sm:flex-row' : undefined,
        className,
      )}
    >
      {isFeatured && integration.cover ? (
        <div className="relative aspect-[16/9] w-full shrink-0 overflow-hidden bg-muted sm:aspect-auto sm:w-[42%]">
          <img
            src={integration.cover}
            alt=""
            className="h-full w-full object-cover"
            loading="lazy"
          />
        </div>
      ) : null}

      <div className={cn('flex flex-1 flex-col p-5', isFeatured ? 'sm:py-6 sm:pe-6' : undefined)}>
        <div className="flex flex-col items-start gap-3">
          <IntegrationIcon
            slug={integration.slug}
            vendor={integration.product.vendor}
            alt={integration.product.vendor}
            size={isFeatured ? 'md' : 'sm'}
          />
          <div className="min-w-0">
            <h3 className="text-[15px] font-semibold text-foreground group-hover:text-foreground">
              {integration.title}
              {integration.isPartner ? (
                <BadgeCheck
                  className="ms-1.5 inline-block size-4 translate-y-[-1px] align-middle text-emerald-600 dark:text-emerald-400"
                  aria-label={t('Verified')}
                />
              ) : null}
            </h3>
            <p className="mt-1 text-[12px] text-muted-foreground">
              {integration.product.vendor}
            </p>
          </div>
        </div>

        <p className="mt-3 line-clamp-3 flex-1 text-[13px] leading-relaxed text-muted-foreground">
          {integration.description}
        </p>

        <div className="mt-4 flex flex-wrap gap-2">
          <IntegrationPill>{t(getIntegrationCategoryHeading(integration.category))}</IntegrationPill>
          {integration.platform.map((platform) => (
            <IntegrationPill key={platform}>{platform}</IntegrationPill>
          ))}
        </div>
      </div>
    </Link>
  )
}
