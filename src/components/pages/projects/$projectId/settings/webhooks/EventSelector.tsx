import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { X, Plus } from 'lucide-react'
import { EventEditorModal } from '@/components/global/shared/EventEditor'
import { DOCS_LINK } from '@/lib/events-editor'

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

  return (
    <div className="space-y-4">
      <div>
        <p className="text-[13px] text-muted-foreground mb-2">
          Set the events that will trigger your webhook. Maximum {maxEvents}{' '}
          events allowed.{' '}
          <a
            href={DOCS_LINK}
            target="_blank"
            rel="noopener noreferrer"
            className="text-primary underline hover:no-underline"
          >
            Learn more
          </a>
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
                  className="ml-1 rounded-full hover:bg-muted"
                >
                  <X className="h-3 w-3" />
                </button>
              </Badge>
            ))}
          </div>
        ) : (
          <p className="text-[13px] text-muted-foreground">
            No events selected
          </p>
        )}
      </div>
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={() => setEventDialogOpen(true)}
        disabled={selectedEvents.length >= maxEvents}
      >
        <Plus className="mr-1.5 h-4 w-4" />
        Add event
      </Button>

      <EventEditorModal
        open={eventDialogOpen}
        onOpenChange={setEventDialogOpen}
        onCreated={handleCreated}
        description="Select events that will trigger your webhook."
        projectId={projectId}
      />
    </div>
  )
}
