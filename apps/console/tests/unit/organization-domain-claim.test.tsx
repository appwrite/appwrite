import { afterAll, afterEach, describe, expect, mock, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { AppwriteException } from '@appwrite.io/console'
import { createElement } from 'react'
import { join } from 'node:path'

GlobalRegistrator.register()
const { act, cleanup, fireEvent, render } = await import(
  '@testing-library/react'
)

const consoleRoot = join(import.meta.dir, '../..')
const inputPath = join(consoleRoot, 'src/components/ui/input.tsx')
const dialogPath = join(
  consoleRoot,
  'src/components/pages/organizations/$orgId/domains/CreateDomain.tsx',
)

let creates: string[] = []
let claimed = false

// React DOM wires up typing only if a DOM existed when it first loaded, which
// depends on the files run before this one, so the test types through props.
let typeDomain: (value: string) => void = () => {}
function FakeInput(props: {
  placeholder?: string
  value?: string
  onChange?: (event: { target: { value: string } }) => void
}) {
  typeDomain = (value) => props.onChange?.({ target: { value } })
  return createElement('input', {
    placeholder: props.placeholder,
    value: props.value,
    readOnly: true,
  })
}

mock.module(inputPath, () => ({ Input: FakeInput }))

// Another organization holds the domain until the record is in place.
async function onCreate(domain: string) {
  creates.push(domain)
  if (claimed) return
  throw new AppwriteException(
    'This domain belongs to another organization. Add a TXT record _appwrite.example.com with the value domain:org1, then create the domain again to claim it.',
    409,
    'domain_claim_required',
  )
}

afterAll(async () => {
  cleanup()
  mock.restore()
  await GlobalRegistrator.unregister()
})

afterEach(() => {
  cleanup()
  creates = []
  claimed = false
})

describe('CreateDomainDialog claim step', () => {
  test('shows the TXT record when another organization holds the domain, and claims it by creating again', async () => {
    const { CreateDomainDialog } = await import(dialogPath)
    const view = render(
      createElement(CreateDomainDialog, {
        open: true,
        onOpenChange: () => {},
        onCreate,
        organizationId: 'org1',
      }),
    )

    act(() => typeDomain('example.com'))
    fireEvent.click(view.getByText('Add Domain', { selector: 'button' }))

    expect(await view.findByText('Claim domain ownership')).toBeTruthy()
    expect(view.getAllByText('_appwrite').length).toBeGreaterThan(0)
    expect(view.getAllByText('domain:org1').length).toBeGreaterThan(0)

    // Still not there: the step stays, with the server's message.
    fireEvent.click(view.getByText('Verify & claim'))
    expect(
      await view.findByText(
        'This domain belongs to another organization. Add a TXT record _appwrite.example.com with the value domain:org1, then create the domain again to claim it.',
      ),
    ).toBeTruthy()

    claimed = true
    fireEvent.click(view.getByText('Verify & claim'))
    await act(async () => {})
    expect(creates).toEqual(['example.com', 'example.com', 'example.com'])
  })
})
