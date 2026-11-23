'use client'

import { useMemo, useState } from 'react'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { MethodDescriptionMarkdown } from '@/components/global/api-explorer/MethodDescriptionMarkdown'
import type { ReferenceVersion } from '@/lib/docs/references/constants'
import type { ApiReferenceModelData } from '@/lib/docs/references/types'
import { DocsLayout } from '../DocsLayout'
import { ApiReferencePropertyTypeCell } from './ApiReferencePropertyTypeCell'
import { ApiReferenceCopyableName } from './_components/ApiReferenceCopyableName'

type ModelViewProps = {
  data: ApiReferenceModelData
  version: ReferenceVersion
}

export function ApiReferenceModelView({ data, version }: ModelViewProps) {
  const slug = `references/${version}/models/${data.id}`
  const [exampleTab, setExampleTab] = useState(
    () => data.examples[0]?.type.toLowerCase() ?? 'rest',
  )

  const activeExample = useMemo(
    () =>
      data.examples.find((example) => example.type.toLowerCase() === exampleTab) ??
      data.examples[0],
    [data.examples, exampleTab],
  )

  return (
    <DocsLayout slug={slug} title={data.title} toc={[]}>
      <div className="space-y-10">
        <section id="properties" className="scroll-mt-28 space-y-4">
          <h2 className="text-[20px] font-semibold tracking-tight text-foreground">
            Properties
          </h2>
          <div className="overflow-hidden rounded-xl border border-border">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Name
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Type
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Description
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.properties.map((property) => (
                  <TableRow key={property.name}>
                    <TableCell className="px-4 py-3">
                      <ApiReferenceCopyableName
                        name={property.name}
                        textClassName="text-[13px] text-foreground"
                      />
                    </TableCell>
                    <TableCell className="px-4 py-3 align-top">
                      <ApiReferencePropertyTypeCell property={property} />
                    </TableCell>
                    <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                      {property.description ? (
                        <MethodDescriptionMarkdown
                          content={
                            property.relatedModels
                              ? `${property.description} Can be one of: ${property.relatedModels}`
                              : property.description
                          }
                          className="border-0 bg-transparent p-0 text-[13px] text-muted-foreground [&_a]:text-foreground [&_a]:underline-offset-4 [&_a]:hover:underline [&_code]:bg-muted/50"
                        />
                      ) : null}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </section>

        {activeExample ? (
          <section id="example" className="scroll-mt-28 space-y-4">
            <h2 className="text-[20px] font-semibold tracking-tight text-foreground">
              Example
            </h2>
            <ConnectCodeExample
              code={JSON.stringify(activeExample.example, null, 2)}
              language="json"
              tabs={
                data.examples.length > 1
                  ? data.examples.map((example) => ({
                      id: example.type.toLowerCase(),
                      label: example.type,
                    }))
                  : undefined
              }
              activeTabId={exampleTab}
              onTabChange={setExampleTab}
            />
          </section>
        ) : null}
      </div>
    </DocsLayout>
  )
}
