"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.validateInstall = void 0;
/* eslint-disable no-console */
const is_installed_globally_1 = __importDefault(require("is-installed-globally"));
async function validateInstall() {
    if (is_installed_globally_1.default)
        console.warn(`
      It looks like you're running Cucumber from a global installation.
      If so, you'll likely see issues - you need to have Cucumber installed as a local dependency in your project.
      See https://github.com/cucumber/cucumber-js/blob/main/docs/installation.md#invalid-installations
      `);
}
exports.validateInstall = validateInstall;
//# sourceMappingURL=install_validator.js.map