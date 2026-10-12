import { useMemo } from 'react'
import { Badge } from '@/components/ui/badge'
import { DocsLayout } from '@/components/pages/docs/DocsLayout'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import {
  DocsHubCategorySection,
  getHubCategoryTocItem,
} from '@/components/pages/docs/_components/DocsHubCategorySection'
import { docsGridTwoCol } from '@/lib/docs/docs-container'
import { getTutorialsHubCategories } from '@/lib/docs/tutorials-hub'
import { cn } from '@/lib/utils'

export function View() {
  const categories = useMemo(() => getTutorialsHubCategories(), [])
  const toc = useMemo(
    () => categories.map((category) => getHubCategoryTocItem(category.title)),
    [categories],
  )

  return (
    <DocsLayout
      slug="tutorials"
      title="Tutorials"
      description="Follow a simple tutorial to get started with Appwrite in your preferred framework quickly and easily."
      toc={toc}
    >
      <div className="space-y-12">
        {categories.map((category) => (
          <DocsHubCategorySection key={category.title} title={category.title}>
            <ul className={cn('grid gap-3', docsGridTwoCol)}>
              {category.tutorials.map((tutorial) => (
                <li key={tutorial.href}>
                  {tutorial.draft ? (
                    <div
                      aria-disabled
                      className={cn(
                        'block rounded-xl border border-border bg-card/45 px-4 py-4 opacity-70',
                      )}
                    >
                      <div className="flex items-start justify-between gap-2">
                        <p className="text-[13px] font-medium text-foreground">
                          {tutorial.framework}
                        </p>
                        <Badge variant="inactive" className="text-[10px] shrink-0">
                          Coming soon
                        </Badge>
                      </div>
                      <p className="mt-1 text-[12px] text-muted-foreground">
                        {tutorial.title}
                      </p>
                    </div>
                  ) : (
                    <DocsRouteLink
                      href={tutorial.href}
                      className="block rounded-xl border border-border bg-card/45 px-4 py-4 transition-colors hover:bg-accent/15"
                    >
                      <p className="text-[13px] font-medium text-foreground">
                        {tutorial.framework}
                      </p>
                      <p className="mt-1 text-[12px] text-muted-foreground">
                        {tutorial.title}
                      </p>
                    </DocsRouteLink>
                  )}
                </li>
              ))}
            </ul>
          </DocsHubCategorySection>
        ))}
      </div>
    </DocsLayout>
  )
}
