import { ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import type {
  AddAppKind,
  WebFrameworkKey,
} from '@/lib/add-app-wizard/types'
import {
  PlatformKindIcon,
  getSelectionSummary,
} from './selected-platform-meta'
import { useT } from '@/lib/i18n/translate'

type Props = {
  kind: AddAppKind
  variant: string
  framework: WebFrameworkKey
  onChange: () => void
  disabled?: boolean
}

/**
 * Compact summary of the user's platform + framework/target choice, shown
 * above the app details form. Renders as two icon-and-label chips connected
 * by a chevron, with a subtle Change action on the right.
 */
export function SelectedPlatformCard({
  kind,
  variant,
  framework,
  onChange,
  disabled,
}: Props) {
  const t = useT()
  const { kindLabel, secondary } = getSelectionSummary(kind, variant, framework)

  return (
    <div className="rounded-xl border border-border bg-card/50 px-3 py-2.5 sm:px-4 sm:py-3">
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
          <span className="inline-flex items-center gap-1.5 rounded-md border border-border bg-muted/40 px-2 py-1 text-[12px] font-medium text-foreground">
            <span className="flex h-3.5 w-3.5 shrink-0 items-center justify-center text-muted-foreground [&_img]:!h-3.5 [&_img]:!w-3.5 [&_svg]:!h-3.5 [&_svg]:!w-3.5">
              <PlatformKindIcon kind={kind} />
            </span>
            {kindLabel}
          </span>
          {secondary ? (
            <>
              <ChevronRight
                className="h-3.5 w-3.5 shrink-0 text-muted-foreground/60"
                aria-hidden
              />
              <span className="inline-flex items-center gap-1.5 rounded-md border border-border bg-muted/40 px-2 py-1 text-[12px] font-medium text-foreground">
                <span className="flex h-3.5 w-3.5 shrink-0 items-center justify-center text-muted-foreground [&_img]:!h-3.5 [&_img]:!w-3.5 [&_svg]:!h-3.5 [&_svg]:!w-3.5">
                  {secondary.icon}
                </span>
                {secondary.label}
              </span>
            </>
          ) : null}
        </div>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-7 shrink-0 px-2 text-[12px] text-muted-foreground hover:text-foreground"
          onClick={onChange}
          disabled={disabled}
        >
          {t('Change')}
        </Button>
      </div>
    </div>
  )
}
