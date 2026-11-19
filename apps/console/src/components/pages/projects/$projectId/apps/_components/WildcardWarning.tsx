import { TriangleAlert } from 'lucide-react'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'

export const WILDCARD_WARNING =
  "Any website can send requests to this project. Before going live, replace * with your app's domain, like app.example.com."

/** Flags a web app whose hostname is `*` on app cards. */
export function WildcardWarning() {
  const t = useT()
  const message = t(WILDCARD_WARNING)

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <TriangleAlert
          role="img"
          aria-label={message}
          data-testid="wildcard-platform-warning"
          className="h-3.5 w-3.5 shrink-0 text-amber-500"
        />
      </TooltipTrigger>
      <TooltipContent className="max-w-64">
        <p className="text-xs">{message}</p>
      </TooltipContent>
    </Tooltip>
  )
}
