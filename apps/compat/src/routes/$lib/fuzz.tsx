import { createFileRoute, getRouteApi } from '@tanstack/react-router'
import { Chip, Empty, Pill, fmt } from '@/components/ui'
import { requireLib } from '@/lib/route'

const root = getRouteApi('__root__')

export const Route = createFileRoute('/$lib/fuzz')({
  loader: async ({ params }) => (await requireLib(params.lib)).fuzz,
  component: Fuzz,
})

function Fuzz() {
  const fuzz = Route.useLoaderData()
  const index = root.useLoaderData()
  const total = fuzz.reduce((s, f) => s + f.inputs, 0)
  return (
    <>
      <p className="m-0 max-w-[80ch] text-muted-foreground">
        Each profile generates inputs from a seeded generator and sends every input to both runtimes; the first difference stops the profile and is
        shrunk to a minimal input. This report ran {fmt(index?.iterations)} inputs per profile ({fmt(total)} in all); CI runs the counts in the last
        column.
      </p>
      {fuzz.length ? (
        <div className="overflow-x-auto rounded-[10px] border border-border">
          <table className="w-full border-collapse text-[0.82rem]">
            <thead>
              <tr className="border-b border-border text-left text-[0.7rem] uppercase tracking-wider text-muted-foreground">
                {['Operation', 'Profile', 'Inputs run', 'In CI', 'Status'].map((h) => (
                  <th key={h} className="whitespace-nowrap px-3 py-2.5 font-medium">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {fuzz.map((f) => (
                <tr key={`${f.op}[${f.profile}]`} className="border-b border-border last:border-b-0">
                  <td className="px-3 py-2 font-mono text-xs">{f.op}</td>
                  <td className="px-3 py-2">
                    {f.profile} {f.isolate ? <Chip>fresh processes</Chip> : null}
                  </td>
                  <td className="px-3 py-2 tabular-nums">{fmt(f.inputs)}</td>
                  <td className="px-3 py-2 tabular-nums text-muted-foreground">{fmt(f.iterations)}</td>
                  <td className="px-3 py-2">
                    {f.ok ? (
                      <Pill tone="ok">no differences</Pill>
                    ) : (
                      <>
                        <Pill tone="bad">stopped</Pill>
                        <div className="whitespace-pre-wrap text-xs">{f.problem}</div>
                      </>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty>This library has no fuzz profiles: its operations depend on services or time, and cases cover them.</Empty>
      )}
    </>
  )
}
