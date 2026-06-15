import { Plug } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { usePostgresConnectDialog } from './PostgresConnectDialogContext'

export function PostgresConnectHeaderButton() {
  const connectDialog = usePostgresConnectDialog()

  if (!connectDialog) return null

  return (
    <div className="relative h-[1lh] shrink-0 overflow-visible">
      <span
        className="inline-flex h-9 items-center gap-1.5 px-3 text-[13px] opacity-0 pointer-events-none select-none"
        aria-hidden
      >
        <Plug className="h-4 w-4 shrink-0" />
        Connect
      </span>
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="absolute right-0 top-1/2 h-9 -translate-y-1/2 gap-1.5 text-[13px]"
        onClick={connectDialog.openConnect}
      >
        <Plug className="h-4 w-4 shrink-0" />
        Connect
      </Button>
    </div>
  )
}
