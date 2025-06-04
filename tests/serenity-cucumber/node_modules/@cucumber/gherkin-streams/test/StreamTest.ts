import {
  dialects,
  IGherkinOptions,
  makeSourceEnvelope,
} from '@cucumber/gherkin'
import * as messages from '@cucumber/messages'
import assert from 'assert'
import { Readable } from 'stream'

import { GherkinStreams } from '../src'

const defaultOptions: IGherkinOptions = {}

describe('gherkin', () => {
  it('parses gherkin from the file system', async () => {
    const envelopes = await streamToArray(
      GherkinStreams.fromPaths(
        ['testdata/good/minimal.feature'],
        defaultOptions
      )
    )
    assert.strictEqual(envelopes.length, 3)
    assert.strictEqual(envelopes[0].source.uri, 'testdata/good/minimal.feature')
    assert.strictEqual(
      envelopes[1].gherkinDocument.uri,
      'testdata/good/minimal.feature'
    )
    assert.strictEqual(envelopes[2].pickle.uri, 'testdata/good/minimal.feature')
  })

  it('throws an error when the path is a directory', async () => {
    assert.rejects(async () =>
      streamToArray(GherkinStreams.fromPaths(['testdata/good'], defaultOptions))
    )
  })

  it('emits uris relative to a given path', async () => {
    const envelopes = await streamToArray(
      GherkinStreams.fromPaths(['testdata/good/minimal.feature'], {
        ...defaultOptions,
        relativeTo: 'testdata/good',
      })
    )
    assert.strictEqual(envelopes.length, 3)
    assert.strictEqual(envelopes[0].source.uri, 'minimal.feature')
    assert.strictEqual(envelopes[1].gherkinDocument.uri, 'minimal.feature')
    assert.strictEqual(envelopes[2].pickle.uri, 'minimal.feature')
  })

  it('parses gherkin from STDIN', async () => {
    const source = makeSourceEnvelope(
      `Feature: Minimal

  Scenario: minimalistic
    Given the minimalism
`,
      'test.feature'
    )

    const envelopes = await streamToArray(
      GherkinStreams.fromSources([source], defaultOptions)
    )
    assert.strictEqual(envelopes.length, 3)
  })

  it('parses gherkin using the provided default language', async () => {
    const source = makeSourceEnvelope(
      `Fonctionnalité: i18n support
  Scénario: Support des caractères spéciaux
    Soit un exemple de scénario en français
`,
      'test.feature'
    )
    const envelopes = await streamToArray(
      GherkinStreams.fromSources([source], { defaultDialect: 'fr' })
    )
    assert.strictEqual(envelopes.length, 3)
  })

  it('outputs dialects', async () => {
    assert.strictEqual(dialects.en.name, 'English')
  })
})

async function streamToArray(
  readableStream: Readable
): Promise<messages.Envelope[]> {
  return new Promise<messages.Envelope[]>(
    (
      resolve: (wrappers: messages.Envelope[]) => void,
      reject: (err: Error) => void
    ) => {
      const items: messages.Envelope[] = []
      readableStream.on('data', items.push.bind(items))
      readableStream.on('error', (err: Error) => reject(err))
      readableStream.on('end', () => resolve(items))
    }
  )
}
