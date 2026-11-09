import { afterAll, afterEach, describe, expect, mock, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { createElement, type ReactNode } from 'react'
import { join } from 'node:path'

GlobalRegistrator.register()
const { cleanup, fireEvent, render } = await import('@testing-library/react')

const consoleRoot = join(import.meta.dir, '../..')
const domainDir = join(
  consoleRoot,
  'src/components/pages/organizations/$orgId/domains/$domainId',
)

// Render the dialog in place so the test does not depend on a portal.
const dialogPath = join(consoleRoot, 'src/components/ui/dialog.tsx')
const realDialog = { ...(await import(dialogPath)) }
const part = ({ children }: { children?: ReactNode }) =>
  createElement('div', null, children)
mock.module(dialogPath, () => ({
  ...realDialog,
  Dialog: ({ open, children }: { open?: boolean; children?: ReactNode }) =>
    open ? createElement('div', null, children) : null,
  DialogContent: part,
  DialogHeader: part,
  DialogTitle: part,
  DialogDescription: part,
}))

afterEach(() => cleanup())

afterAll(async () => {
  mock.module(dialogPath, () => realDialog)
  mock.restore()
  await GlobalRegistrator.unregister()
})

for (const file of ['ImportZone.tsx', 'ImportZoneDialog.tsx']) {
  describe(file, () => {
    test('says the limit is 5MB when the file is too large', async () => {
      const { ImportZoneDialog } = await import(join(domainDir, file))
      const view = render(
        createElement(ImportZoneDialog, {
          open: true,
          onOpenChange: () => {},
          onImport: () => {},
        }),
      )
      const input = view.container.querySelector(
        'input[type="file"]',
      ) as HTMLInputElement
      const zone = new File(['a'], 'zone.txt', { type: 'text/plain' })
      Object.defineProperty(zone, 'size', { value: 6 * 1024 * 1024 })

      fireEvent.change(input, { target: { files: [zone] } })

      expect(view.getByText('File size must be less than 5MB')).toBeTruthy()
    })
  })
}
