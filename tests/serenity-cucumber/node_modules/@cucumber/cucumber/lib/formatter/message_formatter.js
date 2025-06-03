"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const _1 = __importDefault(require("."));
class MessageFormatter extends _1.default {
    constructor(options) {
        super(options);
        options.eventBroadcaster.on('envelope', (envelope) => this.log(JSON.stringify(envelope) + '\n'));
    }
}
exports.default = MessageFormatter;
MessageFormatter.documentation = 'Outputs protobuf messages';
//# sourceMappingURL=message_formatter.js.map