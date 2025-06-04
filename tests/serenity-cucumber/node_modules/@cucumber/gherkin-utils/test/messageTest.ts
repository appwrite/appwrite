import assert from 'assert'
import * as messages from '@cucumber/messages'
import { NdjsonToMessageStream } from '@cucumber/message-streams'
import { Writable, pipeline } from 'stream'

import { GherkinDocumentWalker } from '../src'
import fs from 'fs'
import fg from 'fast-glob'
import { promisify } from 'util'

const asyncPipeline = promisify(pipeline)

describe('Walking with messages', () => {
  const localMessageFiles = fg.sync(`${__dirname}/messages/**/*.ndjson`)
  const tckMessageFiles = fg.sync(
    `${__dirname}/../node_modules/@cucumber/compatibility-kit/features/**/*.ndjson`
  )
  const messageFiles = [].concat(localMessageFiles, tckMessageFiles)

  it('must have some messages for comparison', () => {
    assert.notEqual(messageFiles.length, 0)
  })

  for (const messageFile of messageFiles) {
    it(`can walk through GherkinDocuments in ${messageFile}`, async () => {
      const messageStream = new NdjsonToMessageStream()

      await asyncPipeline(
        fs.createReadStream(messageFile, 'utf-8'),
        messageStream,
        new Writable({
          objectMode: true,
          write(
            envelope: messages.Envelope,
            _encoding: string,
            callback: (error?: Error | null) => void
          ) {
            try {
              if (envelope.gherkinDocument) {
                const walker = new GherkinDocumentWalker()
                walker.walkGherkinDocument(envelope.gherkinDocument)
              }
              callback()
            } catch (error) {
              error.message += `\n${envelope.gherkinDocument.uri}\n`
              callback(error)
            }
          },
        })
      )
    }).timeout(30000)
  }
})
