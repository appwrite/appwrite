import { useMemo, type ReactNode } from 'react'
import { Copy } from 'lucide-react'
import { toast } from 'sonner'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  buildCoverApiDocsContext,
  buildCoverApiDocsMarkdown,
  type CoverApiParameterDoc,
} from '@/lib/cover-generator/api-docs'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import { cn } from '@/lib/utils'

type CoverApiDocsDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  data: CoverRenderData
  origin?: string
}

function DrawerSectionCard({
  title,
  description,
  children,
  className,
}: {
  title: string
  description?: string
  children: ReactNode
  className?: string
}) {
  return (
    <div
      className={cn(
        'overflow-hidden rounded-xl border border-border bg-card/50',
        className,
      )}
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">{title}</h3>
        {description ? (
          <p className="mt-2 text-[13px] leading-6 text-muted-foreground">
            {description}
          </p>
        ) : null}
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">{children}</div>
    </div>
  )
}

function ApiParametersTable({ parameters }: { parameters: CoverApiParameterDoc[] }) {
  return (
    <div className="overflow-hidden rounded-lg border border-border">
      <Table>
        <TableHeader>
          <TableRow className="border-b border-border hover:bg-transparent">
            <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Parameter
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Type
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Description
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {parameters.map((parameter) => (
            <TableRow key={parameter.name} className="hover:bg-transparent">
              <TableCell className="px-4 py-3 align-top">
                <div className="flex flex-wrap items-center gap-2">
                  <code className="text-[12px] font-medium text-foreground">
                    {parameter.name}
                  </code>
                  {parameter.required ? (
                    <Badge variant="warning" className="text-[10px] shrink-0">
                      Required
                    </Badge>
                  ) : null}
                </div>
              </TableCell>
              <TableCell className="px-4 py-3 align-top">
                <Badge variant="info" className="text-[10px] shrink-0">
                  {parameter.type}
                </Badge>
              </TableCell>
              <TableCell className="px-4 py-3 align-top text-[12px] leading-5 text-muted-foreground">
                <p>{parameter.description}</p>
                {parameter.enumValues?.length ? (
                  <p className="mt-1.5 font-mono text-[11px] text-foreground/80">
                    {parameter.enumValues.join(', ')}
                  </p>
                ) : null}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

function ApiCodeExample({
  title,
  description,
  code,
  language,
}: {
  title: string
  description?: string
  code: string
  language: 'bash' | 'javascript' | 'json' | 'http'
}) {
  return (
    <div className="space-y-2">
      <div>
        <p className="text-[13px] font-medium text-foreground">{title}</p>
        {description ? (
          <p className="mt-1 text-[12px] leading-5 text-muted-foreground">
            {description}
          </p>
        ) : null}
      </div>
      <ConnectCodeExample code={code} language={language} />
    </div>
  )
}

export function CoverApiDocsDrawer({
  open,
  onOpenChange,
  data,
  origin = typeof window !== 'undefined' ? window.location.origin : '',
}: CoverApiDocsDrawerProps) {
  const docs = useMemo(
    () => buildCoverApiDocsContext(data, origin),
    [data, origin],
  )

  const payloadJson = JSON.stringify(docs.payload, null, 2)

  const handleCopyMarkdown = async () => {
    try {
      await navigator.clipboard.writeText(buildCoverApiDocsMarkdown(docs))
      toast.success('Markdown copied')
    } catch {
      toast.error('Could not copy markdown')
    }
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title="Cover generator API"
      description="Programmatic cover rendering for OG images and marketing assets."
      maxWidth="sm:max-w-2xl"
      disableAutoFocus
      headerActions={
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 text-[12px]"
          onClick={handleCopyMarkdown}
        >
          <Copy className="mr-1.5 size-3.5" />
          Copy markdown
        </Button>
      }
    >
      <>
        <div className="shrink-0 border-t border-border" />

        <div className="flex min-h-0 flex-1 flex-col">
          <div className="min-h-0 flex-1 overflow-y-auto">
            <div className="space-y-6 px-6 py-6">
              <p className="text-[13px] leading-6 text-muted-foreground">
                Render PNG, JPEG, or AVIF cover images from template parameters.
                Use POST when the request includes uploaded images, data URIs, or
                a query string longer than about 1,800 characters.
              </p>

              {docs.recommendsPost ? (
                <div className="rounded-xl border border-warning/30 bg-warning/10 px-4 py-3 text-[13px] leading-6 text-foreground">
                  The current cover should use{' '}
                  <span className="font-medium">POST</span>
                  {docs.hasInlineAssets ? ' because it includes inline image data' : null}
                  {docs.getUrlTooLong
                    ? `${docs.hasInlineAssets ? ' and' : ' because'} the GET URL is too long`
                    : null}
                  .
                </div>
              ) : null}

              <DrawerSectionCard
                title="Endpoint"
                description="Both methods accept the same template fields. POST is recommended for image-heavy requests."
              >
                <ConnectCodeExample
                  code={`GET  ${docs.endpointUrl}\nPOST ${docs.endpointUrl}`}
                  language="http"
                />
              </DrawerSectionCard>

              <DrawerSectionCard title="Methods">
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <p className="text-[13px] font-medium text-foreground">GET</p>
                    <p className="mt-1 text-[13px] leading-6 text-muted-foreground">
                      Returns the image inline. Best for short, URL-safe parameter
                      sets.
                    </p>
                  </div>
                  <div>
                    <p className="text-[13px] font-medium text-foreground">POST</p>
                    <p className="mt-1 text-[13px] leading-6 text-muted-foreground">
                      Accepts JSON. Supports data URIs and long payloads. Add{' '}
                      <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
                        ?disposition=attachment
                      </code>{' '}
                      to download the file.
                    </p>
                  </div>
                </div>
              </DrawerSectionCard>

              <DrawerSectionCard
                title={`Parameters for ${docs.templateLabel}`}
                description={docs.templateDescription}
              >
                <ApiParametersTable parameters={docs.parameters} />
              </DrawerSectionCard>

              <DrawerSectionCard
                title="Examples for current cover"
                description="Generated from the values in the editor right now."
              >
                <div className="space-y-6">
                  <ApiCodeExample
                    title="Current JSON body"
                    description="Use this payload with POST."
                    code={payloadJson}
                    language="json"
                  />

                  <ApiCodeExample
                    title="cURL (POST)"
                    description="Recommended for the current cover."
                    code={docs.curlPost}
                    language="bash"
                  />

                  <ApiCodeExample
                    title="fetch (POST)"
                    code={docs.fetchExample}
                    language="javascript"
                  />

                  <ApiCodeExample
                    title="cURL (GET)"
                    description={
                      docs.recommendsPost
                        ? 'Included for reference. The current cover may exceed safe URL length limits.'
                        : 'Works for the current cover.'
                    }
                    code={docs.curlGet}
                    language="bash"
                  />
                </div>
              </DrawerSectionCard>

              <DrawerSectionCard title="Response">
                <div className="space-y-3 text-[13px] leading-6 text-muted-foreground">
                  <p>
                    <span className="font-medium text-foreground">200 OK</span>
                    {' '}
                    with
                    {' '}
                    <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
                      Content-Type: image/png
                    </code>
                    ,
                    {' '}
                    <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
                      image/jpeg
                    </code>
                    , or
                    {' '}
                    <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
                      image/avif
                    </code>
                    {' '}
                    depending on
                    {' '}
                    <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
                      format
                    </code>
                    .
                  </p>
                  <p>
                    <span className="font-medium text-foreground">404</span>
                    {' '}
                    when the marketing profile is disabled.
                  </p>
                  <p>
                    <span className="font-medium text-foreground">500</span>
                    {' '}
                    when rendering fails.
                  </p>
                </div>
              </DrawerSectionCard>
            </div>
          </div>
        </div>
      </>
    </BaseDrawer>
  )
}
