import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { WarningAlert } from '@/components/global/shared/WarningAlert'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const SELECTION_PAGE_SIZE = 5
const SELECTION_ROW_HEIGHT_CLASS = 'h-[46px]'
const SELECTION_LIST_MIN_HEIGHT_CLASS = 'min-h-[262px]'

export type DowngradeLimitSelectionItem = {
  id: string
  label: string
  description?: string
  locked?: boolean
}

interface DowngradeLimitSelectionProps {
  title: string
  description: string
  resourceLabel: string
  limit: number
  items: DowngradeLimitSelectionItem[]
  total: number
  page: number
  selectedIds: Set<string>
  onToggle: (id: string) => void
  onDeleteSelected: () => void
  onPageChange: (page: number) => void
  loading?: boolean
  deleting?: boolean
  paginationDisabled?: boolean
}

export function DowngradeLimitSelection({
  title,
  description,
  resourceLabel,
  limit,
  items,
  total,
  page,
  selectedIds,
  onToggle,
  onDeleteSelected,
  onPageChange,
  loading = false,
  deleting = false,
  paginationDisabled = false,
}: DowngradeLimitSelectionProps) {
  const t = useT()
  const overage = Math.max(0, total - limit)
  const stillOver = overage > 0
  const totalPages = Math.max(1, Math.ceil(total / SELECTION_PAGE_SIZE))
  const safePage = Math.min(page, totalPages)
  const pageStart = (safePage - 1) * SELECTION_PAGE_SIZE
  const pageEnd = Math.min(pageStart + items.length, total)

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h3 className="text-[15px] font-semibold leading-normal text-foreground">
              {title}
            </h3>
            <p className="text-[13px] leading-normal text-muted-foreground mt-2">
              {description}
            </p>
          </div>
          <p className="text-[13px] font-medium leading-normal text-foreground shrink-0">
            {total}/{limit}
          </p>
        </div>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4 space-y-4">
        {stillOver ? (
          <WarningAlert title={t('Still over the plan limit.')}>
            {t('Delete at least')} {overage} {t(resourceLabel)}{' '}
            {t('to fit the selected plan.')}
          </WarningAlert>
        ) : null}

        <div className={cn('space-y-2', SELECTION_LIST_MIN_HEIGHT_CLASS)}>
          {loading ? (
            <p className="text-[13px] text-muted-foreground">{t('Loading...')}</p>
          ) : (
            <>
              {items.map((item) => {
                const selected = selectedIds.has(item.id)

                return (
                  <div
                    key={item.id}
                    className={cn(
                      'flex items-start gap-3 rounded-lg border p-3 transition-colors',
                      SELECTION_ROW_HEIGHT_CLASS,
                      selected
                        ? 'border-primary bg-primary/5'
                        : 'border-border bg-background/60',
                      item.locked && 'opacity-50',
                    )}
                  >
                    <Checkbox
                      id={`delete-${resourceLabel}-${item.id}`}
                      checked={selected}
                      disabled={item.locked}
                      onCheckedChange={() => onToggle(item.id)}
                      className="mt-0.5 shrink-0"
                    />
                    <Label
                      htmlFor={`delete-${resourceLabel}-${item.id}`}
                      className={cn(
                        'min-w-0 flex-1',
                        item.locked ? 'cursor-not-allowed' : 'cursor-pointer',
                      )}
                    >
                      <div className="flex items-center gap-2 min-w-0">
                        <p className="min-w-0 truncate text-[13px] font-medium leading-normal text-foreground">
                          {item.label}
                        </p>
                        {item.locked ? (
                          <Badge
                            variant="info"
                            className="text-[10px] shrink-0"
                          >
                            {t('You')}
                          </Badge>
                        ) : null}
                      </div>
                    </Label>
                  </div>
                )
              })}
              {Array.from({
                length: Math.max(0, SELECTION_PAGE_SIZE - items.length),
              }).map((_, index) => (
                <div
                  key={`limit-selection-spacer-${index}`}
                  className={SELECTION_ROW_HEIGHT_CLASS}
                  aria-hidden
                />
              ))}
            </>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
          {total > SELECTION_PAGE_SIZE ? (
            <>
              <p className="text-[12px] text-muted-foreground">
                {t('Showing')} {pageStart + 1}-{pageEnd} {t('of')} {total}{' '}
                {t(resourceLabel)}
              </p>
              <div className="flex items-center gap-1">
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  className="h-8 w-8"
                  onClick={() => onPageChange(safePage - 1)}
                  disabled={safePage <= 1 || loading || paginationDisabled}
                  aria-label={`${t('Previous')} ${t(resourceLabel)} ${t('page')}`}
                >
                  <ChevronLeft className="h-3.5 w-3.5" />
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  className="h-8 w-8"
                  onClick={() => onPageChange(safePage + 1)}
                  disabled={
                    safePage >= totalPages || loading || paginationDisabled
                  }
                  aria-label={`${t('Next')} ${t(resourceLabel)} ${t('page')}`}
                >
                  <ChevronRight className="h-3.5 w-3.5" />
                </Button>
              </div>
            </>
          ) : (
            <span />
          )}
          <Button
            type="button"
            size="sm"
            className="h-8 text-[13px]"
            disabled={selectedIds.size === 0 || deleting}
            onClick={onDeleteSelected}
          >
            {t('Delete selected')}
          </Button>
        </div>
      </div>
    </div>
  )
}
