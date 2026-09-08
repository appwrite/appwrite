'use client'

import { useEffect, useState, type MouseEvent } from 'react'
import {
  PolicySidebarSection,
  policySidebarLinkClassName,
} from '@/components/pages/legal/PolicySidebarNav'
import { useT } from '@/lib/i18n/translate'
import { PRICING_DATABASE_ANCHOR_ID } from '@/lib/pricing/dedicated-databases'
import { comparisonPageSections } from '@/lib/pricing/comparison-sections'
import { scrollToComparisonSection } from '@/lib/pricing/comparison-scroll'
import { cn } from '@/lib/utils'

const pricingPageSections = [
  { id: PRICING_DATABASE_ANCHOR_ID, label: 'Database pricing' },
  ...comparisonPageSections,
] as const

export function CompareToc({ className }: { className?: string }) {
  const t = useT()
  const [activeId, setActiveId] = useState(pricingPageSections[0]?.id ?? '')

  useEffect(() => {
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
        root: document.getElementById('main-content'),
        rootMargin: '-20% 0px -60% 0px',
        threshold: [0, 0.25, 0.5, 1],
      },
    )

    for (const section of pricingPageSections) {
      const element = document.getElementById(section.id)
      if (element) observer.observe(element)
    }

    return () => observer.disconnect()
  }, [])

  const handleClick = (
    event: MouseEvent<HTMLAnchorElement>,
    sectionId: string,
  ) => {
    event.preventDefault()
    scrollToComparisonSection(sectionId)
  }

  return (
    <aside
      className={cn(
        'sticky top-6 z-10 hidden max-h-[calc(100dvh-3rem)] self-start overflow-y-auto lg:block',
        className,
      )}
    >
      <PolicySidebarSection
        title={t('On this page')}
        ariaLabel={t('Compare plans sections')}
      >
        <ul className="space-y-0.5">
          {pricingPageSections.map((section) => (
            <li key={section.id} className="min-w-0">
              <a
                href={`#${section.id}`}
                title={t(section.label)}
                onClick={(event) => handleClick(event, section.id)}
                className={policySidebarLinkClassName(activeId === section.id)}
                aria-current={activeId === section.id ? 'location' : undefined}
              >
                {t(section.label)}
              </a>
            </li>
          ))}
        </ul>
      </PolicySidebarSection>
    </aside>
  )
}
