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
exports.Playwright = exports.CDPSession = exports.Worker = exports.Video = exports.Tracing = exports.Selectors = exports.Page = exports.WebSocket = exports.Route = exports.Response = exports.Request = exports.JSHandle = exports.Touchscreen = exports.Mouse = exports.Keyboard = exports.Frame = exports.TimeoutError = exports.FileChooser = exports.ElementHandle = exports.ElectronApplication = exports.Electron = exports.Download = exports.Dialog = exports.Coverage = exports.ConsoleMessage = exports.BrowserType = exports.BrowserContext = exports.Browser = exports.AndroidSocket = exports.AndroidInput = exports.AndroidWebView = exports.AndroidDevice = exports.Android = exports.Accessibility = void 0;
var accessibility_1 = require("./accessibility");
Object.defineProperty(exports, "Accessibility", { enumerable: true, get: function () { return accessibility_1.Accessibility; } });
var android_1 = require("./android");
Object.defineProperty(exports, "Android", { enumerable: true, get: function () { return android_1.Android; } });
Object.defineProperty(exports, "AndroidDevice", { enumerable: true, get: function () { return android_1.AndroidDevice; } });
Object.defineProperty(exports, "AndroidWebView", { enumerable: true, get: function () { return android_1.AndroidWebView; } });
Object.defineProperty(exports, "AndroidInput", { enumerable: true, get: function () { return android_1.AndroidInput; } });
Object.defineProperty(exports, "AndroidSocket", { enumerable: true, get: function () { return android_1.AndroidSocket; } });
var browser_1 = require("./browser");
Object.defineProperty(exports, "Browser", { enumerable: true, get: function () { return browser_1.Browser; } });
var browserContext_1 = require("./browserContext");
Object.defineProperty(exports, "BrowserContext", { enumerable: true, get: function () { return browserContext_1.BrowserContext; } });
var browserType_1 = require("./browserType");
Object.defineProperty(exports, "BrowserType", { enumerable: true, get: function () { return browserType_1.BrowserType; } });
var consoleMessage_1 = require("./consoleMessage");
Object.defineProperty(exports, "ConsoleMessage", { enumerable: true, get: function () { return consoleMessage_1.ConsoleMessage; } });
var coverage_1 = require("./coverage");
Object.defineProperty(exports, "Coverage", { enumerable: true, get: function () { return coverage_1.Coverage; } });
var dialog_1 = require("./dialog");
Object.defineProperty(exports, "Dialog", { enumerable: true, get: function () { return dialog_1.Dialog; } });
var download_1 = require("./download");
Object.defineProperty(exports, "Download", { enumerable: true, get: function () { return download_1.Download; } });
var electron_1 = require("./electron");
Object.defineProperty(exports, "Electron", { enumerable: true, get: function () { return electron_1.Electron; } });
Object.defineProperty(exports, "ElectronApplication", { enumerable: true, get: function () { return electron_1.ElectronApplication; } });
var elementHandle_1 = require("./elementHandle");
Object.defineProperty(exports, "ElementHandle", { enumerable: true, get: function () { return elementHandle_1.ElementHandle; } });
var fileChooser_1 = require("./fileChooser");
Object.defineProperty(exports, "FileChooser", { enumerable: true, get: function () { return fileChooser_1.FileChooser; } });
var errors_1 = require("../utils/errors");
Object.defineProperty(exports, "TimeoutError", { enumerable: true, get: function () { return errors_1.TimeoutError; } });
var frame_1 = require("./frame");
Object.defineProperty(exports, "Frame", { enumerable: true, get: function () { return frame_1.Frame; } });
var input_1 = require("./input");
Object.defineProperty(exports, "Keyboard", { enumerable: true, get: function () { return input_1.Keyboard; } });
Object.defineProperty(exports, "Mouse", { enumerable: true, get: function () { return input_1.Mouse; } });
Object.defineProperty(exports, "Touchscreen", { enumerable: true, get: function () { return input_1.Touchscreen; } });
var jsHandle_1 = require("./jsHandle");
Object.defineProperty(exports, "JSHandle", { enumerable: true, get: function () { return jsHandle_1.JSHandle; } });
var network_1 = require("./network");
Object.defineProperty(exports, "Request", { enumerable: true, get: function () { return network_1.Request; } });
Object.defineProperty(exports, "Response", { enumerable: true, get: function () { return network_1.Response; } });
Object.defineProperty(exports, "Route", { enumerable: true, get: function () { return network_1.Route; } });
Object.defineProperty(exports, "WebSocket", { enumerable: true, get: function () { return network_1.WebSocket; } });
var page_1 = require("./page");
Object.defineProperty(exports, "Page", { enumerable: true, get: function () { return page_1.Page; } });
var selectors_1 = require("./selectors");
Object.defineProperty(exports, "Selectors", { enumerable: true, get: function () { return selectors_1.Selectors; } });
var tracing_1 = require("./tracing");
Object.defineProperty(exports, "Tracing", { enumerable: true, get: function () { return tracing_1.Tracing; } });
var video_1 = require("./video");
Object.defineProperty(exports, "Video", { enumerable: true, get: function () { return video_1.Video; } });
var worker_1 = require("./worker");
Object.defineProperty(exports, "Worker", { enumerable: true, get: function () { return worker_1.Worker; } });
var cdpSession_1 = require("./cdpSession");
Object.defineProperty(exports, "CDPSession", { enumerable: true, get: function () { return cdpSession_1.CDPSession; } });
var playwright_1 = require("./playwright");
Object.defineProperty(exports, "Playwright", { enumerable: true, get: function () { return playwright_1.Playwright; } });
//# sourceMappingURL=api.js.map