import { useMemo, useState, type ReactNode } from 'react'
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
  buildDefaultCoverRenderData,
  type CoverApiTemplateSummary,
} from '@/lib/cover-generator/api-docs'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import {
  buildDefaultDiagramReferenceDocument,
  buildDiagramApiDocsContext,
  buildDiagramApiDocsMarkdown,
  buildGeneratorApiDocsMarkdown,
} from '@/lib/diagram-generator/api-docs'
import type { DiagramDocument } from '@/lib/diagram-generator/types'
import {
  GENERATOR_API_RESPONSE_NOTES,
  type GeneratorApiParameterDoc,
} from '@/lib/generator/api-docs-shared'
import { GENERATOR_COVER_API_PATH, GENERATOR_DIAGRAM_API_PATH } from '@/lib/generator/constants'
import type { GeneratorApiTab } from '@/components/pages/generator/GeneratorLayoutContext'
import { cn } from '@/lib/utils'

type GeneratorApiDocsDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  activeTab: GeneratorApiTab
  coverData: CoverRenderData | null
  diagramDocument: DiagramDocument | null
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

function ApiParametersTable({
  parameters,
}: {
  parameters: GeneratorApiParameterDoc[]
}) {
  if (parameters.length === 0) {
    return (
      <p className="text-[13px] text-muted-foreground">No parameters for this section.</p>
    )
  }

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
                {parameter.defaultValue ? (
                  <p className="mt-1.5 text-[11px] text-foreground/80">
                    Default: <code>{parameter.defaultValue}</code>
                  </p>
                ) : null}
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

function ApiTemplateCatalogTable({
  templates,
  activeTemplateId,
}: {
  templates: CoverApiTemplateSummary[]
  activeTemplateId?: string
}) {
  return (
    <div className="overflow-hidden rounded-lg border border-border">
      <Table>
        <TableHeader>
          <TableRow className="border-b border-border hover:bg-transparent">
            <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Template
            </TableHead>
            <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Description
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {templates.map((template) => (
            <TableRow key={template.id} className="hover:bg-transparent">
              <TableCell className="px-4 py-3 align-top">
                <div className="space-y-1">
                  <code className="text-[12px] font-medium text-foreground">
                    {template.id}
                  </code>
                  <p className="text-[12px] text-muted-foreground">{template.label}</p>
                  {activeTemplateId === template.id ? (
                    <Badge variant="success" className="text-[10px] shrink-0">
                      Current
                    </Badge>
                  ) : null}
                </div>
              </TableCell>
              <TableCell className="px-4 py-3 align-top text-[12px] leading-5 text-muted-foreground">
                {template.description}
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

function ApiResponseSection() {
  return (
    <DrawerSectionCard title="Response">
      <div className="space-y-3 text-[13px] leading-6 text-muted-foreground">
        {GENERATOR_API_RESPONSE_NOTES.map((note) => (
          <p key={note.status}>
            <span className="font-medium text-foreground">{note.status}</span>:{' '}
            {note.description}
          </p>
        ))}
      </div>
    </DrawerSectionCard>
  )
}

function CoverApiDocsContent({
  data,
  origin,
}: {
  data: CoverRenderData
  origin: string
}) {
  const docs = useMemo(() => buildCoverApiDocsContext(data, origin), [data, origin])
  const payloadJson = JSON.stringify(docs.displayPayload, null, 2)

  return (
    <div className="space-y-6">
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
        title="Methods"
        description="Both methods accept the same template fields."
      >
        <div className="space-y-4 text-[13px] leading-6 text-muted-foreground">
          <div>
            <p className="font-medium text-foreground">GET</p>
            <p className="mt-1">
              Pass fields as query parameters. Best for short, URL-safe covers and direct image
              links.
            </p>
          </div>
          <div>
            <p className="font-medium text-foreground">POST</p>
            <p className="mt-1">
              Send JSON with the same fields. Required for data URIs, uploads, and long text
              fields. Booleans and numbers can be sent as JSON types or strings.
            </p>
          </div>
          <ConnectCodeExample
            code={`GET  ${docs.endpointUrl}\nPOST ${docs.endpointUrl}`}
            language="http"
          />
        </div>
      </DrawerSectionCard>

      <DrawerSectionCard
        title="Query parameters"
        description="Optional on both GET and POST requests."
      >
        <ApiParametersTable parameters={docs.queryParameters} />
      </DrawerSectionCard>

      <DrawerSectionCard
        title="Available templates"
        description="Set template to one of these ids. Each template adds its own content fields."
      >
        <ApiTemplateCatalogTable
          templates={docs.templateSummaries}
          activeTemplateId={docs.templateId}
        />
      </DrawerSectionCard>

      <DrawerSectionCard
        title="Shared parameters"
        description="These fields apply to every cover template."
      >
        <ApiParametersTable parameters={docs.sharedParameters} />
      </DrawerSectionCard>

      <DrawerSectionCard
        title={`Parameters for ${docs.templateLabel}`}
        description={docs.templateDescription}
      >
        <ApiParametersTable parameters={docs.templateParameters} />
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
          {!docs.recommendsPost ? (
            <ApiCodeExample
              title="GET URL"
              description="Works for the current cover."
              code={docs.getUrl}
              language="http"
            />
          ) : null}
          <ApiCodeExample
            title="cURL (POST)"
            description="Recommended for the current cover."
            code={docs.curlPost}
            language="bash"
          />
          <ApiCodeExample
            title="cURL (POST download)"
            description="Adds disposition=attachment to download the image."
            code={docs.curlPostDownload}
            language="bash"
          />
          <ApiCodeExample title="fetch (POST)" code={docs.fetchExample} language="javascript" />
          <ApiCodeExample
            title="fetch (POST download)"
            code={docs.fetchDownloadExample}
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
    </div>
  )
}

function DiagramApiDocsContent({
  document,
  origin,
}: {
  document: DiagramDocument
  origin: string
}) {
  const docs = useMemo(
    () => buildDiagramApiDocsContext(document, origin),
    [document, origin],
  )
  const payloadJson = JSON.stringify(docs.displayPayload, null, 2)

  return (
    <div className="space-y-6">
      <DrawerSectionCard
        title="Method"
        description="Diagram rendering is POST-only because the payload is a structured JSON document."
      >
        <div className="space-y-4">
          <p className="text-[13px] leading-6 text-muted-foreground">
            Send the full diagram document as JSON, including canvas settings, positioned nodes,
            and edge connections.
          </p>
          <ConnectCodeExample code={`POST ${docs.endpointUrl}`} language="http" />
        </div>
      </DrawerSectionCard>

      <DrawerSectionCard
        title="Query parameters"
        description="Optional on POST requests."
      >
        <ApiParametersTable parameters={docs.queryParameters} />
      </DrawerSectionCard>

      <DrawerSectionCard
        title="Document fields"
        description="Top-level fields for the artboard and diagram metadata."
      >
        <ApiParametersTable parameters={docs.documentParameters} />
      </DrawerSectionCard>

      <DrawerSectionCard
        title="Node fields"
        description="Each item in the nodes array uses these fields."
      >
        <ApiParametersTable parameters={docs.nodeParameters} />
      </DrawerSectionCard>

      <DrawerSectionCard
        title="Edge fields"
        description="Each item in the edges array connects two nodes."
      >
        <ApiParametersTable parameters={docs.edgeParameters} />
      </DrawerSectionCard>

      <DrawerSectionCard
        title="Examples for current diagram"
        description="Generated from the values in the editor right now."
      >
        <div className="space-y-6">
          <ApiCodeExample
            title="Current JSON body"
            code={payloadJson}
            language="json"
          />
          <ApiCodeExample title="cURL (POST)" code={docs.curlPost} language="bash" />
          <ApiCodeExample
            title="cURL (POST download)"
            description="Adds disposition=attachment to download the image."
            code={docs.curlPostDownload}
            language="bash"
          />
          <ApiCodeExample title="fetch (POST)" code={docs.fetchExample} language="javascript" />
          <ApiCodeExample
            title="fetch (POST download)"
            code={docs.fetchDownloadExample}
            language="javascript"
          />
        </div>
      </DrawerSectionCard>
    </div>
  )
}

export function GeneratorApiDocsDrawer({
  open,
  onOpenChange,
  activeTab,
  coverData,
  diagramDocument,
  origin = typeof window !== 'undefined' ? window.location.origin : '',
}: GeneratorApiDocsDrawerProps) {
  const [docsTab, setDocsTab] = useState<GeneratorApiTab>(activeTab)

  const resolvedTab = docsTab
  const resolvedCoverData = coverData ?? buildDefaultCoverRenderData()
  const resolvedDiagramDocument =
    diagramDocument ?? buildDefaultDiagramReferenceDocument()
  const coverDocs = buildCoverApiDocsContext(resolvedCoverData, origin)
  const diagramDocs = buildDiagramApiDocsContext(resolvedDiagramDocument, origin)
  const usingCoverFallback = !coverData
  const usingDiagramFallback = !diagramDocument

  const handleCopyMarkdown = async () => {
    try {
      await navigator.clipboard.writeText(
        buildGeneratorApiDocsMarkdown(coverDocs, diagramDocs),
      )
      toast.success('Markdown copied')
    } catch {
      toast.error('Could not copy markdown')
    }
  }

  const handleCopyActiveMarkdown = async () => {
    try {
      const markdown =
        resolvedTab === 'covers'
          ? buildCoverApiDocsMarkdown(coverDocs)
          : buildDiagramApiDocsMarkdown(diagramDocs)
      await navigator.clipboard.writeText(markdown)
      toast.success('Markdown copied')
    } catch {
      toast.error('Could not copy markdown')
    }
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={(nextOpen) => {
        if (nextOpen) setDocsTab(activeTab)
        onOpenChange(nextOpen)
      }}
      title="Generator API"
      description="Programmatic rendering for cover images and architecture diagrams."
      maxWidth="sm:max-w-2xl"
      disableAutoFocus
      headerActions={
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 text-[12px]"
          onClick={handleCopyActiveMarkdown}
        >
          <Copy className="me-1.5 size-3.5" />
          Copy markdown
        </Button>
      }
    >
      <>
        <div className="shrink-0 border-t border-border" />

        <div className="flex min-h-0 flex-1 flex-col">
          <div className="min-h-0 flex-1 overflow-y-auto">
            <div className="space-y-6 px-6 py-6">
              <div className="flex flex-wrap gap-2">
                <Button
                  type="button"
                  size="sm"
                  variant={resolvedTab === 'covers' ? 'default' : 'outline'}
                  className="h-8 text-[12px]"
                  onClick={() => setDocsTab('covers')}
                >
                  Covers
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant={resolvedTab === 'diagrams' ? 'default' : 'outline'}
                  className="h-8 text-[12px]"
                  onClick={() => setDocsTab('diagrams')}
                >
                  Diagrams
                </Button>
              </div>

              <p className="text-[13px] leading-6 text-muted-foreground">
                {resolvedTab === 'covers'
                  ? 'Render PNG, JPEG, or AVIF cover images from template parameters. Available on deployments with the marketing profile enabled.'
                  : 'Render PNG, JPEG, or AVIF diagrams from a JSON document with nodes and edges. Available on deployments with the marketing profile enabled.'}
              </p>

              <DrawerSectionCard title="Available endpoints">
                <ConnectCodeExample
                  code={`GET  ${origin}${GENERATOR_COVER_API_PATH}\nPOST ${origin}${GENERATOR_COVER_API_PATH}\nPOST ${origin}${GENERATOR_DIAGRAM_API_PATH}`}
                  language="http"
                />
              </DrawerSectionCard>

              {resolvedTab === 'covers' ? (
                <>
                  {usingCoverFallback ? (
                    <p className="text-[13px] text-muted-foreground">
                      Showing reference docs for the default simple-title template. Open the
                      Covers tab to generate examples from your current cover.
                    </p>
                  ) : null}
                  <CoverApiDocsContent data={resolvedCoverData} origin={origin} />
                </>
              ) : (
                <>
                  {usingDiagramFallback ? (
                    <p className="text-[13px] text-muted-foreground">
                      Showing reference docs for the three-tier template. Open the Diagrams tab
                      to generate examples from your current diagram.
                    </p>
                  ) : null}
                  <DiagramApiDocsContent
                    document={resolvedDiagramDocument}
                    origin={origin}
                  />
                </>
              )}

              <ApiResponseSection />

              <div className="flex justify-end">
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-8 text-[12px] text-muted-foreground"
                  onClick={handleCopyMarkdown}
                >
                  Copy combined markdown for covers and diagrams
                </Button>
              </div>
            </div>
          </div>
        </div>
      </>
    </BaseDrawer>
  )
}
