import { IGherkinOptions } from '@cucumber/gherkin'
import * as messages from '@cucumber/messages'
import fs from 'fs'
import { PassThrough, Readable } from 'stream'

import makeGherkinOptions from './makeGherkinOptions'
import ParserMessageStream from './ParserMessageStream'
import SourceMessageStream from './SourceMessageStream'

export interface IGherkinStreamOptions extends IGherkinOptions {
  relativeTo?: string
}

function fromPaths(
  paths: readonly string[],
  options: IGherkinStreamOptions
): Readable {
  const pathsCopy = paths.slice()
  options = makeGherkinOptions(options)
  const combinedMessageStream = new PassThrough({
    writableObjectMode: true,
    readableObjectMode: true,
  })

  function pipeSequentially() {
    const path = pathsCopy.shift()
    if (path !== undefined) {
      const parserMessageStream = new ParserMessageStream(options)
      parserMessageStream.on('end', () => {
        pipeSequentially()
      })

      const end = pathsCopy.length === 0
      // Can't use pipeline here because of the { end } argument,
      // so we have to manually propagate errors.
      fs.createReadStream(path, { encoding: 'utf-8' })
        .on('error', (err) => combinedMessageStream.emit('error', err))
        .pipe(new SourceMessageStream(path, options.relativeTo))
        .on('error', (err) => combinedMessageStream.emit('error', err))
        .pipe(parserMessageStream)
        .on('error', (err) => combinedMessageStream.emit('error', err))
        .pipe(combinedMessageStream, { end })
    }
  }
  pipeSequentially()
  return combinedMessageStream
}

function fromSources(
  envelopes: readonly messages.Envelope[],
  options: IGherkinOptions
): Readable {
  const envelopesCopy = envelopes.slice()
  options = makeGherkinOptions(options)
  const combinedMessageStream = new PassThrough({
    writableObjectMode: true,
    readableObjectMode: true,
  })

  function pipeSequentially() {
    const envelope = envelopesCopy.shift()
    if (envelope !== undefined && envelope.source) {
      const parserMessageStream = new ParserMessageStream(options)
      parserMessageStream.pipe(combinedMessageStream, {
        end: envelopesCopy.length === 0,
      })
      parserMessageStream.on('end', pipeSequentially)
      parserMessageStream.end(envelope)
    }
  }
  pipeSequentially()

  return combinedMessageStream
}

export default {
  fromPaths,
  fromSources,
}
