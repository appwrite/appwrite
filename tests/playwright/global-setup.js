const {execSync} = require('node:child_process');

module.exports = async () => {
    execSync('composer installer:clean', {stdio: 'inherit'});
};
