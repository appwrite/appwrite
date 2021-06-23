"use strict";
/**
 * Copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
Object.defineProperty(exports, "__esModule", { value: true });
exports.Stream = void 0;
const stream_1 = require("stream");
const channelOwner_1 = require("./channelOwner");
class Stream extends channelOwner_1.ChannelOwner {
    static from(Stream) {
        return Stream._object;
    }
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
    }
    stream() {
        return new StreamImpl(this._channel);
    }
}
exports.Stream = Stream;
class StreamImpl extends stream_1.Readable {
    constructor(channel) {
        super();
        this._channel = channel;
    }
    async _read(size) {
        const result = await this._channel.read({ size });
        if (result.binary)
            this.push(Buffer.from(result.binary, 'base64'));
        else
            this.push(null);
    }
    _destroy(error, callback) {
        // Stream might be destroyed after the connection was closed.
        this._channel.close().catch(e => null);
        super._destroy(error, callback);
    }
}
//# sourceMappingURL=stream.js.map