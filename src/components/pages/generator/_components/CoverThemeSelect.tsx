import {
  Select,
  SelectContent,
  SelectGroup,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { CoverThemePreviewThumb } from '@/components/pages/generator/_components/CoverThemePreviewThumb'
import { CoverThemeSelectItem } from '@/components/pages/generator/_components/CoverThemeSelectItem'
import type { CoverTheme } from '@/lib/cover-generator/constants'
import {
  getCoverTheme,
  listCoverEditorThemesByFamily,
  resolveCoverEditorThemeId,
} from '@/lib/cover-generator/themes'

type CoverThemeSelectProps = {
  theme: CoverTheme
  onThemeChange: (theme: CoverTheme) => void
  className?: string
}

export function CoverThemeSelect({
  theme,
  onThemeChange,
  className,
}: CoverThemeSelectProps) {
  const editorTheme = resolveCoverEditorThemeId(theme)
  const selectedTheme = getCoverTheme(editorTheme)

  return (
    <Select
      value={editorTheme}
      onValueChange={(value) => {
        onThemeChange(value as CoverTheme)
      }}
    >
      <SelectTrigger className={className ?? 'h-8 w-full text-[12px]'}>
        <CoverThemePreviewThumb themeId={editorTheme} />
        <SelectValue>{selectedTheme.label}</SelectValue>
      </SelectTrigger>
      <SelectContent className="min-w-[min(100vw-2rem,360px)]">
        {(['light', 'dark'] as const).map((family) => (
          <SelectGroup key={family}>
            <SelectLabel className="text-[11px] font-semibold uppercase tracking-wider">
              {family === 'light' ? 'Light backgrounds' : 'Dark backgrounds'}
            </SelectLabel>
            {listCoverEditorThemesByFamily(family).map((themeOption) => (
              <CoverThemeSelectItem key={themeOption.id} theme={themeOption} />
            ))}
          </SelectGroup>
        ))}
      </SelectContent>
    </Select>
  )
}
