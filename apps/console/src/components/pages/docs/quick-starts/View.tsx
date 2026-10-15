import { useMemo } from 'react'
import { DocsLayout } from '@/components/pages/docs/DocsLayout'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import {
  DocsHubCategorySection,
  getHubCategoryTocItem,
} from '@/components/pages/docs/_components/DocsHubCategorySection'
import { docsGridQuickStarts } from '@/lib/docs/docs-container'
import { QUICK_STARTS_HUB_CATEGORIES } from '@/lib/docs/quick-starts-hub'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

export function View() {
  const toc = useMemo(
    () =>
      QUICK_STARTS_HUB_CATEGORIES.map((category) =>
        getHubCategoryTocItem(category.title),
      ),
    [],
  )

  return (
    <DocsLayout
      slug="quick-starts"
      title="Quick start"
      description="Get started with your favorite framework and language in just a few clicks."
      toc={toc}
    >
      <div className="space-y-12">
        {QUICK_STARTS_HUB_CATEGORIES.map((category) => (
          <DocsHubCategorySection key={category.title} title={category.title}>
            <ul className={cn('grid gap-3', docsGridQuickStarts)}>
              {category.items.map((item) => (
                <li key={item.href}>
                  <DocsRouteLink
                    href={item.href}
                    className={cn(
                      'flex h-full items-center gap-3 rounded-xl border border-border bg-card/45 px-4 py-3',
                      'transition-colors hover:bg-accent/15',
                    )}
                  >
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40">
                      <img
                        src={item.iconSrc}
                        alt=""
                        className={cn('size-4', PUBLIC_ICON_MUTED_CLASSES)}
                      />
                    </span>
                    <span className="text-[13px] font-medium text-foreground">
                      {item.title}
                    </span>
                  </DocsRouteLink>
                </li>
              ))}
            </ul>
          </DocsHubCategorySection>
        ))}
      </div>
    </DocsLayout>
  )
}
