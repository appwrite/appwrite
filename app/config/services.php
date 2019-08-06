<?php

return [
    '/' => [
        'name' => 'Homepage',
        'controller' => 'controllers/home.php',
        'sdk' => false,
    ],
    'console/' => [
        'name' => 'Console',
        'controller' => 'controllers/console.php',
        'sdk' => false,
    ],
    'v1/account' => [
        'name' => 'Account',
        'description' => 'The account service allow you to fetch and update information related to the currently logged in user. You can also retrieve a list of all the user sessions across different devices and a security log with the account recent activity.',
        'controller' => 'controllers/account.php',
        'sdk' => true,
    ],
    'v1/auth' => [ //TODO MOVE TO AUTH CONTROLLER SCOPE
        'name' => 'Auth',
        'description' => "The authentication service allows you to verify users accounts using basic email and password login or with a supported OAuth provider. The auth service also exposes methods to confirm users email account and recover users forgotten passwords.\n\nYou can also learn how to [configure support for our supported OAuth providers](/docs/oauth). You can review our currently available OAuth providers from your project console under the **'users'** menu.",
        'controller' => 'controllers/auth.php',
        'sdk' => true,
    ],
    'v1/oauth' => [
        'name' => 'OAuth',
        'controller' => 'controllers/auth.php',
        'sdk' => true,
    ],
    'v1/avatars' => [
        'name' => 'Avatars',
        'description' => 'The avatars service aims to help you complete common and recitative tasks related to your app images, icons and avatars. Using this service we hope to save you some precious time and help you focus on solving your app real challenges.',
        'controller' => 'controllers/avatars.php',
        'sdk' => true,
    ],
    'v1/database' => [
        'name' => 'Database',
        'description' => "The database service allows you to create structured document collections, query and filter lists of documents and manage an advanced set of read and write access.
        \n\nAll the data in the database service is stored in JSON format. The service also allows you to nest child documents and use advanced filters to search and query the database just like you would with a classic graph database.
        \n\nBy leveraging the database permission management you can assign read or write access to the database documents for a specific user, team, user role or even grant public access to all visitors of your project. You can learn more about [how " . APP_NAME . " handles permissions and role access control](/docs/permissions).",
        'controller' => 'controllers/database.php',
        'sdk' => true,
    ],
    'v1/locale' => [
        'name' => 'Locale',
        'controller' => 'controllers/locale.php',
        'sdk' => true,
    ],
    'v1/health' => [
        'name' => 'Health',
        'controller' => 'controllers/health.php',
        'sdk' => false,
    ],
    'v1/projects' => [
        'name' => 'Projects',
        'controller' => 'controllers/projects.php',
        'sdk' => true,
    ],
    'v1/storage' => [
        'name' => 'Storage',
        'description' => "The storage service allows you to manage your project files. You can upload, view, download, and query your files and media.\n\nEach file is granted read and write permissions to manage who has access to view or manage it. You can also learn more about how to manage your [resources permissions](/docs/permissions).\n\n You can also use the storage file preview endpoint to show the app users preview images of your files. The preview endpoint also allows you to manipulate the resulting image, so it will fit perfectly inside your app.",
        'controller' => 'controllers/storage.php',
        'sdk' => true,
    ],
    'v1/teams' => [
        'name' => 'Teams',
        'description' => "The teams' service allows you to group together users of your project and allow them to share read and write access to your project resources, such as, database documents or storage files.\n\nEach user who creates a team becomes the team owner and can delegate the ownership role by inviting a new team member. Only team owners can invite new users to the team.",
        'controller' => 'controllers/teams.php',
        'sdk' => true,
    ],
    'v1/users' => [
        'name' => 'Users',
        'controller' => 'controllers/users.php',
        'sdk' => true,
    ],
];