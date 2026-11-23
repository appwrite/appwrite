import { LayoutGrid, List } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import type { ServiceListViewMode } from '@/lib/user-prefs-keys'
import { useT } from '@/lib/i18n/translate'

type ServiceListViewToggleProps = {
  viewMode: ServiceListViewMode
  onViewModeChange: (mode: ServiceListViewMode) => void
}

export function ServiceListViewToggle({
  viewMode,
  onViewModeChange,
}: ServiceListViewToggleProps) {
  const t = useT()
  const buttonClassName = (selected: boolean) =>
    cn(
      'h-7 w-7 p-0',
      selected
        ? 'bg-background text-foreground shadow-sm'
        : 'text-muted-foreground hover:bg-transparent hover:text-foreground',
    )

  return (
    <div className="flex items-center rounded-md border border-border bg-muted/30 p-0.5">
      <Button
        variant="ghost"
        size="sm"
        type="button"
        className={buttonClassName(viewMode === 'list')}
        onClick={() => onViewModeChange('list')}
        aria-label={t('List view')}
        aria-pressed={viewMode === 'list'}
      >
        <List className="h-4 w-4" />
      </Button>
      <Button
        variant="ghost"
        size="sm"
        type="button"
        className={buttonClassName(viewMode === 'grid')}
        onClick={() => onViewModeChange('grid')}
        aria-label={t('Grid view')}
        aria-pressed={viewMode === 'grid'}
      >
        <LayoutGrid className="h-4 w-4" />
      </Button>
    </div>
  )
}
