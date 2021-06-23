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
exports.WKProvisionalPage = void 0;
const helper_1 = require("../helper");
const utils_1 = require("../../utils/utils");
class WKProvisionalPage {
    constructor(session, page) {
        this._sessionListeners = [];
        this._mainFrameId = null;
        this._session = session;
        this._wkPage = page;
        const overrideFrameId = (handler) => {
            return (payload) => {
                // Pretend that the events happened in the same process.
                if (payload.frameId)
                    payload.frameId = this._wkPage._page._frameManager.mainFrame()._id;
                handler(payload);
            };
        };
        const wkPage = this._wkPage;
        this._sessionListeners = [
            helper_1.helper.addEventListener(session, 'Network.requestWillBeSent', overrideFrameId(e => wkPage._onRequestWillBeSent(session, e))),
            helper_1.helper.addEventListener(session, 'Network.requestIntercepted', overrideFrameId(e => wkPage._onRequestIntercepted(e))),
            helper_1.helper.addEventListener(session, 'Network.responseReceived', overrideFrameId(e => wkPage._onResponseReceived(e))),
            helper_1.helper.addEventListener(session, 'Network.loadingFinished', overrideFrameId(e => wkPage._onLoadingFinished(e))),
            helper_1.helper.addEventListener(session, 'Network.loadingFailed', overrideFrameId(e => wkPage._onLoadingFailed(e))),
        ];
        this.initializationPromise = this._wkPage._initializeSession(session, true, ({ frameTree }) => this._handleFrameTree(frameTree));
    }
    dispose() {
        helper_1.helper.removeEventListeners(this._sessionListeners);
    }
    commit() {
        utils_1.assert(this._mainFrameId);
        this._wkPage._onFrameAttached(this._mainFrameId, null);
    }
    _handleFrameTree(frameTree) {
        utils_1.assert(!frameTree.frame.parentId);
        this._mainFrameId = frameTree.frame.id;
    }
}
exports.WKProvisionalPage = WKProvisionalPage;
//# sourceMappingURL=wkProvisionalPage.js.map