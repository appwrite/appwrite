"use strict";
/**
 * Copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the 'License");
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
const dispatcher_1 = require("./dispatchers/dispatcher");
const playwright_1 = require("./server/playwright");
const playwrightDispatcher_1 = require("./dispatchers/playwrightDispatcher");
const connection_1 = require("./client/connection");
const browserServerImpl_1 = require("./browserServerImpl");
function setupInProcess() {
    const playwright = playwright_1.createPlaywright();
    const clientConnection = new connection_1.Connection();
    const dispatcherConnection = new dispatcher_1.DispatcherConnection();
    // Dispatch synchronously at first.
    dispatcherConnection.onmessage = message => clientConnection.dispatch(message);
    clientConnection.onmessage = message => dispatcherConnection.dispatch(message);
    // Initialize Playwright channel.
    new playwrightDispatcher_1.PlaywrightDispatcher(dispatcherConnection.rootDispatcher(), playwright);
    const playwrightAPI = clientConnection.getObjectWithKnownName('Playwright');
    playwrightAPI.chromium._serverLauncher = new browserServerImpl_1.BrowserServerLauncherImpl('chromium');
    playwrightAPI.firefox._serverLauncher = new browserServerImpl_1.BrowserServerLauncherImpl('firefox');
    playwrightAPI.webkit._serverLauncher = new browserServerImpl_1.BrowserServerLauncherImpl('webkit');
    // Switch to async dispatch after we got Playwright object.
    dispatcherConnection.onmessage = message => setImmediate(() => clientConnection.dispatch(message));
    clientConnection.onmessage = message => setImmediate(() => dispatcherConnection.dispatch(message));
    playwrightAPI._toImpl = (x) => dispatcherConnection._dispatchers.get(x._guid)._object;
    return playwrightAPI;
}
module.exports = setupInProcess();
//# sourceMappingURL=inprocess.js.map