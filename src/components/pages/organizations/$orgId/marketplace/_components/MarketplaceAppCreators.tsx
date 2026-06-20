import { InitialsAvatar } from '@/components/global/shared/Avatar'
import type { MarketplaceAppCreator } from '@/lib/marketplace/types'

type MarketplaceAppCreatorsProps = {
  creators: MarketplaceAppCreator[]
  /** Compact row for cards; stacked list for drawer. */
  variant?: 'inline' | 'list'
}

export function MarketplaceAppCreators({
  creators,
  variant = 'list',
}: MarketplaceAppCreatorsProps) {
  if (creators.length === 0) return null

  if (variant === 'inline') {
    return (
      <div className="flex items-center gap-2 min-w-0">
        <div className="flex -space-x-1.5 shrink-0">
          {creators.slice(0, 3).map((creator) => (
            <InitialsAvatar
              key={creator.name}
              name={creator.name}
              size="sm"
              className="ring-2 ring-card"
            />
          ))}
        </div>
        <span className="text-[13px] text-muted-foreground truncate">
          {creators.map((c) => c.name).join(', ')}
        </span>
      </div>
    )
  }

  return (
    <ul className="space-y-2">
      {creators.map((creator) => (
        <li key={creator.name} className="flex items-center gap-3 min-w-0">
          <InitialsAvatar name={creator.name} size="sm" className="shrink-0" />
          <div className="min-w-0">
            <p className="text-[13px] font-medium text-foreground truncate">
              {creator.name}
            </p>
            {creator.role && (
              <p className="text-[11px] text-muted-foreground truncate">
                {creator.role}
              </p>
            )}
          </div>
        </li>
      ))}
    </ul>
  )
}
