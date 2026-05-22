import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import type { MarketplaceApp } from '@/lib/marketplace/mock-data'
import {
  MARKETPLACE_CATEGORY_ICONS,
  MARKETPLACE_CATEGORY_LABELS,
} from '@/lib/marketplace/mock-data'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { MarketplaceAppBadges } from './MarketplaceAppBadges'
import { MarketplaceAppCreators } from './MarketplaceAppCreators'

interface AppDetailDrawerProps {
  app: MarketplaceApp | null
  open: boolean
  onOpenChange: (open: boolean) => void
  onInstall?: (app: MarketplaceApp) => void
}

export function AppDetailDrawer({
  app,
  open,
  onOpenChange,
  onInstall,
}: AppDetailDrawerProps) {
  if (!app) return null

  const CategoryIcon = MARKETPLACE_CATEGORY_ICONS[app.category]

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={app.name}
      description={app.shortDescription}
      maxWidth="sm:max-w-lg"
      contentClassName="overflow-hidden"
      headerActions={<MarketplaceAppBadges app={app} />}
    >
      <>
        <div className="border-t border-border shrink-0" />

        <div className="flex flex-1 flex-col min-h-0">
          <div className="flex-1 overflow-y-auto">
            <div className="px-6 py-4 space-y-4">
              <div className="flex items-start gap-3">
                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                  <CategoryIcon className="h-5 w-5" />
                </div>
                <p className="text-[13px] text-muted-foreground leading-relaxed pt-0.5">
                  {app.shortDescription}
                </p>
              </div>

              <p className="text-[13px] text-muted-foreground leading-relaxed">
                {app.description}
              </p>

              <div className="flex flex-wrap gap-2">
                <Badge variant="inactive" className="text-[10px] shrink-0">
                  {MARKETPLACE_CATEGORY_LABELS[app.category]}
                </Badge>
                {app.featured && (
                  <Badge variant="inactive" className="text-[10px] shrink-0">
                    Featured
                  </Badge>
                )}
                {app.isOwned && (
                  <Badge variant="warning" className="text-[10px] shrink-0">
                    {app.status === 'published'
                      ? 'Published'
                      : app.status === 'pending'
                        ? 'Pending review'
                        : 'Draft'}
                  </Badge>
                )}
                {app.tags.map((tag) => (
                  <Badge
                    key={tag}
                    variant="inactive"
                    className="text-[10px] shrink-0"
                  >
                    {tag}
                  </Badge>
                ))}
              </div>

              <div className="space-y-2">
                <h4 className="text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Creators
                </h4>
                <MarketplaceAppCreators creators={app.creators} />
              </div>

              <dl className="grid grid-cols-2 gap-3 text-[13px]">
                <div>
                  <dt className="text-muted-foreground">Publisher</dt>
                  <dd className="font-medium text-foreground mt-0.5">
                    {app.author}
                  </dd>
                </div>
                <div>
                  <dt className="text-muted-foreground">Listed</dt>
                  <dd className="font-medium text-foreground mt-0.5">
                    <DateTooltip date={app.$createdAt} />
                  </dd>
                </div>
              </dl>
            </div>
          </div>

          {!app.isOwned && onInstall && (
            <div className="shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex justify-end">
              <Button onClick={() => onInstall(app)}>Install app</Button>
            </div>
          )}
        </div>
      </>
    </BaseDrawer>
  )
}
