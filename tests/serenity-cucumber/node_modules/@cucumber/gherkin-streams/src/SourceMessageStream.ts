import { makeSourceEnvelope } from '@cucumber/gherkin'
import { relative } from 'path'
import { Transform, TransformCallback } from 'stream'

/**
 * Stream that reads a string and writes a single Source message.
 */
export default class SourceMessageStream extends Transform {
  private buffer = Buffer.alloc(0)

  constructor(
    private readonly uri: string,
    private readonly relativeTo?: string
  ) {
    super({ readableObjectMode: true, writableObjectMode: false })
  }

  public _transform(
    chunk: Buffer,
    encoding: string,
    callback: TransformCallback
  ) {
    this.buffer = Buffer.concat([this.buffer, chunk])
    callback()
  }

  public _flush(callback: TransformCallback) {
    const data = this.buffer.toString('utf-8')
    const chunk = makeSourceEnvelope(
      data,
      this.relativeTo ? relative(this.relativeTo, this.uri) : this.uri
    )
    this.push(chunk)
    callback()
  }
}
