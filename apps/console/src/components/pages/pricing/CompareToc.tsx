'use client'

import { useEffect, useMemo, useState, type MouseEvent } from 'react'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { withAnalyticsComparison } from '@/lib/pricing/analytics'
import {
  PolicySidebarSection,
  policySidebarLinkClassName,
} from '@/components/pages/legal/PolicySidebarNav'
import { useT } from '@/lib/i18n/translate'
import { PRICING_DATABASE_ANCHOR_ID } from '@/lib/pricing/dedicated-databases'
import { comparisonPageSections } from '@/lib/pricing/comparison-sections'
import { scrollToComparisonSection } from '@/lib/pricing/comparison-scroll'
import {
  BELOW_APP_HEADER_STICKY_MAX_HEIGHT_CLASS,
  BELOW_APP_HEADER_STICKY_TOP_CLASS,
} from '@/lib/layout/app-header-height'
import { cn } from '@/lib/utils'

export function CompareToc({ className }: { className?: string }) {
  const t = useT()
  const { features } = useConsoleProfile()
  const pricingPageSections = useMemo(
    () => [
      { id: PRICING_DATABASE_ANCHOR_ID, label: 'Database pricing' },
      ...withAnalyticsComparison(
        comparisonPageSections.map((section) => ({ ...section, title: section.label })),
        features.analytics,
      ),
    ],
    [features.analytics],
  )
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
  }, [pricingPageSections])

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
        BELOW_APP_HEADER_STICKY_TOP_CLASS,
        BELOW_APP_HEADER_STICKY_MAX_HEIGHT_CLASS,
        'z-10 hidden self-start overflow-y-auto pt-6 lg:block',
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
