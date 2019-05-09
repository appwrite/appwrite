window.ls.router
    .add('/auth/signin', {
        template: '/auth/signin',
        scope: 'home'
    })
    .add('/auth/signup', {
        template: '/auth/signup',
        scope: 'home'
    })
    .add('/auth/recovery', {
        template: '/auth/recovery',
        scope: 'home'
    })
    .add('/auth/recovery/reset', {
        template: '/auth/recovery/reset',
        scope: 'home'
    })
    .add('/auth/confirm', {
        template: '/auth/confirm',
        scope: 'home'
    })
    .add('/auth/join', {
        template: '/auth/join',
        scope: 'home'
    })
    .add('/console', {
        template: '/console',
        scope: 'console'
    })
    .add('/console/account', {
        template: '/console/account',
        scope: 'console'
    })
    .add('/console/account/:tab', {
        template: '/console/account',
        scope: 'console'
    })
    .add('/console/home', {
        template: '/console/home',
        scope: 'console',
        project: true
    })
    .add('/console/home/:tab', {
        template: '/console/home',
        scope: 'console',
        project: true
    })
    .add('/console/platforms/:platform', {
        template: function (window) {
            return window.location.pathname;
        },
        scope: 'console',
        project: true
    })
    .add('/console/notifications', {
        template: '/console/notifications',
        scope: 'console'
    })
    .add('/console/settings', {
        template: '/console/settings',
        scope: 'console',
        project: true
    })
    .add('/console/settings/:tab', {
        template: '/console/settings',
        scope: 'console',
        project: true
    })
    .add('/console/database', {
        template: '/console/database',
        scope: 'console',
        project: true
    })
    .add('/console/database/:tab', {
        template: '/console/database',
        scope: 'console',
        project: true
    })
    .add('/console/storage', {
        template: '/console/storage',
        scope: 'console',
        project: true
    })
    .add('/console/storage/:tab', {
        template: '/console/storage',
        scope: 'console',
        project: true
    })
    .add('/console/users', {
        template: '/console/users',
        scope: 'console',
        project: true
    })
    .add('/console/users/view', {
        template: '/console/users/view',
        scope: 'console',
        project: true
    })
    .add('/console/users/view/:tab', {
        template: '/console/users/view',
        scope: 'console',
        project: true
    })
    .add('/console/users/:tab', {
        template: '/console/users',
        scope: 'console',
        project: true
    })
;
