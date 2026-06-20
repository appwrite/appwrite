import { useEffect, useState } from 'react'
import { docsTocLinkClassName } from '@/lib/docs/nav-styles'
import { DOCS_TOC_SECTION_TITLE_CLASS } from '@/lib/docs/prose-typography'
import { cn } from '@/lib/utils'
import type { DocsTocItem } from '@/lib/docs/types'

type DocsTocProps = {
  items: DocsTocItem[]
}

export function DocsToc({ items }: DocsTocProps) {
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

  return (
    <aside
      aria-hidden={items.length === 0 ? true : undefined}
      className={cn(
        'sticky top-12 z-10 hidden w-full min-w-0 max-w-[208px] shrink-0 self-start overflow-y-auto overscroll-y-contain pt-8 @[900px]:block',
        'max-h-[calc(100dvh-5rem)]',
      )}
    >
      {items.length > 0 ? (
        <nav aria-label="Table of contents">
          <p className={DOCS_TOC_SECTION_TITLE_CLASS}>On this page</p>
          <ul className="space-y-0.5">
            {items.map((item) => {
              const label = `${item.step ? `${item.step}. ` : ''}${item.label}`

              return (
                <li key={item.id} className="min-w-0">
                  <a
                    href={`#${item.id}`}
                    title={label}
                    className={cn(
                      docsTocLinkClassName(activeId === item.id),
                      item.level > 2 && 'ps-4',
                    )}
                  >
                    {label}
                  </a>
                </li>
              )
            })}
          </ul>
        </nav>
      ) : null}
    </aside>
  )
}
