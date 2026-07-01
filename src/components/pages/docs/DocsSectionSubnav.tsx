import { useLocation } from '@tanstack/react-router'
import { ChevronLeft, Menu } from 'lucide-react'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet'
import { isDocsNavGroup } from '@/lib/docs/navigation'
import type { DocsNavLink, DocsNavParent, DocsNavTree } from '@/lib/docs/types'
import {
  DOCS_NAV_SCROLL_CLASS,
  DOCS_SECTION_HEADER_CLASS,
  docsSidebarNavLinkClassName,
} from '@/lib/docs/nav-styles'
import { cn } from '@/lib/utils'
import { DocsRouteLink } from './DocsRouteLink'

const DOCS_MENU_ICON_STROKE = 1.25

function isSubnavActive(href: string, pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return normalized === href
}

function SectionNavLinks({
  items,
  pathname,
  onNavigate,
  nested = false,
}: {
  items: DocsNavLink[]
  pathname: string
  onNavigate?: () => void
  nested?: boolean
}) {
  const list = (
    <ul className="space-y-0.5">
      {items.map((item) => (
        <li key={item.href}>
          <DocsRouteLink
            href={item.href}
            onClick={onNavigate}
            className={docsSidebarNavLinkClassName(
              isSubnavActive(item.href, pathname),
            )}
          >
            {item.label}
          </DocsRouteLink>
        </li>
      ))}
    </ul>
  )

  if (!nested) return list

  return <div className="ms-3 mt-0.5 ps-2">{list}</div>
}

function SectionNavCategory({
  label,
  items,
  pathname,
  onNavigate,
}: {
  label: string
  items: DocsNavLink[]
  pathname: string
  onNavigate?: () => void
}) {
  return (
    <nav aria-label={label}>
      <p className="mb-1.5 px-2 text-start text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        {label}
      </p>
      <SectionNavLinks
        items={items}
        pathname={pathname}
        onNavigate={onNavigate}
        nested
      />
    </nav>
  )
}

function SectionParentLink({
  parent,
  onNavigate,
}: {
  parent: DocsNavParent
  onNavigate?: () => void
}) {
  return (
    <DocsRouteLink
      href={parent.href}
      onClick={onNavigate}
      className="flex items-center gap-1.5 px-2 py-1.5 text-[13px] font-medium text-foreground transition-colors hover:text-foreground/80"
    >
      <ChevronLeft className="size-3.5" strokeWidth={DOCS_MENU_ICON_STROKE} />
      {parent.label}
    </DocsRouteLink>
  )
}

function SectionNavContent({
  navigation,
  parent,
  pathname,
  onNavigate,
}: {
  navigation: DocsNavTree
  parent: DocsNavParent | null
  pathname: string
  onNavigate?: () => void
}) {
  return (
    <div className="space-y-4">
      {parent ? <SectionParentLink parent={parent} onNavigate={onNavigate} /> : null}

      {navigation.map((entry, index) =>
        isDocsNavGroup(entry) ? (
          entry.label ? (
            <SectionNavCategory
              key={entry.label ?? index}
              label={entry.label}
              items={entry.items}
              pathname={pathname}
              onNavigate={onNavigate}
            />
          ) : (
            <SectionNavLinks
              key={index}
              items={entry.items}
              pathname={pathname}
              onNavigate={onNavigate}
            />
          )
        ) : (
          <SectionNavLinks
            key={entry.href}
            items={[entry]}
            pathname={pathname}
            onNavigate={onNavigate}
          />
        ),
      )}
    </div>
  )
}

type DocsSectionSubnavPanelProps = {
  navigation: DocsNavTree
  parent: DocsNavParent | null
}

/** Full-height section nav beside the global docs sidebar (desktop). */
export function DocsSectionSubnavPanel({
  navigation,
  parent,
}: DocsSectionSubnavPanelProps) {
  const pathname = useLocation().pathname

  return (
    <aside
      className="relative z-10 hidden h-full w-[220px] shrink-0 flex-col overflow-hidden border-e border-border bg-background @[1024px]:flex"
      aria-label={parent?.label ? `${parent.label} section navigation` : 'Section navigation'}
    >
      {parent ? (
        <div className={cn(DOCS_SECTION_HEADER_CLASS, 'px-3')}>
          <SectionParentLink parent={parent} />
        </div>
      ) : null}
      <nav className={cn('min-h-0 flex-1 overflow-y-auto px-3 py-4', DOCS_NAV_SCROLL_CLASS)}>
        <SectionNavContent
          navigation={navigation}
          parent={null}
          pathname={pathname}
        />
      </nav>
    </aside>
  )
}

type DocsSectionSubnavMobileProps = {
  navigation: DocsNavTree
  parent: DocsNavParent | null
}

/** Mobile sheet for section navigation. */
export function DocsSectionSubnavMobile({
  navigation,
  parent,
}: DocsSectionSubnavMobileProps) {
  const pathname = useLocation().pathname
  const [sheetOpen, setSheetOpen] = useState(false)

  return (
    <div className="border-b border-border px-4 py-3 @[1024px]:hidden">
      <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
        <SheetTrigger asChild>
          <Button variant="outline" size="sm" className="h-8 gap-1.5 text-[13px]">
            <Menu className="size-3.5" strokeWidth={DOCS_MENU_ICON_STROKE} />
            {parent?.label ?? 'Section'}
          </Button>
        </SheetTrigger>
        <SheetContent side="left" className="w-[280px] p-0">
          <SheetHeader className="border-b border-border px-4 py-4 text-start">
            <SheetTitle className="text-[15px]">
              {parent?.label ?? 'Section'}
            </SheetTitle>
          </SheetHeader>
          <div className={cn('overflow-y-auto px-4 py-4', DOCS_NAV_SCROLL_CLASS)}>
            <SectionNavContent
              navigation={navigation}
              parent={parent}
              pathname={pathname}
              onNavigate={() => setSheetOpen(false)}
            />
          </div>
        </SheetContent>
      </Sheet>
    </div>
  )
}
