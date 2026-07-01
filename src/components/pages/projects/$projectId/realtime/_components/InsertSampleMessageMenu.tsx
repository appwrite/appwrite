'use client'

import { Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  MOCK_MESSAGE_SAMPLE_OPTIONS,
  type MockMessageSampleId,
} from '@/lib/realtime/mock-message-samples'

export function InsertSampleMessageMenu({
  onInsert,
}: {
  onInsert: (sampleId: MockMessageSampleId) => void
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-7 text-[12px]"
        >
          <Plus className="me-1.5 h-3.5 w-3.5" />
          Sample
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-72">
        <DropdownMenuLabel className="text-[12px] font-normal leading-snug text-muted-foreground">
          Sample frames are added locally for reference. Nothing is sent over
          the network.
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        {MOCK_MESSAGE_SAMPLE_OPTIONS.map((option) => (
          <DropdownMenuItem
            key={option.id}
            className="flex cursor-pointer flex-col items-start gap-0.5 py-2"
            onSelect={() => onInsert(option.id)}
          >
            <span className="text-[13px] font-medium text-foreground">
              {option.label}
            </span>
            <span className="text-[12px] leading-snug text-muted-foreground">
              {option.description}
            </span>
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
