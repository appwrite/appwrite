"use strict";
/**
 * Copyright Microsoft Corporation. All rights reserved.
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
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    Object.defineProperty(o, k2, { enumerable: true, get: function() { return m[k]; } });
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.AdbBackend = void 0;
const assert_1 = __importDefault(require("assert"));
const debug_1 = __importDefault(require("debug"));
const net = __importStar(require("net"));
const events_1 = require("events");
const utils_1 = require("../../utils/utils");
class AdbBackend {
    async devices() {
        const result = await runCommand('host:devices');
        const lines = result.toString().trim().split('\n');
        return lines.map(line => {
            const [serial, status] = line.trim().split('\t');
            return new AdbDevice(serial, status);
        });
    }
}
exports.AdbBackend = AdbBackend;
class AdbDevice {
    constructor(serial, status) {
        this.serial = serial;
        this.status = status;
    }
    async init() {
    }
    async close() {
    }
    runCommand(command) {
        return runCommand(command, this.serial);
    }
    async open(command) {
        const result = await open(command, this.serial);
        result.becomeSocket();
        return result;
    }
}
async function runCommand(command, serial) {
    debug_1.default('pw:adb:runCommand')(command, serial);
    const socket = new BufferedSocketWrapper(command, net.createConnection({ port: 5037 }));
    if (serial) {
        await socket.write(encodeMessage(`host:transport:${serial}`));
        const status = await socket.read(4);
        assert_1.default(status.toString() === 'OKAY', status.toString());
    }
    await socket.write(encodeMessage(command));
    const status = await socket.read(4);
    assert_1.default(status.toString() === 'OKAY', status.toString());
    let commandOutput;
    if (!command.startsWith('shell:')) {
        const remainingLength = parseInt((await socket.read(4)).toString(), 16);
        commandOutput = await socket.read(remainingLength);
    }
    else {
        commandOutput = await socket.readAll();
    }
    socket.close();
    return commandOutput;
}
async function open(command, serial) {
    const socket = new BufferedSocketWrapper(command, net.createConnection({ port: 5037 }));
    if (serial) {
        await socket.write(encodeMessage(`host:transport:${serial}`));
        const status = await socket.read(4);
        assert_1.default(status.toString() === 'OKAY', status.toString());
    }
    await socket.write(encodeMessage(command));
    const status = await socket.read(4);
    assert_1.default(status.toString() === 'OKAY', status.toString());
    return socket;
}
function encodeMessage(message) {
    let lenHex = (message.length).toString(16);
    lenHex = '0'.repeat(4 - lenHex.length) + lenHex;
    return Buffer.from(lenHex + message);
}
class BufferedSocketWrapper extends events_1.EventEmitter {
    constructor(command, socket) {
        super();
        this.guid = utils_1.createGuid();
        this._buffer = Buffer.from([]);
        this._isSocket = false;
        this._isClosed = false;
        this._command = command;
        this._socket = socket;
        this._connectPromise = new Promise(f => this._socket.on('connect', f));
        this._socket.on('data', data => {
            debug_1.default('pw:adb:data')(data.toString());
            if (this._isSocket) {
                this.emit('data', data);
                return;
            }
            this._buffer = Buffer.concat([this._buffer, data]);
            if (this._notifyReader)
                this._notifyReader();
        });
        this._socket.on('close', () => {
            this._isClosed = true;
            if (this._notifyReader)
                this._notifyReader();
            this.close();
            this.emit('close');
        });
        this._socket.on('error', error => this.emit('error', error));
    }
    async write(data) {
        debug_1.default('pw:adb:send')(data.toString().substring(0, 100) + '...');
        await this._connectPromise;
        await new Promise(f => this._socket.write(data, f));
    }
    close() {
        if (this._isClosed)
            return;
        debug_1.default('pw:adb')('Close ' + this._command);
        this._socket.destroy();
    }
    async read(length) {
        await this._connectPromise;
        assert_1.default(!this._isSocket, 'Can not read by length in socket mode');
        while (this._buffer.length < length)
            await new Promise(f => this._notifyReader = f);
        const result = this._buffer.slice(0, length);
        this._buffer = this._buffer.slice(length);
        debug_1.default('pw:adb:recv')(result.toString().substring(0, 100) + '...');
        return result;
    }
    async readAll() {
        while (!this._isClosed)
            await new Promise(f => this._notifyReader = f);
        return this._buffer;
    }
    becomeSocket() {
        assert_1.default(!this._buffer.length);
        this._isSocket = true;
    }
}
//# sourceMappingURL=backendAdb.js.map