const Client = require('./lib/client.js');
const Avatars = require('./lib/services/avatars.js');
const Database = require('./lib/services/database.js');
const Functions = require('./lib/services/functions.js');
const Health = require('./lib/services/health.js');
const Locale = require('./lib/services/locale.js');
const Storage = require('./lib/services/storage.js');
const Teams = require('./lib/services/teams.js');
const Users = require('./lib/services/users.js');

module.exports = {
    Client,
    Avatars,
    Database,
    Functions,
    Health,
    Locale,
    Storage,
    Teams,
    Users,
};