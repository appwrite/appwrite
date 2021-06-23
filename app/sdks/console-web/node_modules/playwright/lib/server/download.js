"use strict";
/**
 * Copyright (c) Microsoft Corporation.
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
exports.Download = void 0;
const path_1 = __importDefault(require("path"));
const page_1 = require("./page");
const utils_1 = require("../utils/utils");
const artifact_1 = require("./artifact");
class Download {
    constructor(page, downloadsPath, uuid, url, suggestedFilename) {
        const unaccessibleErrorMessage = !page._browserContext._options.acceptDownloads ? 'Pass { acceptDownloads: true } when you are creating your browser context.' : undefined;
        this.artifact = new artifact_1.Artifact(page, path_1.default.join(downloadsPath, uuid), unaccessibleErrorMessage);
        this._page = page;
        this.url = url;
        this._suggestedFilename = suggestedFilename;
        page._browserContext._downloads.add(this);
        if (suggestedFilename !== undefined)
            this._page.emit(page_1.Page.Events.Download, this);
    }
    _filenameSuggested(suggestedFilename) {
        utils_1.assert(this._suggestedFilename === undefined);
        this._suggestedFilename = suggestedFilename;
        this._page.emit(page_1.Page.Events.Download, this);
    }
    suggestedFilename() {
        return this._suggestedFilename;
    }
}
exports.Download = Download;
//# sourceMappingURL=download.js.map