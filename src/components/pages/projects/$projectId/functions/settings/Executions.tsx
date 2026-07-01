import { useState, useEffect } from 'react'
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
import { DOCS_LINK as EVENTS_DOCS_LINK } from '@/lib/events-editor'
import { CronScheduleEditor } from '../CronScheduleEditor'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'

export function View() {
  const { projectId, functionId } = useParams({ strict: false })
  const queryClient = useQueryClient()

  const { data: func, isLoading: funcLoading } = useProjectFunction(
    projectId,
    functionId,
  )

  const [schedule, setSchedule] = useState('')
  const [events, setEvents] = useState<string[]>([])
  const [eventDialogOpen, setEventDialogOpen] = useState(false)

  useEffect(() => {
    if (func) {
      setSchedule(func.schedule || '')
      setEvents(func.events || [])
    }
  }, [func])

  const invalidateFunction = () => {
    queryClient.invalidateQueries({
      queryKey: ['function', 'project', projectId, functionId],
    })
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
    onSuccess: () => {
      toast.success('Schedule updated successfully')
      invalidateFunction()
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, 'Failed to update schedule'))
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
    onSuccess: () => {
      toast.success('Events updated successfully')
      invalidateFunction()
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, 'Failed to update events'))
    },
  })

  const handleSaveSchedule = () => {
    scheduleMutation.mutate({ schedule: schedule || undefined })
  }

  const handleSaveEvents = () => {
    if (events.length > 100) {
      toast.error('Maximum 100 events allowed')
      return
    }
    eventsMutation.mutate({ events })
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

  const executionsPending =
    scheduleMutation.isPending || eventsMutation.isPending

  if (funcLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">Loading settings...</p>
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
          <h3 className="text-[15px] font-semibold text-foreground">Schedule</h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            Run this function on a schedule using cron expressions.
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
            disabled={
              schedule === (func.schedule || '') || scheduleMutation.isPending
            }
            onClick={handleSaveSchedule}
          >
            Update
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
          <h3 className="text-[15px] font-semibold text-foreground">Events</h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            Events that trigger this function (maximum 100).{' '}
            <DocsRouteLink className="link-neutral" href={EVENTS_DOCS_LINK}>
              Learn more
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
              Add event
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
                      aria-label={`Remove event ${event}`}
                    >
                      <X className="h-4 w-4" />
                    </Button>
                  </div>
                ))}
              </div>
            )}
            {events.length === 0 && (
              <p className="text-[13px] text-muted-foreground">
                No events configured
              </p>
            )}
            <EventEditorModal
              open={eventDialogOpen}
              onOpenChange={setEventDialogOpen}
              onCreated={handleEventCreated}
              description="Set the events that will trigger your function. Maximum 100 events allowed."
              projectId={projectId}
            />
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={
              arraysEqual(events, func.events || []) ||
              eventsMutation.isPending
            }
            onClick={handleSaveEvents}
          >
            Update
          </Button>
        </div>
      </div>
      ),
    },
  ]

  return <SettingsCardsList cards={cards} />
}
