'use client'

import { useCallback, useState } from 'react'
import { Download, Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import type { ApiExplorerProjectPlatform } from '@/lib/api-explorer/types'
import { Button } from '@/components/ui/button'
import { downloadReferenceOpenApiSpec } from '@/lib/docs/references/download-open-api-spec'
import type { ReferenceVersion } from '@/lib/docs/references/constants'
import { cn } from '@/lib/utils'

type ApiReferenceOpenApiSpecDownloadFooterProps = {
  version: ReferenceVersion
  mode: ApiExplorerProjectPlatform
  className?: string
}

export function ApiReferenceOpenApiSpecDownloadFooter({
  version,
  mode,
  className,
}: ApiReferenceOpenApiSpecDownloadFooterProps) {
  const [isDownloading, setIsDownloading] = useState(false)

  const handleDownload = useCallback(async () => {
    setIsDownloading(true)
    try {
      await downloadReferenceOpenApiSpec(version, mode)
    } catch {
      toast.error('Failed to download OpenAPI spec')
    } finally {
      setIsDownloading(false)
    }
  }, [mode, version])

  return (
    <div
      className={cn(
        'shrink-0 border-t border-border bg-background px-3 py-3',
        className,
      )}
    >
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={isDownloading}
        onClick={() => void handleDownload()}
        className="h-9 w-full text-[13px] text-muted-foreground hover:text-foreground"
      >
        {isDownloading ? (
          <Loader2 className="me-1.5 size-4 animate-spin" />
        ) : (
          <Download className="me-1.5 size-4" />
        )}
        OpenAPI spec
      </Button>
    </div>
  )
}
