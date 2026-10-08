import { useEffect, useState, type MouseEvent } from 'react'
import { companyPageSections } from '@/lib/company/sections'
import { scrollToCompanySection, scrollToCompanySectionFromHash } from '@/lib/company/section-scroll'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export function CompanySectionNav() {
  const t = useT()
  const [activeId, setActiveId] = useState(companyPageSections[0]?.id ?? '')

  useEffect(() => {
    scrollToCompanySectionFromHash()
  }, [])

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
        rootMargin: '-20% 0px -55% 0px',
        threshold: [0, 0.25, 0.5, 1],
      },
    )

    for (const section of companyPageSections) {
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
    scrollToCompanySection(sectionId)
  }

  return (
    <nav
      aria-label={t('Company page sections')}
      className="relative z-[1] border-b border-border bg-background/80 backdrop-blur-sm"
    >
      <div className="mx-auto max-w-7xl px-4 py-3 sm:px-6 sm:py-4">
        <ul className="flex flex-wrap items-center justify-center gap-2">
          {companyPageSections.map((section) => {
            const isActive = activeId === section.id

            return (
              <li key={section.id}>
                <a
                  href={`#${section.id}`}
                  onClick={(event) => handleClick(event, section.id)}
                  className={cn(
                    'inline-flex h-8 items-center justify-center rounded-full border px-3.5 text-[12px] font-medium transition-colors',
                    isActive
                      ? 'border-border bg-foreground text-background'
                      : 'border-border bg-card/50 text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                  )}
                  aria-current={isActive ? 'location' : undefined}
                >
                  {t(section.label)}
                </a>
              </li>
            )
          })}
        </ul>
      </div>
    </nav>
  )
}
