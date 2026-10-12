import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const SYNC_MODE_OPTIONS = [
  {
    value: 'async',
    label: 'Asynchronous',
    summary: 'Fastest writes; replicas may lag briefly.',
  },
  {
    value: 'sync',
    label: 'Synchronous',
    summary: 'Strongest durability; higher write latency.',
  },
  {
    value: 'quorum',
    label: 'Quorum',
    summary: 'Majority confirm; balanced for HA.',
  },
] as const

type MysqlReplicationSyncModePickerProps = {
  syncMode: string
  onSyncModeChange: (value: string) => void
  disabled?: boolean
}

export function MysqlReplicationSyncModePicker({
  syncMode,
  onSyncModeChange,
  disabled = false,
}: MysqlReplicationSyncModePickerProps) {
  const t = useT()

  return (
    <Table>
      <TableHeader>
        <TableRow className="hover:bg-transparent border-b border-border">
          <TableHead className="w-[40px] px-6 py-3" />
          <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Mode')}
          </TableHead>
          <TableHead className="px-6 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Summary')}
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {SYNC_MODE_OPTIONS.map((option) => {
          const isSelected = syncMode === option.value
          return (
            <TableRow
              key={option.value}
              tabIndex={disabled ? -1 : 0}
              aria-selected={isSelected}
              onClick={() => {
                if (disabled) return
                onSyncModeChange(option.value)
              }}
              onKeyDown={(event) => {
                if (disabled) return
                if (event.key === 'Enter' || event.key === ' ') {
                  event.preventDefault()
                  onSyncModeChange(option.value)
                }
              }}
              className={cn(
                'cursor-pointer border-b border-border last:border-b-0',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                disabled && 'cursor-not-allowed opacity-50',
                isSelected && 'bg-primary/5',
              )}
            >
              <TableCell className="px-6 py-3">
                <div
                  className={cn(
                    'flex h-4 w-4 items-center justify-center rounded-full border',
                    isSelected
                      ? 'border-primary bg-primary'
                      : 'border-muted-foreground/40 bg-background',
                  )}
                >
                  {isSelected ? (
                    <span className="h-1.5 w-1.5 rounded-full bg-primary-foreground" />
                  ) : null}
                </div>
              </TableCell>
              <TableCell className="px-4 py-3 text-[13px] font-medium text-foreground">
                {t(option.label)}
              </TableCell>
              <TableCell className="px-6 py-3 text-[13px] text-muted-foreground">
                {t(option.summary)}
              </TableCell>
            </TableRow>
          )
        })}
      </TableBody>
    </Table>
  )
}
