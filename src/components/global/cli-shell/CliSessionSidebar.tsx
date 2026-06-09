import { useEffect, useRef, useState } from 'react'
import { Loader2, Terminal as TerminalIcon, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import { MAX_CLI_SHELL_SESSION_NAME_LENGTH } from '@/lib/user-prefs-keys'
import { useCliShell } from './CliShellProvider'

export function CliSessionSidebar() {
  const {
    sessions,
    activeSessionId,
    setActiveSessionId,
    removeSession,
    renameSession,
    isSessionRunning,
  } = useCliShell()

  const [editingSessionId, setEditingSessionId] = useState<string | null>(null)
  const [editingName, setEditingName] = useState('')
  const editInputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (!editingSessionId) return
    const raf = requestAnimationFrame(() => {
      editInputRef.current?.focus()
      editInputRef.current?.select()
    })
    return () => cancelAnimationFrame(raf)
  }, [editingSessionId])

  const startEditing = (sessionId: string, currentName: string) => {
    setEditingSessionId(sessionId)
    setEditingName(currentName)
  }

  const commitRename = () => {
    if (!editingSessionId) return
    renameSession(editingSessionId, editingName)
    setEditingSessionId(null)
    setEditingName('')
  }

  return (
    <div
      className="flex w-44 shrink-0 flex-col border-l border-border bg-muted/20 py-1 sm:w-48"
      aria-label="Terminal sessions"
    >
      <div className="min-h-0 flex-1 overflow-y-auto">
        {sessions.map((session) => {
          const isActive = session.id === activeSessionId
          const canRemove = sessions.length > 1
          const isRunning = isSessionRunning(session.id)
          const isEditing = editingSessionId === session.id

          return (
            <div
              key={session.id}
              className={cn(
                'group flex h-9 items-center gap-1 px-1',
                'hover:bg-muted/60',
                isActive && 'bg-muted/60',
              )}
            >
              <div
                role={isEditing ? undefined : 'button'}
                tabIndex={isEditing ? -1 : 0}
                onClick={() => {
                  if (!isEditing) setActiveSessionId(session.id)
                }}
                onKeyDown={(event) => {
                  if (isEditing) return
                  if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault()
                    setActiveSessionId(session.id)
                  }
                }}
                className={cn(
                  'flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 h-9 text-left text-foreground transition-colors',
                  !isEditing && 'cursor-pointer',
                )}
                aria-current={isActive ? 'true' : undefined}
              >
                {isRunning ? (
                  <Loader2
                    className="h-3.5 w-3.5 shrink-0 animate-spin text-muted-foreground"
                    strokeWidth={isActive ? 3 : 2}
                  />
                ) : (
                  <TerminalIcon
                    className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
                    strokeWidth={isActive ? 3 : 2}
                  />
                )}
                <div
                  className="relative min-w-0 flex-1 h-7"
                  onDoubleClick={(event) => {
                    event.stopPropagation()
                    if (!isEditing) startEditing(session.id, session.name)
                  }}
                >
                  <span
                    className={cn(
                      'block truncate text-[12px] font-medium leading-7 h-7',
                      isEditing && 'invisible',
                    )}
                  >
                    {session.name}
                  </span>
                  {isEditing ? (
                    <Input
                      ref={editInputRef}
                      value={editingName}
                      maxLength={MAX_CLI_SHELL_SESSION_NAME_LENGTH}
                      onChange={(event) => setEditingName(event.target.value)}
                      onBlur={commitRename}
                      onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                          event.preventDefault()
                          commitRename()
                        }
                        if (event.key === 'Escape') {
                          event.preventDefault()
                          setEditingSessionId(null)
                          setEditingName('')
                        }
                      }}
                      className="absolute inset-0 h-7 min-h-7 max-h-7 w-full min-w-0 rounded-sm border border-input px-1.5 py-0 text-[12px] leading-7 shadow-none focus-visible:ring-1 focus-visible:ring-ring/50"
                      aria-label="Rename terminal session"
                    />
                  ) : null}
                </div>
              </div>
              {canRemove ? (
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
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
