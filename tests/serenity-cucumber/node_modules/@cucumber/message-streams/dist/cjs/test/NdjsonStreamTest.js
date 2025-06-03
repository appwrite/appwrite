"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
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
var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
var __generator = (this && this.__generator) || function (thisArg, body) {
    var _ = { label: 0, sent: function() { if (t[0] & 1) throw t[1]; return t[1]; }, trys: [], ops: [] }, f, y, t, g;
    return g = { next: verb(0), "throw": verb(1), "return": verb(2) }, typeof Symbol === "function" && (g[Symbol.iterator] = function() { return this; }), g;
    function verb(n) { return function (v) { return step([n, v]); }; }
    function step(op) {
        if (f) throw new TypeError("Generator is already executing.");
        while (_) try {
            if (f = 1, y && (t = op[0] & 2 ? y["return"] : op[0] ? y["throw"] || ((t = y["return"]) && t.call(y), 0) : y.next) && !(t = t.call(y, op[1])).done) return t;
            if (y = 0, t) op = [op[0] & 2, t.value];
            switch (op[0]) {
                case 0: case 1: t = op; break;
                case 4: _.label++; return { value: op[1], done: false };
                case 5: _.label++; y = op[1]; op = [0]; continue;
                case 7: op = _.ops.pop(); _.trys.pop(); continue;
                default:
                    if (!(t = _.trys, t = t.length > 0 && t[t.length - 1]) && (op[0] === 6 || op[0] === 2)) { _ = 0; continue; }
                    if (op[0] === 3 && (!t || (op[1] > t[0] && op[1] < t[3]))) { _.label = op[1]; break; }
                    if (op[0] === 6 && _.label < t[1]) { _.label = t[1]; t = op; break; }
                    if (t && _.label < t[2]) { _.label = t[2]; _.ops.push(op); break; }
                    if (t[2]) _.ops.pop();
                    _.trys.pop(); continue;
            }
            op = body.call(thisArg, _);
        } catch (e) { op = [6, e]; y = 0; } finally { f = t = 0; }
        if (op[0] & 5) throw op[1]; return { value: op[0] ? op[1] : void 0, done: true };
    }
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var messages = __importStar(require("@cucumber/messages"));
var messages_1 = require("@cucumber/messages");
var assert_1 = __importDefault(require("assert"));
var index_js_1 = require("../src/index.js");
var NdjsonToMessageStream_js_1 = __importDefault(require("../src/NdjsonToMessageStream.js"));
var toArray_js_1 = __importDefault(require("./toArray.js"));
var verifyStreamContract_js_1 = __importDefault(require("./verifyStreamContract.js"));
describe('NdjsonStream', function () {
    var makeToMessageStream = function () { return new NdjsonToMessageStream_js_1.default(); };
    var makeFromMessageStream = function () { return new index_js_1.MessageToNdjsonStream(); };
    (0, verifyStreamContract_js_1.default)(makeFromMessageStream, makeToMessageStream);
    it('converts a buffer stream written byte by byte', function (cb) {
        var stream = makeToMessageStream();
        var envelope = {
            testStepFinished: {
                testStepResult: {
                    status: messages.TestStepResultStatus.UNKNOWN,
                    duration: { nanos: 0, seconds: 0 },
                },
                testCaseStartedId: '1',
                testStepId: '2',
                timestamp: {
                    seconds: 0,
                    nanos: 0,
                },
            },
        };
        var json = JSON.stringify(envelope);
        stream.on('error', cb);
        stream.on('data', function (receivedEnvelope) {
            assert_1.default.deepStrictEqual(envelope, receivedEnvelope);
            cb();
        });
        var buffer = Buffer.from(json);
        for (var i = 0; i < buffer.length; i++) {
            stream.write(buffer.slice(i, i + 1));
        }
        stream.end();
    });
    it('converts messages to JSON with enums as strings', function (cb) {
        var stream = new index_js_1.MessageToNdjsonStream();
        stream.on('data', function (json) {
            var ob = JSON.parse(json);
            var expected = {
                testStepFinished: {
                    testStepResult: {
                        status: messages.TestStepResultStatus.UNKNOWN,
                        duration: { nanos: 0, seconds: 0 },
                    },
                    testCaseStartedId: '1',
                    testStepId: '2',
                    timestamp: {
                        seconds: 0,
                        nanos: 0,
                    },
                },
            };
            assert_1.default.deepStrictEqual(ob, expected);
            cb();
        });
        var envelope = {
            testStepFinished: {
                testStepResult: {
                    status: messages.TestStepResultStatus.UNKNOWN,
                    duration: { nanos: 0, seconds: 0 },
                },
                testCaseStartedId: '1',
                testStepId: '2',
                timestamp: {
                    seconds: 0,
                    nanos: 0,
                },
            },
        };
        stream.write(envelope);
    });
    it('ignores empty lines', function () { return __awaiter(void 0, void 0, void 0, function () {
        var toMessageStream, incomingMessages;
        return __generator(this, function (_a) {
            switch (_a.label) {
                case 0:
                    toMessageStream = makeToMessageStream();
                    toMessageStream.write('{}\n{}\n\n{}\n');
                    toMessageStream.end();
                    return [4 /*yield*/, (0, toArray_js_1.default)(toMessageStream)];
                case 1:
                    incomingMessages = _a.sent();
                    assert_1.default.deepStrictEqual(incomingMessages, [
                        new messages_1.Envelope(),
                        new messages_1.Envelope(),
                        new messages_1.Envelope(),
                    ]);
                    return [2 /*return*/];
            }
        });
    }); });
    it('includes offending line in error message', function () { return __awaiter(void 0, void 0, void 0, function () {
        var toMessageStream;
        return __generator(this, function (_a) {
            switch (_a.label) {
                case 0:
                    toMessageStream = makeToMessageStream();
                    return [4 /*yield*/, assert_1.default.rejects(function () { return __awaiter(void 0, void 0, void 0, function () {
                            return __generator(this, function (_a) {
                                switch (_a.label) {
                                    case 0:
                                        toMessageStream.write('{}\n  BLA BLA\n\n{}\n');
                                        toMessageStream.end();
                                        return [4 /*yield*/, (0, toArray_js_1.default)(toMessageStream)];
                                    case 1:
                                        _a.sent();
                                        return [2 /*return*/];
                                }
                            });
                        }); }, {
                            message: "Unexpected token B in JSON at position 2\nNot JSON: '  BLA BLA'\n",
                        })];
                case 1:
                    _a.sent();
                    return [2 /*return*/];
            }
        });
    }); });
});
//# sourceMappingURL=NdjsonStreamTest.js.map