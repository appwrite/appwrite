"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.SocksProxyServer = exports.SocksInterceptedSocketHandler = void 0;
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
const net_1 = __importDefault(require("net"));
const debugLogger_1 = require("../utils/debugLogger");
const instrumentation_1 = require("./instrumentation");
var ConnectionPhases;
(function (ConnectionPhases) {
    ConnectionPhases[ConnectionPhases["VERSION"] = 0] = "VERSION";
    ConnectionPhases[ConnectionPhases["NMETHODS"] = 1] = "NMETHODS";
    ConnectionPhases[ConnectionPhases["METHODS"] = 2] = "METHODS";
    ConnectionPhases[ConnectionPhases["REQ_CMD"] = 3] = "REQ_CMD";
    ConnectionPhases[ConnectionPhases["REQ_RSV"] = 4] = "REQ_RSV";
    ConnectionPhases[ConnectionPhases["REQ_ATYP"] = 5] = "REQ_ATYP";
    ConnectionPhases[ConnectionPhases["REQ_DSTADDR"] = 6] = "REQ_DSTADDR";
    ConnectionPhases[ConnectionPhases["REQ_DSTADDR_VARLEN"] = 7] = "REQ_DSTADDR_VARLEN";
    ConnectionPhases[ConnectionPhases["REQ_DSTPORT"] = 8] = "REQ_DSTPORT";
    ConnectionPhases[ConnectionPhases["DONE"] = 9] = "DONE";
})(ConnectionPhases || (ConnectionPhases = {}));
var SOCKS_AUTH_METHOD;
(function (SOCKS_AUTH_METHOD) {
    SOCKS_AUTH_METHOD[SOCKS_AUTH_METHOD["NO_AUTH"] = 0] = "NO_AUTH";
})(SOCKS_AUTH_METHOD || (SOCKS_AUTH_METHOD = {}));
var SOCKS_CMD;
(function (SOCKS_CMD) {
    SOCKS_CMD[SOCKS_CMD["CONNECT"] = 1] = "CONNECT";
    SOCKS_CMD[SOCKS_CMD["BIND"] = 2] = "BIND";
    SOCKS_CMD[SOCKS_CMD["UDP"] = 3] = "UDP";
})(SOCKS_CMD || (SOCKS_CMD = {}));
var SOCKS_ATYP;
(function (SOCKS_ATYP) {
    SOCKS_ATYP[SOCKS_ATYP["IPv4"] = 1] = "IPv4";
    SOCKS_ATYP[SOCKS_ATYP["NAME"] = 3] = "NAME";
    SOCKS_ATYP[SOCKS_ATYP["IPv6"] = 4] = "IPv6";
})(SOCKS_ATYP || (SOCKS_ATYP = {}));
var SOCKS_REPLY;
(function (SOCKS_REPLY) {
    SOCKS_REPLY[SOCKS_REPLY["SUCCESS"] = 0] = "SUCCESS";
})(SOCKS_REPLY || (SOCKS_REPLY = {}));
const SOCKS_VERSION = 0x5;
const BUF_REP_INTR_SUCCESS = Buffer.from([
    0x05,
    SOCKS_REPLY.SUCCESS,
    0x00,
    0x01,
    0x00, 0x00, 0x00, 0x00,
    0x00, 0x00
]);
/**
 * https://tools.ietf.org/html/rfc1928
 */
class SocksV5ServerParser {
    constructor(socket) {
        this._dstAddrp = 0;
        this._phase = ConnectionPhases.VERSION;
        this._methodsp = 0;
        this._socket = socket;
        this._info = { srcAddr: socket.remoteAddress, srcPort: socket.remotePort, dstAddr: '', dstPort: 0 };
        this._parsingFinished = new Promise((resolve, reject) => {
            this._parsingFinishedResolve = resolve;
            this._parsingFinishedReject = reject;
        });
        socket.on('data', this._onData.bind(this));
        socket.on('error', () => { });
    }
    _onData(chunk) {
        const socket = this._socket;
        let i = 0;
        const readByte = () => chunk[i++];
        const closeSocketOnError = () => {
            socket.end();
            this._parsingFinishedReject(new Error('Parsing aborted'));
        };
        while (i < chunk.length && this._phase !== ConnectionPhases.DONE) {
            switch (this._phase) {
                case ConnectionPhases.VERSION:
                    if (readByte() !== SOCKS_VERSION)
                        return closeSocketOnError();
                    this._phase = ConnectionPhases.NMETHODS;
                    break;
                case ConnectionPhases.NMETHODS:
                    this._authMethods = Buffer.alloc(readByte());
                    this._phase = ConnectionPhases.METHODS;
                    break;
                case ConnectionPhases.METHODS: {
                    if (!this._authMethods)
                        return closeSocketOnError();
                    chunk.copy(this._authMethods, 0, i, i + chunk.length);
                    if (!this._authMethods.includes(SOCKS_AUTH_METHOD.NO_AUTH))
                        return closeSocketOnError();
                    const left = this._authMethods.length - this._methodsp;
                    const chunkLeft = chunk.length - i;
                    const minLen = (left < chunkLeft ? left : chunkLeft);
                    chunk.copy(this._authMethods, this._methodsp, i, i + minLen);
                    this._methodsp += minLen;
                    i += minLen;
                    if (this._methodsp !== this._authMethods.length)
                        return closeSocketOnError();
                    if (i < chunk.length)
                        this._socket.unshift(chunk.slice(i));
                    this._authWithoutPassword(socket);
                    this._phase = ConnectionPhases.REQ_CMD;
                    break;
                }
                case ConnectionPhases.REQ_CMD:
                    if (readByte() !== SOCKS_VERSION)
                        return closeSocketOnError();
                    const cmd = readByte();
                    if (cmd !== SOCKS_CMD.CONNECT)
                        return closeSocketOnError();
                    this._phase = ConnectionPhases.REQ_RSV;
                    break;
                case ConnectionPhases.REQ_RSV:
                    readByte();
                    this._phase = ConnectionPhases.REQ_ATYP;
                    break;
                case ConnectionPhases.REQ_ATYP:
                    this._phase = ConnectionPhases.REQ_DSTADDR;
                    this._addressType = readByte();
                    if (!(this._addressType in SOCKS_ATYP))
                        return closeSocketOnError();
                    if (this._addressType === SOCKS_ATYP.IPv4)
                        this._dstAddr = Buffer.alloc(4);
                    else if (this._addressType === SOCKS_ATYP.IPv6)
                        this._dstAddr = Buffer.alloc(16);
                    else if (this._addressType === SOCKS_ATYP.NAME)
                        this._phase = ConnectionPhases.REQ_DSTADDR_VARLEN;
                    break;
                case ConnectionPhases.REQ_DSTADDR: {
                    if (!this._dstAddr)
                        return closeSocketOnError();
                    const left = this._dstAddr.length - this._dstAddrp;
                    const chunkLeft = chunk.length - i;
                    const minLen = (left < chunkLeft ? left : chunkLeft);
                    chunk.copy(this._dstAddr, this._dstAddrp, i, i + minLen);
                    this._dstAddrp += minLen;
                    i += minLen;
                    if (this._dstAddrp === this._dstAddr.length)
                        this._phase = ConnectionPhases.REQ_DSTPORT;
                    break;
                }
                case ConnectionPhases.REQ_DSTADDR_VARLEN:
                    this._dstAddr = Buffer.alloc(readByte());
                    this._phase = ConnectionPhases.REQ_DSTADDR;
                    break;
                case ConnectionPhases.REQ_DSTPORT:
                    if (!this._dstAddr)
                        return closeSocketOnError();
                    if (this._dstPort === undefined) {
                        this._dstPort = readByte();
                        break;
                    }
                    this._dstPort <<= 8;
                    this._dstPort += readByte();
                    this._socket.removeListener('data', this._onData);
                    if (i < chunk.length)
                        this._socket.unshift(chunk.slice(i));
                    if (this._addressType === SOCKS_ATYP.IPv4) {
                        this._info.dstAddr = [...this._dstAddr].join('.');
                    }
                    else if (this._addressType === SOCKS_ATYP.IPv6) {
                        let ipv6str = '';
                        const addr = this._dstAddr;
                        for (let b = 0; b < 16; ++b) {
                            if (b % 2 === 0 && b > 0)
                                ipv6str += ':';
                            ipv6str += (addr[b] < 16 ? '0' : '') + addr[b].toString(16);
                        }
                        this._info.dstAddr = ipv6str;
                    }
                    else {
                        this._info.dstAddr = this._dstAddr.toString();
                    }
                    this._info.dstPort = this._dstPort;
                    this._phase = ConnectionPhases.DONE;
                    this._parsingFinishedResolve();
                    return;
                default:
                    return closeSocketOnError();
            }
        }
    }
    _authWithoutPassword(socket) {
        socket.write(Buffer.from([0x05, 0x00]));
    }
    async ready() {
        await this._parsingFinished;
        return {
            info: this._info,
            forward: () => {
                const dstSocket = new net_1.default.Socket();
                this._socket.on('close', () => dstSocket.end());
                this._socket.on('end', () => dstSocket.end());
                dstSocket.setKeepAlive(false);
                dstSocket.on('error', (err) => writeSocksSocketError(this._socket, String(err)));
                dstSocket.on('connect', () => {
                    this._socket.write(BUF_REP_INTR_SUCCESS);
                    this._socket.pipe(dstSocket).pipe(this._socket);
                    this._socket.resume();
                }).connect(this._info.dstPort, this._info.dstAddr);
            },
            intercept: (parent) => {
                return new SocksInterceptedSocketHandler(parent, this._socket, this._info.dstAddr, this._info.dstPort);
            },
        };
    }
}
class SocksInterceptedSocketHandler extends instrumentation_1.SdkObject {
    constructor(parent, socket, dstAddr, dstPort) {
        super(parent, 'SocksSocket');
        this.socket = socket;
        this.dstAddr = dstAddr;
        this.dstPort = dstPort;
        socket.on('data', data => this.emit('data', data));
        socket.on('close', data => this.emit('close', data));
    }
    connected() {
        this.socket.write(BUF_REP_INTR_SUCCESS);
        this.socket.resume();
    }
    error(error) {
        this.socket.resume();
        writeSocksSocketError(this.socket, error);
    }
    write(data) {
        this.socket.write(data);
    }
    end() {
        this.socket.end();
    }
}
exports.SocksInterceptedSocketHandler = SocksInterceptedSocketHandler;
function writeSocksSocketError(socket, error) {
    if (!socket.writable)
        return;
    socket.write(BUF_REP_INTR_SUCCESS);
    const body = `Connection error: ${error}`;
    socket.end([
        'HTTP/1.1 502 OK',
        'Connection: close',
        'Content-Type: text/plain',
        'Content-Length: ' + Buffer.byteLength(body),
        '',
        body
    ].join('\r\n'));
}
class SocksProxyServer {
    constructor(incomingMessageHandler) {
        this.server = net_1.default.createServer(this._handleConnection.bind(this, incomingMessageHandler));
    }
    async listen(port, host) {
        await new Promise(resolve => this.server.listen(port, host, resolve));
    }
    async _handleConnection(incomingMessageHandler, socket) {
        const parser = new SocksV5ServerParser(socket);
        let parsedSocket;
        try {
            parsedSocket = await parser.ready();
        }
        catch (error) {
            debugLogger_1.debugLogger.log('proxy', `Could not parse: ${error} ${error === null || error === void 0 ? void 0 : error.stack}`);
            return;
        }
        incomingMessageHandler(parsedSocket.info, parsedSocket.forward, parsedSocket.intercept);
    }
    close() {
        this.server.close();
    }
}
exports.SocksProxyServer = SocksProxyServer;
//# sourceMappingURL=socksServer.js.map