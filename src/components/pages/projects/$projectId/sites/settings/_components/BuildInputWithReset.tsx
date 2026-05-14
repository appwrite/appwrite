import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { RotateCcw } from 'lucide-react'

export function BuildInputWithReset({
  id,
  label,
  value,
  placeholder,
  onChange,
  onReset,
  isModified,
}: {
  id: string
  label: string
  value: string
  placeholder: string
  onChange: (value: string) => void
  onReset: () => void
  isModified: boolean
}) {
  return (
    <div>
      <Label htmlFor={id} className="text-[13px]">
        {label}
      </Label>
      <div className="mt-2 flex overflow-hidden rounded-md border border-input transition-colors has-[:focus-visible]:border-ring">
        <Input
          id={id}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={placeholder}
          className="h-9 flex-1 min-w-0 rounded-none border-0 border-r border-input bg-transparent font-mono text-[13px] focus-visible:ring-0 focus-visible:ring-offset-0"
        />
        <TooltipProvider delayDuration={300}>
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={onReset}
                disabled={!isModified}
                className={cn(
                  'h-9 w-9 shrink-0 rounded-none border-0 text-muted-foreground hover:text-foreground',
                  !isModified && 'cursor-default opacity-50',
                )}
                aria-label="Reset to default"
              >
                <RotateCcw className="h-3.5 w-3.5" />
              </Button>
            </TooltipTrigger>
            <TooltipContent side="top" className="text-xs">
              {isModified ? 'Reset to default' : 'No changes to reset'}
            </TooltipContent>
          </Tooltip>
        </TooltipProvider>
      </div>
    </div>
  )
}
