import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  AlertCircle,
  CheckCircle2,
  Loader2,
  Clock,
  XCircle,
} from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { DateTooltip } from '@/components/global/shared/DateTooltip'

interface MigrationDetailsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  migration: Models.Migration
}

interface StatusCounters {
  pending?: number
  success?: number
  error?: number
  skip?: number
  processing?: number
  warning?: number
}

export function MigrationDetailsDialog({
  open,
  onOpenChange,
  migration,
}: MigrationDetailsDialogProps) {
  // Parse status counters
  const statusCounters: StatusCounters = useMemo(() => {
    if (!migration.statusCounters) return {}
    try {
      return typeof migration.statusCounters === 'string'
        ? JSON.parse(migration.statusCounters)
        : migration.statusCounters
    } catch {
      return {}
    }
  }, [migration.statusCounters])

  // Parse errors
  const errors = useMemo(() => {
    if (!migration.errors || migration.errors.length === 0) return []
    return migration.errors.map((error) => {
      try {
        return typeof error === 'string' ? JSON.parse(error) : error
      } catch {
        return error
      }
    })
  }, [migration.errors])

  const hasErrors =
    errors.length > 0 || (statusCounters.error && statusCounters.error > 0)

  const getResourceStatus = (resource: string) => {
    const pending =
      statusCounters[`${resource}_pending` as keyof StatusCounters] || 0
    const success =
      statusCounters[`${resource}_success` as keyof StatusCounters] || 0
    const error =
      statusCounters[`${resource}_error` as keyof StatusCounters] || 0
    const processing =
      statusCounters[`${resource}_processing` as keyof StatusCounters] || 0

    if (error > 0)
      return { icon: XCircle, tone: 'error' as const, count: error }
    if (processing > 0 || pending > 0) {
      return {
        icon: Loader2,
        tone: 'processing' as const,
        count: processing + pending,
      }
    }
    if (success > 0)
      return { icon: CheckCircle2, tone: 'success' as const, count: success }
    return { icon: Clock, tone: 'waiting' as const, count: 0 }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-3xl p-0">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>
            {migration.status === 'failed'
              ? 'Resolve migration issues'
              : 'Migration details'}
          </DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            View migration details and logs
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <div className="px-6 pb-4 pt-0">
          <Tabs defaultValue="details">
            <TabsList>
              <TabsTrigger value="details">Details</TabsTrigger>
              <TabsTrigger value="logs">Logs</TabsTrigger>
            </TabsList>

            <TabsContent value="details" className="space-y-4 mt-4">
              <div className="space-y-2">
                <p className="text-[13px] font-medium">Date</p>
                <DateTooltip date={migration.$createdAt} />
              </div>
              <div className="space-y-2">
                <p className="text-[13px] font-medium">Source</p>
                <p className="text-[13px] text-muted-foreground">
                  {migration.source}
                </p>
              </div>

              {/* Status Counters */}
              <div className="space-y-2">
                <p className="text-[13px] font-medium">Status</p>
                <div className="grid gap-2 sm:grid-cols-2">
                  {['database', 'table', 'function', 'user'].map((resource) => {
                    const status = getResourceStatus(resource)
                    const Icon = status.icon
                    return (
                      <div
                        key={resource}
                        className="flex items-center gap-2 rounded-lg border border-border bg-card p-3"
                      >
                        <div
                          className={`flex h-8 w-8 items-center justify-center rounded-lg ${
                            status.tone === 'error'
                              ? 'bg-destructive/10'
                              : status.tone === 'processing'
                                ? 'bg-blue-500/10'
                                : status.tone === 'success'
                                  ? 'bg-green-500/10'
                                  : 'bg-muted'
                          }`}
                        >
                          <Icon
                            className={`h-4 w-4 ${
                              status.tone === 'error'
                                ? 'text-destructive'
                                : status.tone === 'processing'
                                  ? 'text-blue-600 animate-spin'
                                  : status.tone === 'success'
                                    ? 'text-green-600'
                                    : 'text-muted-foreground'
                            }`}
                          />
                        </div>
                        <div className="flex-1">
                          <p className="text-[13px] font-medium capitalize">
                            {resource}
                          </p>
                          <p className="text-[12px] text-muted-foreground">
                            {status.count}{' '}
                            {status.count === 1 ? 'item' : 'items'}
                          </p>
                        </div>
                        {status.count > 0 && (
                          <Badge variant="secondary" className="text-[12px]">
                            {status.count}
                          </Badge>
                        )}
                      </div>
                    )
                  })}
                </div>
              </div>

              {hasErrors && (
                <Alert variant="destructive">
                  <AlertCircle className="h-4 w-4" />
                  <AlertTitle>Migration errors</AlertTitle>
                  <AlertDescription>
                    There was an error migrating some of the project's entities.
                  </AlertDescription>
                </Alert>
              )}
            </TabsContent>

            <TabsContent value="logs" className="mt-4">
              <pre className="max-h-[400px] overflow-auto rounded-md bg-muted p-4 text-[13px] font-mono">
                {JSON.stringify(migration, null, 2)}
              </pre>
            </TabsContent>
          </Tabs>
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Close
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
