"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.initializePlugins = void 0;
const plugin_1 = require("../plugin");
const publish_1 = __importDefault(require("../publish"));
const INTERNAL_PLUGINS = {
    publish: publish_1.default,
};
async function initializePlugins(logger, configuration, environment) {
    // eventually we'll load plugin packages here
    const pluginManager = new plugin_1.PluginManager(Object.values(INTERNAL_PLUGINS));
    await pluginManager.init(logger, configuration, environment);
    return pluginManager;
}
exports.initializePlugins = initializePlugins;
//# sourceMappingURL=plugins.js.map