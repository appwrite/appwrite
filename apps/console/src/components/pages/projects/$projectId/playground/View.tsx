import { useEffect, useRef } from 'react'
import { useNavigate, useParams, useSearch } from '@tanstack/react-router'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { ServiceHeader } from '../shared/ServiceHeader'
import { Rest } from './Rest'
import { Realtime } from './Realtime'
import { usePlaygroundMode } from '@/hooks/use-playground-mode'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes, useProject } from '@/lib/react-query/hooks'
import { canShowConnectSection } from '@/lib/console-access-checks'
import type { PlaygroundMode } from '@/lib/user-prefs-keys'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const MODE_OPTIONS: { value: PlaygroundMode; label: string }[] = [
  { value: 'rest', label: 'REST' },
  { value: 'realtime', label: 'Realtime' },
]

const TOGGLE_ITEM_CLASS =
  'h-full min-w-[5.5rem] px-3 text-[12px] font-medium text-muted-foreground hover:text-foreground data-[state=on]:bg-secondary data-[state=on]:text-secondary-foreground data-[state=on]:hover:bg-secondary data-[state=on]:hover:text-secondary-foreground'

export function View() {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const search = useSearch({ strict: false }) as {
    service?: string
    operation?: string
  }
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access, isLoading: scopesLoading } = useOrganizationScopes(
    project?.teamId,
  )
  const { mode: savedMode, setMode } = usePlaygroundMode()

  const canUseRest = scopesLoading || canShowConnectSection(access, features)
  const mode = canUseRest ? savedMode : 'realtime'

  // A link to a specific REST method opens REST, regardless of the saved mode.
  const deepLinkHandledRef = useRef(false)
  useEffect(() => {
    if (deepLinkHandledRef.current) return
    if (!search.service && !search.operation) return
    if (!canUseRest) return
    deepLinkHandledRef.current = true
    setMode('rest')
  }, [canUseRest, search.operation, search.service, setMode])

  // The URL only carries REST state; keep it aside while Realtime is open.
  const restSearchRef = useRef<{
    service: string | undefined
    operation: string | undefined
  }>({ service: undefined, operation: undefined })
  const handleModeChange = (next: PlaygroundMode) => {
    if (!projectId || next === mode) return
    if (next === 'realtime') {
      restSearchRef.current = {
        service: search.service,
        operation: search.operation,
      }
    }
    setMode(next)
    navigate({
      to: '/projects/$projectId/playground',
      params: { projectId },
      search:
        next === 'rest'
          ? restSearchRef.current
          : { service: undefined, operation: undefined },
      replace: true,
    })
  }

  if (!projectId) return null

  return (
    <div className="flex h-full min-h-0 flex-col">
      <ServiceHeader
        title={t('Playground')}
        fullWidthBorder
        fullWidth
        titleCenterContent={
          canUseRest ? (
            <ToggleGroup
              type="single"
              variant="outline"
              size="sm"
              value={mode}
              onValueChange={(next) => {
                if (next === 'rest' || next === 'realtime') {
                  handleModeChange(next)
                }
              }}
              className="h-8 w-fit shrink-0 gap-0"
              aria-label={t('Playground mode')}
            >
              {MODE_OPTIONS.map((option, index) => (
                <ToggleGroupItem
                  key={option.value}
                  value={option.value}
                  className={cn(
                    TOGGLE_ITEM_CLASS,
                    '!rounded-none shadow-none',
                    index === 0 && '!rounded-s-md !border-s',
                    index === MODE_OPTIONS.length - 1 && '!rounded-e-md',
                    index !== 0 && '!border-s-0',
                  )}
                >
                  {option.label}
                </ToggleGroupItem>
              ))}
            </ToggleGroup>
          ) : undefined
        }
      />
      {mode === 'rest' ? <Rest /> : <Realtime />}
    </div>
  )
}
