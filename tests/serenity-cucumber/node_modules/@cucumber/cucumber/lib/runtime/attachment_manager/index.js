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
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const is_stream_1 = __importDefault(require("is-stream"));
const messages = __importStar(require("@cucumber/messages"));
const value_checker_1 = require("../../value_checker");
class AttachmentManager {
    constructor(onAttachment) {
        this.onAttachment = onAttachment;
    }
    log(text) {
        return this.create(text, 'text/x.cucumber.log+plain');
    }
    create(data, mediaTypeOrOptions, callback) {
        const options = normaliseOptions(mediaTypeOrOptions);
        if (Buffer.isBuffer(data)) {
            if ((0, value_checker_1.doesNotHaveValue)(options.mediaType)) {
                throw Error('Buffer attachments must specify a media type');
            }
            this.createBufferAttachment(data, options.mediaType, options.fileName);
        }
        else if (is_stream_1.default.readable(data)) {
            if ((0, value_checker_1.doesNotHaveValue)(options.mediaType)) {
                throw Error('Stream attachments must specify a media type');
            }
            return this.createStreamAttachment(data, options.mediaType, options.fileName, callback);
        }
        else if (typeof data === 'string') {
            if ((0, value_checker_1.doesNotHaveValue)(options.mediaType)) {
                options.mediaType = 'text/plain';
            }
            if (options.mediaType.startsWith('base64:')) {
                this.createStringAttachment(data, {
                    encoding: messages.AttachmentContentEncoding.BASE64,
                    contentType: options.mediaType.replace('base64:', ''),
                }, options.fileName);
            }
            else {
                this.createStringAttachment(data, {
                    encoding: messages.AttachmentContentEncoding.IDENTITY,
                    contentType: options.mediaType,
                }, options.fileName);
            }
        }
        else {
            throw Error('Invalid attachment data: must be a buffer, readable stream, or string');
        }
    }
    createBufferAttachment(data, mediaType, fileName) {
        this.createStringAttachment(data.toString('base64'), {
            encoding: messages.AttachmentContentEncoding.BASE64,
            contentType: mediaType,
        }, fileName);
    }
    createStreamAttachment(data, mediaType, fileName, callback) {
        const promise = new Promise((resolve, reject) => {
            const buffers = [];
            data.on('data', (chunk) => {
                buffers.push(chunk);
            });
            data.on('end', () => {
                this.createBufferAttachment(Buffer.concat(buffers), mediaType, fileName);
                resolve();
            });
            data.on('error', reject);
        });
        if ((0, value_checker_1.doesHaveValue)(callback)) {
            promise.then(callback, callback);
        }
        else {
            return promise;
        }
    }
    createStringAttachment(data, media, fileName) {
        this.onAttachment({
            data,
            media,
            ...(fileName ? { fileName } : {}),
        });
    }
}
exports.default = AttachmentManager;
function normaliseOptions(mediaTypeOrOptions) {
    if (!mediaTypeOrOptions) {
        return {};
    }
    if (typeof mediaTypeOrOptions === 'string') {
        return {
            mediaType: mediaTypeOrOptions,
        };
    }
    return mediaTypeOrOptions;
}
//# sourceMappingURL=index.js.map