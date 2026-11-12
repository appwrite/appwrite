import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { X, Plus } from 'lucide-react'
import { EventEditorModal } from '@/components/global/shared/EventEditor'
import { DOCS_LINK } from '@/lib/events-editor/events-model'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'

interface EventSelectorProps {
  projectId?: string | null
  selectedEvents: string[]
  onEventsChange: (events: string[]) => void
  maxEvents?: number
}

export function EventSelector({
  projectId,
  selectedEvents,
  onEventsChange,
  maxEvents = 100,
}: EventSelectorProps) {
  const t = useT()
  const [eventDialogOpen, setEventDialogOpen] = useState(false)

  const handleCreated = (eventString: string) => {
    const trimmed = eventString.trim()
    if (!trimmed || selectedEvents.includes(trimmed)) return
    if (selectedEvents.length >= maxEvents) return
    onEventsChange([...selectedEvents, trimmed])
    setEventDialogOpen(false)
  }

  const handleRemoveEvent = (event: string) => {
    onEventsChange(selectedEvents.filter((e) => e !== event))
  }

  const handleOpenEventDialog = () => {
    openDialogAfterOverlayCloses(() => setEventDialogOpen(true))
  }

  return (
    <div className="space-y-4">
      <div>
        <p className="text-[13px] text-muted-foreground mb-2">
          {t('Set the events that will trigger your webhook. Maximum')}{' '}
          {maxEvents} {t('events allowed.')}{' '}
          <DocsRouteLink className="link-neutral" href={DOCS_LINK}>
            {t('Learn more')}
          </DocsRouteLink>
        </p>
        {selectedEvents.length > 0 ? (
          <div className="flex flex-wrap gap-2">
            {selectedEvents.map((event) => (
              <Badge key={event} variant="secondary" className="gap-1.5">
                {event}
                <button
                  type="button"
                  onClick={(e) => {
                    e.stopPropagation()
                    handleRemoveEvent(event)
                  }}
                  className="ms-1 rounded-full hover:bg-muted"
                >
                  <X className="h-3 w-3" />
                </button>
              </Badge>
            ))}
          </div>
        ) : (
          <p className="text-[13px] text-muted-foreground">
            {t('No events selected')}
          </p>
        )}
      </div>
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={handleOpenEventDialog}
        disabled={selectedEvents.length >= maxEvents}
      >
        <Plus className="me-1.5 h-4 w-4" />
        {t('Add event')}
      </Button>

      <EventEditorModal
        open={eventDialogOpen}
        onOpenChange={setEventDialogOpen}
        onCreated={handleCreated}
        description={t('Select events that will trigger your webhook.')}
        projectId={projectId}
      />
    </div>
  )
}
