import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'

interface CreateAppProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function CreateApp({ open, onOpenChange }: CreateAppProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>Create distribution app</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Connect a git repository and pick a platform to start shipping
            builds to the app stores. The full setup flow is coming soon.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            className="h-9 text-[13px]"
            onClick={() => onOpenChange(false)}
          >
            Cancel
          </Button>
          <Button className="h-9 text-[13px]" disabled>
            Continue
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
