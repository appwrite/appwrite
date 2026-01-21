import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { X, Plus } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Checkbox } from '@/components/ui/checkbox'

interface EventSelectorProps {
  selectedEvents: string[]
  onEventsChange: (events: string[]) => void
  maxEvents?: number
}

// Common project events (this would ideally be fetched from the API)
const PROJECT_EVENTS = [
  'users.*.create',
  'users.*.update',
  'users.*.delete',
  'teams.*.create',
  'teams.*.update',
  'teams.*.delete',
  'databases.*.create',
  'databases.*.update',
  'databases.*.delete',
  'collections.*.create',
  'collections.*.update',
  'collections.*.delete',
  'documents.*.create',
  'documents.*.update',
  'documents.*.delete',
  'functions.*.create',
  'functions.*.update',
  'functions.*.delete',
  'functions.*.deploy',
  'functions.*.execute',
  'storage.*.create',
  'storage.*.update',
  'storage.*.delete',
  'buckets.*.create',
  'buckets.*.update',
  'buckets.*.delete',
]

export function EventSelector({
  selectedEvents,
  onEventsChange,
  maxEvents = 100,
}: EventSelectorProps) {
  const [eventDialogOpen, setEventDialogOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [tempSelectedEvents, setTempSelectedEvents] =
    useState<string[]>(selectedEvents)

  const filteredEvents = PROJECT_EVENTS.filter((event) =>
    event.toLowerCase().includes(searchQuery.toLowerCase()),
  )

  const handleAddEvent = (event: string) => {
    if (
      !tempSelectedEvents.includes(event) &&
      tempSelectedEvents.length < maxEvents
    ) {
      setTempSelectedEvents([...tempSelectedEvents, event])
    }
  }

  const handleRemoveEvent = (event: string, fromDialog = false) => {
    if (fromDialog) {
      setTempSelectedEvents(tempSelectedEvents.filter((e) => e !== event))
    } else {
      onEventsChange(selectedEvents.filter((e) => e !== event))
    }
  }

  const handleToggleEvent = (event: string) => {
    if (tempSelectedEvents.includes(event)) {
      handleRemoveEvent(event, true)
    } else {
      handleAddEvent(event)
    }
  }

  const handleApply = () => {
    onEventsChange(tempSelectedEvents)
    setEventDialogOpen(false)
    setSearchQuery('')
  }

  const handleOpenDialog = () => {
    setTempSelectedEvents(selectedEvents)
    setEventDialogOpen(true)
  }

  return (
    <div className="space-y-4">
      <div>
        <p className="text-[13px] text-muted-foreground mb-2">
          Set the events that will trigger your webhook. Maximum {maxEvents}{' '}
          events allowed.
        </p>
        {selectedEvents.length > 0 ? (
          <div className="flex flex-wrap gap-2">
            {selectedEvents.map((event) => (
              <Badge key={event} variant="secondary" className="gap-1.5">
                {event}
                <button
                  type="button"
                  onClick={() => handleRemoveEvent(event, false)}
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
        onClick={handleOpenDialog}
        disabled={selectedEvents.length >= maxEvents}
      >
        <Plus className="mr-1.5 h-4 w-4" />
        Add event
      </Button>

      {/* Event Selection Dialog */}
      <Dialog open={eventDialogOpen} onOpenChange={setEventDialogOpen}>
        <DialogContent className="sm:max-w-2xl p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Select events</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Select events in your Appwrite project that will trigger your
              webhook. Learn more.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />

          <div className="px-6 pb-4 pt-0">
            <div className="space-y-4">
              <Input
                placeholder="Search events..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
              />
              <div className="max-h-[400px] overflow-auto rounded-lg border border-border">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-[50px]"></TableHead>
                      <TableHead>Event</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {filteredEvents.map((event) => (
                      <TableRow key={event}>
                        <TableCell>
                          <Checkbox
                            checked={tempSelectedEvents.includes(event)}
                            onCheckedChange={() => handleToggleEvent(event)}
                            disabled={
                              !tempSelectedEvents.includes(event) &&
                              tempSelectedEvents.length >= maxEvents
                            }
                          />
                        </TableCell>
                        <TableCell>
                          <code className="text-[13px] font-mono">{event}</code>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
              <p className="text-[13px] text-muted-foreground">
                {tempSelectedEvents.length} of {maxEvents} events selected
              </p>
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => setEventDialogOpen(false)}
            >
              Cancel
            </Button>
            <Button type="button" onClick={handleApply}>
              Apply
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
