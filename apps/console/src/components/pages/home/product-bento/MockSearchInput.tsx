import { Search } from 'lucide-react'

export function MockSearchInput({
  placeholder,
  typedText,
  typeDelayMs = 120,
}: {
  placeholder: string
  typedText: string
  typeDelayMs?: number
}) {
  const cursorDelay = typeDelayMs + typedText.length * 55

  return (
    <div className="flex items-center gap-1.5 rounded-md border border-border bg-background/90 px-2 py-1.5 text-[10px]">
      <Search className="size-3 shrink-0 text-muted-foreground" aria-hidden />
      <div className="relative min-w-0 flex-1">
        <span className="text-muted-foreground transition-opacity duration-200 group-hover:opacity-0 motion-reduce:group-hover:opacity-100">
          {placeholder}
        </span>
        <span className="absolute inset-0 flex items-center opacity-0 group-hover:opacity-100 motion-reduce:opacity-100">
          <span className="inline-flex max-w-full items-center overflow-hidden whitespace-nowrap text-foreground">
            <span
              className="inline-block max-w-0 overflow-hidden whitespace-nowrap group-hover:animate-[product-bento-search-reveal_1.2s_steps(12,end)_forwards] motion-reduce:max-w-none motion-reduce:group-hover:animate-none"
              style={{ animationDelay: `${typeDelayMs}ms` }}
            >
              {typedText}
            </span>
            <span
              className="ms-px inline-block h-2.5 w-px shrink-0 bg-muted-foreground opacity-0 group-hover:animate-[ai-mock-cursor-blink_1s_step-end_infinite] motion-reduce:opacity-100 motion-reduce:group-hover:animate-none"
              style={{ animationDelay: `${cursorDelay}ms` }}
            />
          </span>
        </span>
      </div>
    </div>
  )
}
