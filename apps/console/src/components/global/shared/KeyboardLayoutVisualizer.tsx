import { Fingerprint } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import type { KeyId } from '@/lib/keyboard-shortcuts/display'
import {
  ARROW_CLUSTER,
  getMacModifierRow,
  getMainKeyboardRows,
  getWinModifierRow,
  type KeySpec,
} from '@/lib/keyboard-shortcuts/layout'

interface KeyboardLayoutVisualizerProps {
  isMac: boolean
  highlightedKeys: KeyId[]
  /** Keys lit in order for sequential shortcuts (e.g. g then o). */
  sequentialHighlightKeys?: KeyId[]
  className?: string
  /** Tighter padding and centered keyboard for wide modal footer. */
  compact?: boolean
}

function WindowsKeyIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      className={cn('h-3.5 w-3.5 shrink-0', className)}
      fill="currentColor"
      aria-hidden
    >
      <path d="M3 5.5 10.5 4.1V11H3V5.5zm8.5 0L21 3.5V11h-9.5V5.5zM3 13h7.5v6.9L3 18.4V13zm9.5 0H21v7.5l-8.5 2.4V13z" />
    </svg>
  )
}

function KeyCap({
  keySpec,
  isHighlighted,
  sequenceStep,
  className,
  compact = false,
}: {
  keySpec: KeySpec
  isHighlighted: boolean
  sequenceStep?: number
  className?: string
  compact?: boolean
}) {
  if (keySpec.spacer) {
    return (
      <div
        style={{ flex: keySpec.flex ?? 1 }}
        className={className}
        aria-hidden
      />
    )
  }

  const isSequential = sequenceStep !== undefined
  const hideLabel = keySpec.id === 'space' && !isHighlighted
  const isTouchId = keySpec.id === 'touchId'
  const isWindowsKey = keySpec.id === 'winLeft' || keySpec.id === 'winRight'

  return (
    <div
      style={keySpec.flex != null ? { flex: keySpec.flex } : undefined}
      className={cn(
        'flex min-w-0 items-center justify-center rounded-md border font-medium transition-colors duration-150',
        compact ? 'h-full px-0 text-[8px]' : 'h-8 px-0.5 text-[9px]',
        keySpec.flex == null && 'w-full',
        isHighlighted
          ? 'border-primary/40 bg-primary text-primary-foreground shadow-sm'
          : cn(
              'border-border bg-card text-foreground/75 shadow-sm',
              'dark:border-border/50 dark:bg-background/40 dark:text-muted-foreground dark:shadow-none dark:backdrop-blur-sm',
            ),
        className,
      )}
      title={
        isTouchId ? 'Touch ID' : isWindowsKey ? 'Windows' : undefined
      }
    >
      {isTouchId ? (
        <Fingerprint className="h-3.5 w-3.5 shrink-0" strokeWidth={2} />
      ) : isWindowsKey ? (
        <WindowsKeyIcon />
      ) : (
        <span className={cn('truncate px-0.5', hideLabel && 'text-transparent')}>
          {isSequential && isHighlighted
            ? `${sequenceStep + 1}. ${keySpec.label}`
            : keySpec.label}
        </span>
      )}
    </div>
  )
}

function ArrowCluster({
  highlighted,
  sequentialIndex,
}: {
  highlighted: Set<KeyId>
  sequentialIndex: Map<KeyId, number>
}) {
  const [up, left, down, right] = ARROW_CLUSTER

  return (
    <div className="grid h-8 w-[4.75rem] shrink-0 grid-cols-3 gap-0.5 self-end">
      <KeyCap
        keySpec={left}
        isHighlighted={highlighted.has(left.id)}
        sequenceStep={sequentialIndex.get(left.id)}
      />
      <div className="flex h-8 min-h-0 flex-col gap-0.5">
        <KeyCap
          keySpec={up}
          isHighlighted={highlighted.has(up.id)}
          sequenceStep={sequentialIndex.get(up.id)}
          compact
          className="min-h-0 flex-1"
        />
        <KeyCap
          keySpec={down}
          isHighlighted={highlighted.has(down.id)}
          sequenceStep={sequentialIndex.get(down.id)}
          compact
          className="min-h-0 flex-1"
        />
      </div>
      <KeyCap
        keySpec={right}
        isHighlighted={highlighted.has(right.id)}
        sequenceStep={sequentialIndex.get(right.id)}
      />
    </div>
  )
}

export function KeyboardLayoutVisualizer({
  isMac,
  highlightedKeys,
  sequentialHighlightKeys,
  className,
  compact = false,
}: KeyboardLayoutVisualizerProps) {
  const t = useT()
  const mainRows = getMainKeyboardRows(isMac)
  const modifierRow = isMac ? getMacModifierRow() : getWinModifierRow()
  const highlighted = new Set(highlightedKeys)
  const sequential = sequentialHighlightKeys ?? []
  const sequentialIndex = new Map<KeyId, number>()
  sequential.forEach((key, index) => {
    if (!sequentialIndex.has(key)) sequentialIndex.set(key, index)
  })

  return (
    <div
      className={cn(
        compact ? 'flex min-h-0 flex-col px-6 py-3' : 'border-t border-border/40 px-3 py-3',
        className,
      )}
      aria-hidden
    >
      <div className="mb-2 flex shrink-0 items-center justify-between px-0.5">
        <span className="text-[11px] font-medium uppercase tracking-wider text-foreground/60 dark:text-muted-foreground">
          {t('Keyboard layout')}
        </span>
        {sequential.length > 1 && (
          <span className="text-[11px] text-muted-foreground">
            {t('Press keys in order')}
          </span>
        )}
      </div>
      <div className={cn(compact && 'flex min-h-0 flex-1 items-center justify-center')}>
        <div
          className={cn(
            'rounded-xl border p-3 shadow-sm backdrop-blur-md',
            'border-border/80 bg-muted/40',
            'dark:border-border/50 dark:bg-background/35',
            compact ? 'w-full max-w-[640px]' : 'min-w-[580px]',
          )}
        >
          <div className="overflow-x-auto pb-0.5">
            <div className="space-y-1">
              {mainRows.map((row, rowIndex) => (
                <div key={rowIndex} className="flex gap-1">
                  {row.keys.map((key) => (
                    <KeyCap
                      key={key.id}
                      keySpec={key}
                      isHighlighted={highlighted.has(key.id)}
                      sequenceStep={sequentialIndex.get(key.id)}
                    />
                  ))}
                </div>
              ))}

              <div className="flex items-end gap-1">
                <div className="flex min-w-0 flex-1 gap-1">
                  {modifierRow.keys.map((key) => (
                    <KeyCap
                      key={key.id}
                      keySpec={key}
                      isHighlighted={highlighted.has(key.id)}
                      sequenceStep={sequentialIndex.get(key.id)}
                    />
                  ))}
                </div>
                <ArrowCluster
                  highlighted={highlighted}
                  sequentialIndex={sequentialIndex}
                />
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
