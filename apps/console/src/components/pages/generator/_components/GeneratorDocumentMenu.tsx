import { useNavigate } from '@tanstack/react-router'
import { ChevronDown, LayoutGrid, Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useGeneratorLayout } from '@/components/pages/generator/GeneratorLayoutContext'

export function GeneratorDocumentMenu() {
  const navigate = useNavigate()
  const { documentChrome } = useGeneratorLayout()

  if (!documentChrome) return null

  const {
    phase,
    resource,
    onBrowseDocuments,
    onNewDocument,
    browseDocumentsLabel,
  } = documentChrome

  const openDiagramStart = () => {
    if (resource === 'diagrams' && phase === 'editor') {
      onNewDocument()
      return
    }
    void navigate({ to: '/generator/diagrams' })
  }

  const openCoverStart = () => {
    if (resource === 'covers' && phase === 'editor') {
      onNewDocument()
      return
    }
    void navigate({ to: '/generator' })
  }

  return (
    <div className="flex items-center gap-2">
      {phase === 'editor' ? (
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-7 text-[12px]"
          onClick={onBrowseDocuments}
        >
          <LayoutGrid className="me-1.5 size-3.5" />
          {browseDocumentsLabel}
        </Button>
      ) : null}

      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-7 text-[12px]"
          >
            <Plus className="me-1.5 size-3.5" />
            New
            <ChevronDown className="ms-1 size-3.5 opacity-60" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-44">
          <DropdownMenuItem onClick={openDiagramStart}>New diagram</DropdownMenuItem>
          <DropdownMenuItem onClick={openCoverStart}>New cover</DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}
