import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { WarningAlert } from '@/components/global/shared/WarningAlert'
import { cn } from '@/lib/utils'

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
  selectedIds: Set<string>
  onToggle: (id: string) => void
  loading?: boolean
}

export function DowngradeLimitSelection({
  title,
  description,
  resourceLabel,
  limit,
  items,
  selectedIds,
  onToggle,
  loading = false,
}: DowngradeLimitSelectionProps) {
  const selectionValid = selectedIds.size === limit

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
            {selectedIds.size} / {limit}
          </p>
        </div>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4 space-y-4">
        {!selectionValid ? (
          <WarningAlert title={`Select ${resourceLabel} to keep`}>
            Choose exactly {limit} {resourceLabel} to continue.
          </WarningAlert>
        ) : null}

        {loading ? (
          <p className="text-[13px] text-muted-foreground">Loading...</p>
        ) : (
          <div className="space-y-2">
            {items.map((item) => {
              const selected = selectedIds.has(item.id)
              const disabled =
                item.locked || (!selected && selectedIds.size >= limit)

              return (
                <div
                  key={item.id}
                  className={cn(
                    'flex items-start gap-3 rounded-lg border p-3 transition-colors',
                    selected
                      ? 'border-primary bg-primary/5'
                      : 'border-border bg-background/60',
                    disabled && !item.locked && 'opacity-50',
                  )}
                >
                  <Checkbox
                    id={`keep-${resourceLabel}-${item.id}`}
                    checked={selected}
                    disabled={disabled}
                    onCheckedChange={() => onToggle(item.id)}
                    className="mt-0.5 shrink-0"
                  />
                  <Label
                    htmlFor={`keep-${resourceLabel}-${item.id}`}
                    className={cn(
                      'min-w-0 flex-1',
                      disabled ? 'cursor-not-allowed' : 'cursor-pointer',
                    )}
                  >
                    <div className="flex items-start gap-2 min-w-0">
                      <p className="min-w-0 truncate text-[13px] font-medium leading-normal text-foreground">
                        {item.label}
                      </p>
                      {item.locked ? (
                        <Badge variant="info" className="text-[10px] shrink-0">
                          You
                        </Badge>
                      ) : null}
                    </div>
                    {item.description ? (
                      <p className="min-w-0 truncate text-[12px] leading-normal text-muted-foreground mt-0.5">
                        {item.description}
                      </p>
                    ) : null}
                  </Label>
                </div>
              )
            })}
          </div>
        )}
      </div>
    </div>
  )
}
