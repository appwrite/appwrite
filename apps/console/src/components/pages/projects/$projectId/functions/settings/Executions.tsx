import { useState } from 'react'
import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import {
  useProjectFunction,
  buildFunctionUpdateParams,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Plus, X } from 'lucide-react'
import { EventEditorModal } from '@/components/global/shared/EventEditor'
import { DOCS_LINK as EVENTS_DOCS_LINK } from '@/lib/events-editor/events-model'
import { CronScheduleEditor } from '../CronScheduleEditor'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { ScopeEditor } from '@/components/global/shared/ScopeEditor'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { projectId, functionId } = useParams({ strict: false })
  const queryClient = useQueryClient()

  const { data: func, isLoading: funcLoading } = useProjectFunction(
    projectId,
    functionId,
  )

  const [schedule, setSchedule] = useSyncStateFromServer(func?.schedule || '')
  const [events, setEvents] = useSyncStateFromServer(func?.events || [])
  const [scopes, setScopes] = useSyncStateFromServer(func?.scopes || [])
  const [eventDialogOpen, setEventDialogOpen] = useState(false)

  const syncFunctionCache = (updated: Models.Function) => {
    queryClient.setQueryData(
      ['function', 'project', projectId, functionId],
      updated,
    )
    queryClient.invalidateQueries({
      queryKey: ['functions', 'project', projectId],
    })
  }

  const scheduleMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Function>) => {
      if (!projectId || !functionId || !func)
        throw new Error('Project ID, Function ID, and Function are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update(
        buildFunctionUpdateParams(func, updates),
      )
    },
    onSuccess: (updated) => {
      toast.success(t('Schedule updated successfully'))
      syncFunctionCache(updated)
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update schedule')))
    },
  })

  const eventsMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Function>) => {
      if (!projectId || !functionId || !func)
        throw new Error('Project ID, Function ID, and Function are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update(
        buildFunctionUpdateParams(func, updates),
      )
    },
    onSuccess: (updated) => {
      toast.success(t('Events updated successfully'))
      syncFunctionCache(updated)
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update events')))
    },
  })

  const scopesMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Function>) => {
      if (!projectId || !functionId || !func)
        throw new Error('Project ID, Function ID, and Function are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update(
        buildFunctionUpdateParams(func, updates),
      )
    },
    onSuccess: (updated) => {
      toast.success(t('Function updated successfully'))
      syncFunctionCache(updated)
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update function')))
    },
  })

  const handleSaveSchedule = () => {
    scheduleMutation.mutate({ schedule: schedule || undefined })
  }

  const handleSaveEvents = () => {
    if (events.length > 100) {
      toast.error(t('Maximum 100 events allowed'))
      return
    }
    eventsMutation.mutate({ events })
  }

  const handleSaveScopes = () => {
    scopesMutation.mutate({ scopes })
  }

  const handleEventCreated = (eventString: string) => {
    const trimmed = eventString.trim()
    if (!trimmed || events.includes(trimmed) || events.length >= 100) return
    setEvents([...events, trimmed])
    setEventDialogOpen(false)
  }

  const handleRemoveEvent = (event: string) => {
    setEvents(events.filter((e) => e !== event))
  }

  const arraysEqual = (a: string[], b: string[]) => {
    if (a.length !== b.length) return false
    return a.every((val, idx) => val === b[idx])
  }

  // Scope order is not meaningful, so compare as sets.
  const savedScopes = new Set(func?.scopes || [])
  const draftScopes = new Set(scopes)
  const scopesChanged =
    draftScopes.size !== savedScopes.size ||
    [...draftScopes].some((scope) => !savedScopes.has(scope))

  // Each save sends the whole function, so only one may be in flight or the
  // later request would restore the other card's old value.
  const executionsPending =
    scheduleMutation.isPending ||
    eventsMutation.isPending ||
    scopesMutation.isPending

  if (funcLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">
          {t('Loading settings...')}
        </p>
      </div>
    )
  }

  if (!func) return null

  const cards: SettingsCardItem[] = [
    {
      id: 'schedule',
      search: {
        title: 'Schedule',
        description: 'Run this function on a schedule using cron expressions.',
        keywords: ['cron', 'scheduled', 'recurring'],
      },
      node: (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Schedule')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t('Run this function on a schedule using cron expressions.')}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <CronScheduleEditor
              value={schedule}
              onChange={setSchedule}
              disabled={executionsPending}
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={schedule === (func.schedule || '') || executionsPending}
              onClick={handleSaveSchedule}
            >
              {t('Update')}
            </Button>
          </div>
        </div>
      ),
    },
    {
      id: 'events',
      search: {
        title: 'Events',
        description: 'Events that trigger this function (maximum 100).',
        keywords: ['webhook', 'trigger', 'invoke', 'async'],
      },
      node: (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Events')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t('Events that trigger this function (maximum 100).')}{' '}
              <DocsRouteLink className="link-neutral" href={EVENTS_DOCS_LINK}>
                {t('Learn more')}
              </DocsRouteLink>
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="space-y-3">
              <Button
                type="button"
                size="sm"
                variant="outline"
                className="h-9 text-[13px]"
                onClick={() => setEventDialogOpen(true)}
                disabled={events.length >= 100 || executionsPending}
              >
                <Plus className="me-1.5 h-4 w-4" />
                {t('Add event')}
              </Button>
              {events.length > 0 && (
                <div className="space-y-2">
                  {events.map((event) => (
                    <div
                      key={event}
                      className="flex items-center justify-between rounded-md border border-border bg-background px-3 py-2"
                    >
                      <span className="text-[13px] font-mono">{event}</span>
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 w-7 p-0"
                        onClick={() => handleRemoveEvent(event)}
                        disabled={executionsPending}
                        aria-label={`${t('Remove event')} ${event}`}
                      >
                        <X className="h-4 w-4" />
                      </Button>
                    </div>
                  ))}
                </div>
              )}
              {events.length === 0 && (
                <p className="text-[13px] text-muted-foreground">
                  {t('No events configured')}
                </p>
              )}
              <EventEditorModal
                open={eventDialogOpen}
                onOpenChange={setEventDialogOpen}
                onCreated={handleEventCreated}
                description={t(
                  'Set the events that will trigger your function. Maximum 100 events allowed.',
                )}
                projectId={projectId}
              />
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                arraysEqual(events, func.events || []) || executionsPending
              }
              onClick={handleSaveEvents}
            >
              {t('Update')}
            </Button>
          </div>
        </div>
      ),
    },
    {
      id: 'scopes',
      search: {
        title: 'Scopes',
        description:
          'Choose what the API key generated for each execution is allowed to do.',
        keywords: [
          'scope',
          'permission',
          'api key',
          'ephemeral key',
          'execution',
          'access',
        ],
      },
      node: (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Scopes')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t(
                'Select scopes to grant the ephemeral key generated for your function. It is best practice to allow only necessary permissions.',
              )}{' '}
              <DocsRouteLink
                className="link-neutral"
                href="/docs/advanced/platform/api-keys#scopes"
              >
                {t('Learn more')}
              </DocsRouteLink>
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <ScopeEditor
              value={scopes}
              onChange={setScopes}
              disabled={executionsPending}
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={!scopesChanged || executionsPending}
              onClick={handleSaveScopes}
            >
              {t('Update')}
            </Button>
          </div>
        </div>
      ),
    },
  ]

  return <SettingsCardsList cards={cards} />
}
