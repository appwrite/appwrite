import { useState } from 'react'
import { Link, useLocation } from '@tanstack/react-router'
import { ChevronDown } from 'lucide-react'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { SheetClose } from '@/components/ui/sheet'
import { analyticsAttrs, type AnalyticsActionId } from '@/lib/analytics-actions'
import { useI18n } from '@/lib/i18n'
import { cn } from '@/lib/utils'

const NAV_TRIGGER_CLASS =
  'inline-flex h-9 cursor-pointer items-center gap-1 rounded-md px-2.5 text-start text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground data-[state=open]:bg-accent data-[state=open]:text-foreground'

const SOLUTION_LINKS = [
  {
    href: '/startups',
    label: 'solutionStartups',
    description: 'solutionStartupsDescription',
    analytics: 'marketing-nav-startups',
  },
  {
    href: '/agencies',
    label: 'solutionAgencies',
    description: 'solutionAgenciesDescription',
    analytics: 'marketing-nav-agencies',
  },
  {
    href: '/innovation-teams',
    label: 'solutionInnovationTeams',
    description: 'solutionInnovationTeamsDescription',
    analytics: 'marketing-nav-innovation-teams',
  },
  {
    href: '/enterprise',
    label: 'enterprise',
    description: 'solutionEnterpriseDescription',
    analytics: 'marketing-nav-enterprise',
  },
] as const

type SolutionCopy = ReturnType<typeof useI18n>['catalog']['app']['header']['marketingNav']

function isSolutionPath(pathname: string): boolean {
  const path = pathname.replace(/\/+$/, '') || '/'
  return SOLUTION_LINKS.some((item) => path === item.href || path.startsWith(`${item.href}/`))
}

function SolutionLinks({
  copy,
  pathname,
  onNavigate,
  closeSheet = false,
}: {
  copy: SolutionCopy
  pathname: string
  onNavigate?: () => void
  closeSheet?: boolean
}) {
  const path = pathname.replace(/\/+$/, '') || '/'

  return (
    <ul className="flex flex-col py-1">
      {SOLUTION_LINKS.map((item) => {
        const active = path === item.href
        const link = (
          <Link
            to={item.href}
            onClick={onNavigate}
            aria-current={active ? 'page' : undefined}
            className={cn(
              'link-unstyled flex flex-col gap-0.5 rounded-md px-3 py-2.5 text-start transition-colors hover:bg-accent',
              active && 'bg-accent',
            )}
            {...analyticsAttrs(item.analytics satisfies AnalyticsActionId)}
          >
            <span className="text-[13px] font-medium text-foreground">{copy[item.label]}</span>
            <span className="text-[12px] leading-5 text-muted-foreground">{copy[item.description]}</span>
          </Link>
        )

        return (
          <li key={item.href}>
            {closeSheet ? <SheetClose asChild>{link}</SheetClose> : link}
          </li>
        )
      })}
    </ul>
  )
}

export function isMarketingSolutionsNavItem(item: { menu?: string }) {
  return item.menu === 'solutions'
}

export function MarketingSolutionsNavPopover({ isActive = false }: { isActive?: boolean }) {
  const { catalog } = useI18n()
  const copy = catalog.app.header.marketingNav
  const [open, setOpen] = useState(false)
  const { pathname } = useLocation()

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          className={cn(NAV_TRIGGER_CLASS, isActive && 'bg-accent text-foreground')}
          aria-current={isActive ? 'page' : undefined}
          aria-expanded={open}
          aria-haspopup="dialog"
          {...analyticsAttrs('marketing-nav-solutions')}
        >
          {copy.solutions}
          <ChevronDown
            className={cn('size-3.5 transition-transform duration-200', open && 'rotate-180')}
            aria-hidden
          />
        </button>
      </PopoverTrigger>
      <PopoverContent
        align="start"
        sideOffset={10}
        className="w-[min(calc(100vw-2rem),320px)] overflow-hidden rounded-xl border border-border bg-popover p-1.5 text-start shadow-lg"
      >
        <SolutionLinks copy={copy} pathname={pathname} onNavigate={() => setOpen(false)} />
      </PopoverContent>
    </Popover>
  )
}

export function MarketingSolutionsMobileNav({ isActive = false }: { isActive?: boolean }) {
  const { catalog } = useI18n()
  const copy = catalog.app.header.marketingNav
  const { pathname } = useLocation()

  return (
    <Accordion type="single" collapsible>
      <AccordionItem value="solutions" className="border-none">
        <AccordionTrigger
          className={cn(
            'flex h-10 w-full items-center justify-between rounded-md px-3 py-0 text-start text-[13px] font-medium text-muted-foreground hover:bg-accent hover:text-foreground hover:no-underline [&[data-state=open]]:bg-accent [&[data-state=open]]:text-foreground',
            isActive && 'bg-accent text-foreground',
          )}
          aria-current={isActive ? 'page' : undefined}
          {...analyticsAttrs('marketing-nav-solutions')}
        >
          {copy.solutions}
        </AccordionTrigger>
        <AccordionContent className="pb-2 pt-1">
          <SolutionLinks copy={copy} pathname={pathname} closeSheet />
        </AccordionContent>
      </AccordionItem>
    </Accordion>
  )
}

export function isSolutionsMarketingPath(pathname: string): boolean {
  return isSolutionPath(pathname)
}
