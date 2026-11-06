import { useEffect, useMemo, useState } from 'react'
import { X } from 'lucide-react'
import { Command } from '@/components/ui/command'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { usePlatform } from '@/hooks/use-keyboard-shortcuts'
import { DEFAULT_GROUP_LABELS, type CommandKind } from '@/lib/command-center'
import {
  buildShortcutGroups,
  buildShortcutRefGroup,
  dedupeShortcutGroups,
  mergeShortcutGroups,
  type ParsedShortcut,
} from '@/lib/keyboard-shortcuts/display'
import {
  CLI_SHELL_CONSOLE_SHORTCUTS,
  CLI_TERMINAL_INPUT_SHORTCUTS,
} from '@/lib/cli-shell/cli-terminal-shortcuts'
import { POSTGRES_SQL_EDITOR_SHORTCUTS } from '@/lib/postgres-sql-editor-shortcuts'
import { AGENT_SHORTCUTS } from '@/lib/assistant/agent-shortcuts'
import { AppwriterPromo } from '@/components/global/shared/AppwriterPromo'
import { KeyboardLayoutVisualizer } from '@/components/global/shared/KeyboardLayoutVisualizer'
import { ShortcutGlyph } from '@/components/global/shared/ShortcutGlyphs'

interface ShortcutCommand {
  id: string
  label: string
  shortcut?: string
  kind: string
  group?: string
}

interface KeyboardShortcutsViewProps {
  commands: ShortcutCommand[]
  isMobile: boolean
  showTerminalShortcuts?: boolean
  showSqlEditorShortcuts?: boolean
  showAgentShortcuts?: boolean
  onBack: () => void
  onClose: () => void
  onKeyDown: (e: React.KeyboardEvent) => void
}

function ShortcutKeyBadges({
  keys,
  isSequential,
  highlighted = false,
}: {
  keys: string[]
  isSequential?: boolean
  highlighted?: boolean
}) {
  const t = useT()
  return (
    <div dir="ltr" className="flex shrink-0 items-center gap-0.5">
      {keys.map((key, i) => (
        <span key={i} className="flex items-center gap-0.5">
          {isSequential && i > 0 && (
            <span
              className={cn(
                'text-[10px]',
                highlighted
                  ? 'text-primary/70'
                  : 'text-muted-foreground/60',
              )}
            >
              {t('then')}
            </span>
          )}
          <kbd
            className={cn(
              'flex h-5 min-w-[20px] items-center justify-center rounded border px-1.5 text-[10px] font-medium',
              highlighted
                ? 'border-primary/35 bg-primary/15 text-primary'
                : 'border-border bg-muted/50 text-foreground/80 dark:bg-muted/40 dark:text-muted-foreground',
            )}
          >
            <ShortcutGlyph keyLabel={key} />
          </kbd>
        </span>
      ))}
    </div>
  )
}

function getSequentialHighlightKeys(shortcut: ParsedShortcut) {
  if (!shortcut.isSequential) return undefined
  return shortcut.highlightKeys
}

export function KeyboardShortcutsView({
  commands,
  isMobile,
  showTerminalShortcuts = false,
  showSqlEditorShortcuts = false,
  showAgentShortcuts = false,
  onBack,
  onClose,
  onKeyDown,
}: KeyboardShortcutsViewProps) {
  const t = useT()
  const { isMac } = usePlatform()
  const [selectedId, setSelectedId] = useState<string | null>(null)

  const groups = useMemo(() => {
    const catalogCommands = showSqlEditorShortcuts
      ? commands.filter((command) => !command.id.startsWith('postgres.sql.'))
      : commands

    const base = buildShortcutGroups(
      catalogCommands,
      (kind) => DEFAULT_GROUP_LABELS[kind as CommandKind] ?? kind,
      isMac,
    )

    let merged = base

    if (showAgentShortcuts) {
      merged = mergeShortcutGroups(merged, [
        buildShortcutRefGroup('Agent', AGENT_SHORTCUTS, isMac),
      ])
    }

    if (showSqlEditorShortcuts) {
      merged = mergeShortcutGroups(merged, [
        buildShortcutRefGroup('SQL editor', POSTGRES_SQL_EDITOR_SHORTCUTS, isMac),
      ])
    }

    if (showTerminalShortcuts) {
      const terminalConsoleShortcuts = CLI_SHELL_CONSOLE_SHORTCUTS.filter(
        (shortcut) => shortcut.id !== 'terminal.toggle',
      )
      merged = mergeShortcutGroups(merged, [
        buildShortcutRefGroup(
          'Terminal',
          [...terminalConsoleShortcuts, ...CLI_TERMINAL_INPUT_SHORTCUTS],
          isMac,
        ),
      ])
    }

    return dedupeShortcutGroups(merged)
  }, [
    commands,
    isMac,
    showAgentShortcuts,
    showSqlEditorShortcuts,
    showTerminalShortcuts,
  ])

  const allShortcuts = useMemo(
    () => groups.flatMap((group) => group.shortcuts),
    [groups],
  )

  const selectedShortcut = useMemo(
    () => allShortcuts.find((shortcut) => shortcut.id === selectedId) ?? null,
    [allShortcuts, selectedId],
  )

  useEffect(() => {
    if (!selectedId && allShortcuts.length > 0) {
      setSelectedId(allShortcuts[0].id)
    }
  }, [allShortcuts, selectedId])

  return (
    <Command
      className={cn(
        'flex min-h-0 flex-1 flex-col bg-transparent',
        isMobile && 'flex-1',
      )}
      onKeyDown={onKeyDown}
    >
      <div className="flex h-14 shrink-0 items-center border-b border-border px-3">
        <button
          onClick={onBack}
          className="flex h-6 items-center gap-1 rounded bg-accent px-2 text-[11px] font-medium text-muted-foreground hover:bg-accent/80 hover:text-foreground"
        >
          ← {t('Back')}
        </button>
        <h2 className="flex-1 ps-3 text-[14px] font-medium text-foreground">
          {t('Keyboard shortcuts')}
        </h2>
        {isMobile && (
          <button
            onClick={onClose}
            className="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
          >
            <X className="h-5 w-5" />
          </button>
        )}
      </div>

      <div className="flex min-h-0 flex-1 flex-col">
        <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-6">
          <div
            className={cn(
              'gap-x-6 [column-fill:balance]',
              isMobile ? 'columns-1' : 'columns-2',
            )}
          >
            {groups.map((group) => (
              <section
                key={group.label}
                className="mb-5 inline-block w-full break-inside-avoid"
              >
                <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-foreground/55 dark:text-muted-foreground">
                  {t(group.label)}
                </h3>
                <div className="space-y-0.5 rounded-lg border border-border bg-muted/30 p-1 dark:border-border/60 dark:bg-muted/20">
                  {group.shortcuts.map((shortcut) => {
                    const isSelected = selectedId === shortcut.id
                    return (
                      <button
                        key={shortcut.id}
                        type="button"
                        onClick={() => setSelectedId(shortcut.id)}
                        onFocus={() => setSelectedId(shortcut.id)}
                        className={cn(
                          'flex w-full items-center gap-3 rounded-md px-3 py-2 text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-0',
                          isSelected
                            ? 'bg-primary/10 ring-1 ring-inset ring-primary/25'
                            : 'hover:bg-primary/5',
                        )}
                      >
                        <span
                          className={cn(
                            'min-w-0 flex-1 text-[13px] leading-snug',
                            isSelected
                              ? 'font-medium text-foreground'
                              : 'text-foreground/90',
                          )}
                        >
                          {t(shortcut.description)}
                        </span>
                        <ShortcutKeyBadges
                          keys={shortcut.displayKeys}
                          isSequential={shortcut.isSequential}
                          highlighted={isSelected}
                        />
                      </button>
                    )
                  })}
                </div>
              </section>
            ))}
          </div>
        </div>

        {!isMobile && (
          <div className="flex shrink-0 items-stretch border-t border-border/60">
            <KeyboardLayoutVisualizer
              isMac={isMac}
              highlightedKeys={selectedShortcut?.highlightKeys ?? []}
              sequentialHighlightKeys={
                selectedShortcut
                  ? getSequentialHighlightKeys(selectedShortcut)
                  : undefined
              }
              className="min-w-0 flex-1"
              compact
            />
            <AppwriterPromo />
          </div>
        )}
      </div>

      {!isMobile && (
        <div className="flex shrink-0 items-center border-t border-border px-6 py-2">
          <span className="flex items-center gap-1 text-[11px] text-foreground/50 dark:text-muted-foreground/60">
            <kbd className="rounded border border-border bg-muted/50 px-1 py-0.5 text-[10px] text-foreground/80 dark:bg-muted/40 dark:text-muted-foreground">
              esc
            </kbd>
            {t('back')}
          </span>
        </div>
      )}
    </Command>
  )
}

export { ShortcutKeyBadges }
