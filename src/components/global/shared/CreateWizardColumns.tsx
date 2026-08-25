/**
 * Shared column layout for create wizards (sites, functions).
 * Reused so template and repository wizard right/left cards match across products.
 */

import { Input } from '@/components/ui/input'
import { Search } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'

export function CreateWizardLeftColumn({
  title,
  children,
}: {
  title: string
  children: React.ReactNode
}) {
  const t = useT()
  return (
    <div className="flex min-h-0 min-w-0 flex-col lg:col-span-2">
      <h2 className="mb-4 shrink-0 text-[14px] font-semibold text-foreground">
        {t(title)}
      </h2>
      <div className="flex min-h-0 min-w-0 flex-1 flex-col">{children}</div>
    </div>
  )
}

export function CreateWizardRightColumn({
  title = 'Clone template',
  search,
  children,
}: {
  title?: string
  /** Optional search row (same as sites template search). */
  search?: {
    value: string
    onChange: (value: string) => void
    placeholder?: string
  }
  children: React.ReactNode
}) {
  const t = useT()
  return (
    <div className="flex min-h-0 min-w-0 flex-col lg:col-span-3">
      <h2 className="mb-4 shrink-0 text-[14px] font-semibold text-foreground">{t(title)}</h2>
      <div className="flex min-h-0 min-w-0 flex-1 flex-col">
        {search ? (
          <div className="mb-4 shrink-0">
            <div className="relative">
              <Search className="pointer-events-none absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={search.value}
                onChange={(e) => search.onChange(e.target.value)}
                placeholder={t(search.placeholder ?? 'Search templates...')}
                className="h-9 ps-9 text-[13px]"
              />
            </div>
          </div>
        ) : null}
        <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-x-hidden overflow-y-auto overscroll-contain">
          {children}
        </div>
      </div>
    </div>
  )
}
