import { ChevronDown } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { THREADS_MORE_TAGS, THREADS_PRIMARY_TAGS } from '@/lib/threads/constants'
import { cn } from '@/lib/utils'

type ThreadTagsFilterProps = {
  selectedTags: string[]
  onToggleTag: (tag: string) => void
}

function TagButton({
  tag,
  selected,
  onToggle,
}: {
  tag: string
  selected: boolean
  onToggle: (tag: string) => void
}) {
  return (
    <Button
      type="button"
      variant="outline"
      size="sm"
      className={cn(
        'h-8 rounded-full px-3 text-[12px] font-normal',
        selected && 'border-foreground/30 bg-muted text-foreground',
      )}
      onClick={() => onToggle(tag)}
    >
      {tag}
    </Button>
  )
}

export function ThreadTagsFilter({
  selectedTags,
  onToggleTag,
}: ThreadTagsFilterProps) {
  const selectedMoreCount = THREADS_MORE_TAGS.filter((tag) =>
    selectedTags.includes(tag),
  ).length

  return (
    <ul className="flex flex-wrap items-center gap-2">
      {THREADS_PRIMARY_TAGS.map((tag) => (
        <li key={tag}>
          <TagButton
            tag={tag}
            selected={selectedTags.includes(tag)}
            onToggle={onToggleTag}
          />
        </li>
      ))}
      <li>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className={cn(
                'h-8 rounded-full px-3 text-[12px] font-normal',
                selectedMoreCount > 0 && 'border-foreground/30 bg-muted text-foreground',
              )}
            >
              More
              {selectedMoreCount > 0 ? ` (${selectedMoreCount})` : null}
              <ChevronDown className="ms-1 h-3.5 w-3.5" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start" className="max-h-48 w-56 overflow-y-auto">
            {THREADS_MORE_TAGS.map((tag) => (
              <DropdownMenuCheckboxItem
                key={tag}
                checked={selectedTags.includes(tag)}
                onCheckedChange={() => onToggleTag(tag)}
              >
                {tag}
              </DropdownMenuCheckboxItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      </li>
    </ul>
  )
}
