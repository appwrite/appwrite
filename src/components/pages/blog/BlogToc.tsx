import { useEffect, useState } from 'react'
import { docsTocLinkClassName } from '@/lib/docs/nav-styles'
import { DOCS_TOC_SECTION_TITLE_CLASS } from '@/lib/docs/prose-typography'
import { cn } from '@/lib/utils'
import {
  BELOW_APP_HEADER_STICKY_MAX_HEIGHT_CLASS,
  BELOW_APP_HEADER_STICKY_TOP_CLASS,
} from '@/lib/layout/app-header-height'
import type { DocsTocItem } from '@/lib/docs/types'

type BlogTocProps = {
  items: DocsTocItem[]
}

export function BlogToc({ items }: BlogTocProps) {
  const [activeId, setActiveId] = useState(items[0]?.id ?? '')
  const [tocVisible, setTocVisible] = useState(false)

  useEffect(() => {
    const media = window.matchMedia('(min-width: 900px)')
    const sync = () => setTocVisible(media.matches)
    sync()
    media.addEventListener('change', sync)
    return () => media.removeEventListener('change', sync)
  }, [])

  useEffect(() => {
    if (!tocVisible || items.length === 0) return

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
  }, [items, tocVisible])

  return (
    <aside
      aria-hidden={items.length === 0 ? true : undefined}
      className={cn(
        BELOW_APP_HEADER_STICKY_TOP_CLASS,
        BELOW_APP_HEADER_STICKY_MAX_HEIGHT_CLASS,
        'z-10 hidden w-full min-w-0 max-w-[208px] shrink-0 self-start overflow-y-auto overscroll-y-contain pt-6 @[900px]:block',
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
