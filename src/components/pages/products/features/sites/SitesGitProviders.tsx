import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const GIT_PROVIDERS: {
  id: string
  name: string
  icon: string
  comingSoon?: boolean
}[] = [
  { id: 'github', name: 'GitHub', icon: '/icons/github.svg' },
  { id: 'origin', name: 'Origin', icon: '/icons/origin.svg' },
  { id: 'gitlab', name: 'GitLab', icon: '/icons/gitlab.svg', comingSoon: true },
  { id: 'gitea', name: 'Gitea', icon: '/icons/gitea.svg', comingSoon: true },
  { id: 'forgejo', name: 'Forgejo', icon: '/icons/forgejo.svg', comingSoon: true },
  { id: 'gogs', name: 'Gogs', icon: '/icons/gogs.svg', comingSoon: true },
  { id: 'bitbucket', name: 'Bitbucket', icon: '/icons/bitbucket.svg', comingSoon: true },
]

type SitesGitProvidersProps = {
  className?: string
}

export function SitesGitProviders({ className }: SitesGitProvidersProps) {
  const t = useT()
  const hasComingSoon = GIT_PROVIDERS.some((provider) => provider.comingSoon)

  return (
    <div className={cn('mt-6', className)}>
      <p className="text-[12px] font-medium text-muted-foreground">{t('Supported Git providers')}</p>
      <div className="mt-2.5 flex flex-wrap gap-2">
        {GIT_PROVIDERS.map((provider, index) => (
          <span
            key={provider.id}
            className={cn(
              'product-hero-rise inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-medium',
              provider.comingSoon
                ? 'border-dashed border-border text-muted-foreground'
                : 'border-border bg-background text-foreground shadow-sm dark:bg-card',
            )}
            style={riseStyle(150 + index * 60)}
          >
            <ProductFeaturePublicIcon
              src={provider.icon}
              className={cn('size-3.5', provider.comingSoon && 'opacity-60')}
            />
            {provider.name}
          </span>
        ))}
      </div>
      {hasComingSoon ? (
        <p className="mt-2 text-[11px] text-muted-foreground/80">
          {t('GitLab, Bitbucket, Gitea, and more coming soon.')}
        </p>
      ) : null}
    </div>
  )
}
