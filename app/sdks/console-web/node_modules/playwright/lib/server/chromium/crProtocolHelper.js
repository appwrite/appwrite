"use strict";
/**
 * Copyright 2017 Google Inc. All rights reserved.
 * Modifications copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.toModifiersMask = exports.exceptionToError = exports.toConsoleMessageLocation = exports.readProtocolStream = exports.releaseObject = exports.getExceptionMessage = void 0;
const fs_1 = __importDefault(require("fs"));
const utils_1 = require("../../utils/utils");
const stackTrace_1 = require("../../utils/stackTrace");
function getExceptionMessage(exceptionDetails) {
    if (exceptionDetails.exception)
        return exceptionDetails.exception.description || String(exceptionDetails.exception.value);
    let message = exceptionDetails.text;
    if (exceptionDetails.stackTrace) {
        for (const callframe of exceptionDetails.stackTrace.callFrames) {
            const location = callframe.url + ':' + callframe.lineNumber + ':' + callframe.columnNumber;
            const functionName = callframe.functionName || '<anonymous>';
            message += `\n    at ${functionName} (${location})`;
        }
    }
    return message;
}
exports.getExceptionMessage = getExceptionMessage;
async function releaseObject(client, objectId) {
    await client.send('Runtime.releaseObject', { objectId }).catch(error => { });
}
exports.releaseObject = releaseObject;
async function readProtocolStream(client, handle, path) {
    let eof = false;
    let fd;
    if (path) {
        await utils_1.mkdirIfNeeded(path);
        fd = await fs_1.default.promises.open(path, 'w');
    }
    const bufs = [];
    while (!eof) {
        const response = await client.send('IO.read', { handle });
        eof = response.eof;
        const buf = Buffer.from(response.data, response.base64Encoded ? 'base64' : undefined);
        bufs.push(buf);
        if (fd)
            await fd.write(buf);
    }
    if (fd)
        await fd.close();
    await client.send('IO.close', { handle });
    return Buffer.concat(bufs);
}
exports.readProtocolStream = readProtocolStream;
function toConsoleMessageLocation(stackTrace) {
    return stackTrace && stackTrace.callFrames.length ? {
        url: stackTrace.callFrames[0].url,
        lineNumber: stackTrace.callFrames[0].lineNumber,
        columnNumber: stackTrace.callFrames[0].columnNumber,
    } : { url: '', lineNumber: 0, columnNumber: 0 };
}
exports.toConsoleMessageLocation = toConsoleMessageLocation;
function exceptionToError(exceptionDetails) {
    const messageWithStack = getExceptionMessage(exceptionDetails);
    const lines = messageWithStack.split('\n');
    const firstStackTraceLine = lines.findIndex(line => line.startsWith('    at'));
    let messageWithName = '';
    let stack = '';
    if (firstStackTraceLine === -1) {
        messageWithName = messageWithStack;
    }
    else {
        messageWithName = lines.slice(0, firstStackTraceLine).join('\n');
        stack = messageWithStack;
    }
    const { name, message } = stackTrace_1.splitErrorMessage(messageWithName);
    const err = new Error(message);
    err.stack = stack;
    err.name = name;
    return err;
}
exports.exceptionToError = exceptionToError;
function toModifiersMask(modifiers) {
    let mask = 0;
    if (modifiers.has('Alt'))
        mask |= 1;
    if (modifiers.has('Control'))
        mask |= 2;
    if (modifiers.has('Meta'))
        mask |= 4;
    if (modifiers.has('Shift'))
        mask |= 8;
    return mask;
}
exports.toModifiersMask = toModifiersMask;
//# sourceMappingURL=crProtocolHelper.js.map