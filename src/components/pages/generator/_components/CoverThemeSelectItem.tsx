import * as SelectPrimitive from '@radix-ui/react-select'
import { CheckIcon } from 'lucide-react'
import { CoverThemePreviewThumb } from '@/components/pages/generator/_components/CoverThemePreviewThumb'
import type { CoverThemeDefinition } from '@/lib/cover-generator/themes'
import { cn } from '@/lib/utils'

type CoverThemeSelectItemProps = {
  theme: CoverThemeDefinition
}

export function CoverThemeSelectItem({ theme }: CoverThemeSelectItemProps) {
  return (
    <SelectPrimitive.Item
      value={theme.id}
      className={cn(
        'relative flex w-full cursor-pointer items-start gap-2.5 rounded-sm py-2 pe-8 ps-2 text-sm outline-hidden select-none focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
      )}
    >
      <CoverThemePreviewThumb themeId={theme.id} className="mt-0.5" />
      <div className="min-w-0 flex-1">
        <SelectPrimitive.ItemText className="block text-[13px] leading-snug text-foreground">
          {theme.label}
        </SelectPrimitive.ItemText>
        <p className="mt-0.5 text-[11px] leading-snug text-muted-foreground">
          {theme.description}
        </p>
      </div>
      <span className="absolute end-2 top-2 flex size-3.5 items-center justify-center">
        <SelectPrimitive.ItemIndicator>
          <CheckIcon className="size-4" />
        </SelectPrimitive.ItemIndicator>
      </span>
    </SelectPrimitive.Item>
  )
}
