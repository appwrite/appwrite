'use client'

import { useEffect, useState, type RefObject } from 'react'
import { docsTocLinkClassName } from '@/lib/docs/nav-styles'
import { DOCS_TOC_SECTION_TITLE_CLASS } from '@/lib/docs/prose-typography'
import type { DocsTocItem } from '@/lib/docs/types'
import { cn } from '@/lib/utils'

type DocsPreviewInlineTocProps = {
  items: DocsTocItem[]
  scrollContainerRef?: RefObject<HTMLElement | null>
}

export function DocsPreviewInlineToc({
  items,
  scrollContainerRef,
}: DocsPreviewInlineTocProps) {
  const [activeId, setActiveId] = useState(items[0]?.id ?? '')

  useEffect(() => {
    if (items.length === 0) return

    const root = scrollContainerRef?.current ?? null

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
        root,
        rootMargin: '-12% 0px -55% 0px',
        threshold: [0, 0.25, 0.5, 1],
      },
    )

    for (const item of items) {
      const element = document.getElementById(item.id)
      if (element) observer.observe(element)
    }

    return () => observer.disconnect()
  }, [items, scrollContainerRef])

  if (items.length === 0) return null

  const handleTocClick = (
    event: React.MouseEvent<HTMLAnchorElement>,
    id: string,
  ) => {
    event.preventDefault()
    const target = document.getElementById(id)
    const container = scrollContainerRef?.current
    if (!target || !container) {
      target?.scrollIntoView({ behavior: 'smooth', block: 'start' })
      return
    }

    const targetTop = target.getBoundingClientRect().top
    const containerTop = container.getBoundingClientRect().top
    container.scrollTo({
      top: container.scrollTop + targetTop - containerTop - 12,
      behavior: 'smooth',
    })
  }

  return (
    <nav aria-label="Table of contents" className="mt-5">
      <p className={DOCS_TOC_SECTION_TITLE_CLASS}>On this page</p>
      <ul className="mt-2 space-y-0.5">
        {items.map((item) => {
          const label = `${item.step ? `${item.step}. ` : ''}${item.label}`

          return (
            <li key={item.id} className="min-w-0">
              <a
                href={`#${item.id}`}
                title={label}
                onClick={(event) => handleTocClick(event, item.id)}
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
  )
}
