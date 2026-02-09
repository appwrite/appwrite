/**
 * Shared column layout for create wizards (sites, functions).
 * Reused so template and repository wizard right/left cards match across products.
 */

import { Input } from '@/components/ui/input'
import { Search } from 'lucide-react'

export function CreateWizardLeftColumn({
  title,
  children,
}: {
  title: string
  children: React.ReactNode
}) {
  return (
    <div className="lg:col-span-2 flex flex-col">
      <h2 className="text-[14px] font-semibold text-foreground mb-4">{title}</h2>
      {children}
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
  return (
    <div className="lg:col-span-3 space-y-4">
      <h2 className="text-[14px] font-semibold text-foreground">{title}</h2>
      {search && (
        <div className="flex items-center gap-2">
          <div className="relative flex-1">
            <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
            <Input
              value={search.value}
              onChange={(e) => search.onChange(e.target.value)}
              placeholder={search.placeholder ?? 'Search templates...'}
              className="h-9 pl-9 text-[13px]"
            />
          </div>
        </div>
      )}
      {children}
    </div>
  )
}
