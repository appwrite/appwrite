import { Badge } from '@/components/ui/badge'
import {
  MarketingFeatureGrid,
  MarketingSectionHeading,
} from '@/components/pages/marketing/MarketingSections'
import type { ProductUniqueSection } from '@/lib/products/types'
import { cn } from '@/lib/utils'

type ProductUniqueSectionsProps = {
  sections: ProductUniqueSection[]
}

function sectionShellClassName(muted?: boolean) {
  return cn(
    'border-b border-border py-16 sm:py-20',
    muted ? 'bg-muted/20' : 'bg-background',
  )
}

export function ProductUniqueSections({ sections }: ProductUniqueSectionsProps) {
  return (
    <>
      {sections.map((section) => {
        if (section.type === 'feature-grid') {
          return (
            <section
              key={section.title}
              className={sectionShellClassName(section.muted)}
            >
              <div className="mx-auto max-w-7xl px-4 sm:px-6">
                <MarketingSectionHeading
                  title={section.title}
                  description={section.description}
                  size="md"
                />
                <div className="mt-10">
                  <MarketingFeatureGrid
                    items={section.items}
                    columns={section.columns ?? 3}
                  />
                </div>
              </div>
            </section>
          )
        }

        if (section.type === 'method-cards') {
          return (
            <section key={section.title} className={sectionShellClassName()}>
              <div className="mx-auto max-w-7xl px-4 sm:px-6">
                <MarketingSectionHeading
                  title={section.title}
                  description={section.description}
                  size="md"
                />
                <div className="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {section.items.map((item) => {
                    const Icon = item.icon
                    return (
                      <a
                        key={item.title}
                        href={item.href}
                        className="group flex flex-col gap-3 rounded-xl border border-border bg-card/45 p-5 transition-colors hover:bg-accent/15"
                      >
                        <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
                          <Icon className="size-3.5 text-muted-foreground" aria-hidden />
                        </span>
                        <div>
                          <h3 className="text-[14px] font-semibold text-foreground">
                            {item.title}
                          </h3>
                          <p className="mt-2 text-[13px] leading-5 text-muted-foreground">
                            {item.description}
                          </p>
                        </div>
                      </a>
                    )
                  })}
                </div>
              </div>
            </section>
          )
        }

        if (section.type === 'compare') {
          return (
            <section key={section.title} className={sectionShellClassName(section.muted)}>
              <div className="mx-auto max-w-7xl px-4 sm:px-6">
                <MarketingSectionHeading
                  title={section.title}
                  description={section.description}
                  size="md"
                />
                <div className="mt-10 grid overflow-hidden rounded-xl border border-border bg-card/45 lg:grid-cols-3">
                  {section.items.map((item, index) => {
                    const Icon = item.icon
                    return (
                      <article
                        key={item.title}
                        className={cn(
                          'flex h-full flex-col p-6',
                          index < section.items.length - 1 &&
                            'border-b border-border lg:border-b-0 lg:border-r lg:last:border-r-0',
                        )}
                      >
                        <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
                          <Icon className="size-3.5 text-[var(--brand-cta)]" aria-hidden />
                        </span>
                        <h3 className="mt-4 text-[15px] font-semibold text-foreground">
                          {item.title}
                        </h3>
                        <p className="mt-2 text-[13px] leading-6 text-muted-foreground">
                          {item.description}
                        </p>
                        <ul className="mt-4 space-y-2">
                          {item.bullets.map((bullet) => (
                            <li
                              key={bullet}
                              className="text-[13px] leading-5 text-muted-foreground before:mr-2 before:text-[var(--brand-cta)] before:content-['•']"
                            >
                              {bullet}
                            </li>
                          ))}
                        </ul>
                      </article>
                    )
                  })}
                </div>
              </div>
            </section>
          )
        }

        return (
          <section
            key={section.title}
            className={sectionShellClassName(section.muted)}
          >
            <div className="mx-auto max-w-7xl px-4 sm:px-6">
              <MarketingSectionHeading
                title={section.title}
                description={section.description}
                size="md"
              />
              <div className="mt-10 grid overflow-hidden rounded-xl border border-border bg-card/45 lg:grid-cols-3">
                {section.items.map((item, index) => (
                  <article
                    key={item.title}
                    className="flex h-full flex-col border-b border-border p-6 last:border-b-0 lg:border-b-0 lg:border-r lg:last:border-r-0"
                  >
                    <Badge variant="info" className="w-fit shrink-0 text-[10px]">
                      Step {index + 1}
                    </Badge>
                    <h3 className="mt-4 text-[14px] font-semibold text-foreground">
                      {item.title}
                    </h3>
                    <p className="mt-2 flex-1 text-[13px] leading-6 text-muted-foreground">
                      {item.description}
                    </p>
                  </article>
                ))}
              </div>
            </div>
          </section>
        )
      })}
    </>
  )
}
