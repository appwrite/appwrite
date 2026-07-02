import { useEffect, useState } from 'react'
import { cn } from '@/lib/utils'
import type { PolicySlug } from '@/lib/legal/policies'
import { PolicyRelatedLinks } from './PolicyRelatedLinks'
import {
  PolicySidebarSection,
  policySidebarLinkClassName,
} from './PolicySidebarNav'
import { useT } from '@/lib/i18n/translate'

export type PolicyTocItem = {
  id: string
  label: string
}

type PolicyTocProps = {
  items: readonly PolicyTocItem[]
  currentPolicy?: PolicySlug
  className?: string
}

export function PolicyToc({ items, currentPolicy, className }: PolicyTocProps) {
  const t = useT()
  const [activeId, setActiveId] = useState(items[0]?.id ?? '')

  useEffect(() => {
    if (items.length === 0) return

    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries
          .filter((entry) => entry.isIntersecting)
          .sort((a, b) => b.intersectionRatio - a.intersectionRatio)

        if (visible[0]?.target.id) {
          setActiveId(visible[0].target.id)
        }
      },
      {
        rootMargin: '-20% 0px -60% 0px',
        threshold: [0, 0.25, 0.5, 1],
      },
    )

    for (const item of items) {
      const element = document.getElementById(item.id)
      if (element) observer.observe(element)
    }

    return () => observer.disconnect()
  }, [items])

  if (items.length === 0 && !currentPolicy) return null

  return (
    <aside
      className={cn(
        'sticky top-6 z-10 hidden max-h-[calc(100dvh-3rem)] self-start overflow-y-auto lg:block',
        className,
      )}
    >
      <div className="space-y-8 pe-2">
        {items.length > 0 ? (
          <PolicySidebarSection title={t('On this page')} ariaLabel={t('Table of contents')}>
            <ul className="space-y-0.5">
              {items.map((item) => (
                <li key={item.id} className="min-w-0">
                  <a
                    href={`#${item.id}`}
                    title={t(item.label)}
                    className={policySidebarLinkClassName(activeId === item.id)}
                  >
                    {t(item.label)}
                  </a>
                </li>
              ))}
            </ul>
          </PolicySidebarSection>
        ) : null}

        {currentPolicy ? (
          <PolicyRelatedLinks current={currentPolicy} embedded />
        ) : null}
      </div>
    </aside>
  )
}
