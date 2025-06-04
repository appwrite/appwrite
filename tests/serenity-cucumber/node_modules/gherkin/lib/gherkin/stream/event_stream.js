'use strict'

const Stream = require('stream')
const generateEvents = require('../generate_events')

/**
 * Stream that reads a Gherkin document as plain text and writes
 * events.
 */
class EventStream extends Stream.Transform {
  /**
   * @param uri the uri of the Gherkin document written to this stream
   * @param types {object} with keys source,gherkin-document and pickle,
   *   indicating what kinds of events to emit
   */
  constructor(uri, types, language) {
    super({ objectMode: true })
    this._uri = uri
    this._types = types
    this._language = language
    this._gherkin = ""
  }

  _transform(chunk, _, callback) {
    this._gherkin += chunk
    callback()
  }

  _flush(callback) {
    const events = generateEvents(this._gherkin, this._uri, this._types, this._language)
    for (const event of events) {
      this.push(event)
    }
    callback()
  }
}

module.exports = EventStream
