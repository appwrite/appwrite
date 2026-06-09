import { Terminal as TerminalIcon, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useCliShell } from './CliShellProvider'

export function CliSessionSidebar() {
  const { sessions, activeSessionId, setActiveSessionId, removeSession } =
    useCliShell()

  return (
    <div
      className="flex w-44 shrink-0 flex-col border-l border-border bg-muted/20 py-1 sm:w-48"
      aria-label="Terminal sessions"
    >
      <div className="min-h-0 flex-1 overflow-y-auto">
        {sessions.map((session) => {
          const isActive = session.id === activeSessionId
          const canRemove = sessions.length > 1

          return (
            <div
              key={session.id}
              className={cn(
                'group flex items-center gap-1 px-1',
                'hover:bg-muted/60',
                isActive && 'bg-muted/60',
              )}
            >
              <button
                type="button"
                onClick={() => setActiveSessionId(session.id)}
                className="flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5 text-left text-foreground transition-colors"
                aria-current={isActive ? 'true' : undefined}
              >
                <TerminalIcon
                  className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
                  strokeWidth={isActive ? 3 : 2}
                />
                <span className="truncate text-[12px] font-medium">
                  {session.name}
                </span>
              </button>
              {canRemove ? (
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-7 w-7 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                  onClick={() => removeSession(session.id)}
                  title="Delete terminal"
                  aria-label={`Delete ${session.name}`}
                >
                  <Trash2 className="h-3.5 w-3.5" />
                </Button>
              ) : null}
            </div>
          )
        })}
      </div>
    </div>
  )
}
