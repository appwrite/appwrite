import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  usePostgresSavedQueries,
  type PostgresSavedQueryLevel,
} from '@/lib/react-query/hooks/postgres-databases'
import { queryPreviewLabel, usePostgresSidebar } from './PostgresSidebarContext'
import { MAX_SAVED_POSTGRES_QUERY_NAME_LENGTH } from '@/lib/user-prefs-keys'
import { useT } from '@/lib/i18n/translate'

type SavePostgresQueryDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  databaseId: string
  sql: string
  account: { prefs?: Record<string, unknown> } | undefined
  teamId: string | null | undefined
  canSaveTeam: boolean
}

export function SavePostgresQueryDialog({
  open,
  onOpenChange,
  databaseId,
  sql,
  account,
  teamId,
  canSaveTeam,
}: SavePostgresQueryDialogProps) {
  const t = useT()
  const [name, setName] = useState('')
  const [level, setLevel] = useState<PostgresSavedQueryLevel>('user')
  const { setPanel, setSavedQueryLevel, selectSavedQuery, savedQueryLevel } =
    usePostgresSidebar()
  const { addSavedQuery, isAdding, hasTeamLevel } = usePostgresSavedQueries(
    databaseId,
    account,
    teamId,
  )

  useEffect(() => {
    if (!open) return
    setName(queryPreviewLabel(sql))
    setLevel('user')
  }, [open, sql])

  useEffect(() => {
    if (!canSaveTeam && level === 'team') setLevel('user')
  }, [canSaveTeam, level])

  const handleSave = async () => {
    const trimmedName = name.trim()
    if (!trimmedName || isAdding) return
    if (level === 'team' && !canSaveTeam) return
    try {
      const savedLevel = hasTeamLevel ? level : 'user'
      const savedQuery = await addSavedQuery({
        name: trimmedName,
        sql,
        level: savedLevel,
      })
      toast.success(
        savedLevel === 'team' ? 'Query saved for team' : 'Query saved for you',
      )
      if (savedLevel !== savedQueryLevel) {
        setSavedQueryLevel(savedLevel)
      }
      if (savedQuery) {
        selectSavedQuery(savedLevel, savedQuery)
      }
      setPanel('queries')
      onOpenChange(false)
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Save query')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Save this SQL query to reopen it later from the Queries panel.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="space-y-4 px-6 py-4">
          {hasTeamLevel ? (
            <div className="space-y-2">
              <Label className="text-[13px]">{t('Save for')}</Label>
              <div className="flex overflow-hidden rounded-md border border-border">
                <button
                  type="button"
                  onClick={() => setLevel('user')}
                  className={cn(
                    'flex h-9 flex-1 cursor-pointer items-center justify-center text-[12px] transition-colors',
                    level === 'user'
                      ? 'bg-muted text-foreground'
                      : 'text-muted-foreground hover:bg-muted/60',
                  )}
                  aria-pressed={level === 'user'}
                >
                  {t('For me')}
                </button>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      type="button"
                      onClick={() => canSaveTeam && setLevel('team')}
                      disabled={!canSaveTeam}
                      className={cn(
                        'flex h-9 flex-1 cursor-pointer items-center justify-center border-s border-border text-[12px] transition-colors disabled:cursor-not-allowed',
                        level === 'team'
                          ? 'bg-muted text-foreground'
                          : 'text-muted-foreground hover:bg-muted/60 disabled:opacity-50',
                      )}
                      aria-pressed={level === 'team'}
                    >
                      {t('For team')}
                    </button>
                  </TooltipTrigger>
                  {!canSaveTeam ? (
                    <TooltipContent side="top" sideOffset={4}>
                      {t('Only owners and developers can save team queries.')}
                    </TooltipContent>
                  ) : null}
                </Tooltip>
              </div>
            </div>
          ) : null}
          <div className="space-y-2">
            <Label htmlFor="postgres-query-name" className="text-[13px]">
              {t('Query name')}
            </Label>
            <Input
              id="postgres-query-name"
              value={name}
              onChange={(event) => setName(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault()
                  void handleSave()
                }
              }}
              className="h-9 text-[13px]"
              maxLength={MAX_SAVED_POSTGRES_QUERY_NAME_LENGTH}
              disabled={isAdding}
            />
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isAdding}
          >
            {t('Cancel')}
          </Button>
          <Button
            type="button"
            onClick={() => void handleSave()}
            disabled={!name.trim() || isAdding}
          >
            {t('Save')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
