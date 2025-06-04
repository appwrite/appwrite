"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const _1 = __importDefault(require("."));
const resolve_pkg_1 = __importDefault(require("resolve-pkg"));
const html_formatter_1 = __importDefault(require("@cucumber/html-formatter"));
const stream_1 = require("stream");
const util_1 = require("util");
class HtmlFormatter extends _1.default {
    constructor(options) {
        super(options);
        this._htmlStream = new html_formatter_1.default((0, resolve_pkg_1.default)('@cucumber/html-formatter', { cwd: __dirname }) +
            '/dist/main.css', (0, resolve_pkg_1.default)('@cucumber/html-formatter', { cwd: __dirname }) +
            '/dist/main.js');
        options.eventBroadcaster.on('envelope', (envelope) => {
            this._htmlStream.write(envelope);
        });
        this._htmlStream.on('data', (chunk) => this.log(chunk));
    }
    async finished() {
        this._htmlStream.end();
        await (0, util_1.promisify)(stream_1.finished)(this._htmlStream);
        await super.finished();
    }
}
exports.default = HtmlFormatter;
HtmlFormatter.documentation = 'Outputs HTML report';
//# sourceMappingURL=html_formatter.js.map