import { Plug } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { usePostgresConnectDialog } from './PostgresConnectDialogContext'

export function PostgresConnectButton() {
  const connectDialog = usePostgresConnectDialog()

  if (!connectDialog) return null

  return (
    <Button
      type="button"
      size="sm"
      className="h-9 w-full gap-1.5 text-[13px]"
      onClick={connectDialog.openConnect}
    >
      <Plug className="h-4 w-4 shrink-0" />
      Connect database
    </Button>
  )
}
