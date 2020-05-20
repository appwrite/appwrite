window.ls.router
  .add("/auth/signin", {
    template: "/auth/signin?version=" + APP_ENV.VERSION,
    scope: "home"
  })
  .add("/auth/signup", {
    template: "/auth/signup?version=" + APP_ENV.VERSION,
    scope: "home"
  })
  .add("/auth/recovery", {
    template: "/auth/recovery?version=" + APP_ENV.VERSION,
    scope: "home"
  })
  .add("/auth/recovery/reset", {
    template: "/auth/recovery/reset?version=" + APP_ENV.VERSION,
    scope: "home"
  })
  .add("/auth/confirm", {
    template: "/auth/confirm?version=" + APP_ENV.VERSION,
    scope: "home"
  })
  .add("/auth/join", {
    template: "/auth/join?version=" + APP_ENV.VERSION,
    scope: "home"
  })
  .add("/console", {
    template: "/console?version=" + APP_ENV.VERSION,
    scope: "console"
  })
  .add("/console/account", {
    template: "/console/account?version=" + APP_ENV.VERSION,
    scope: "console"
  })
  .add("/console/account/:tab", {
    template: "/console/account?version=" + APP_ENV.VERSION,
    scope: "console"
  })
  .add("/console/home", {
    template: "/console/home?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/home/:tab", {
    template: "/console/home?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/platforms/:platform", {
    template: function(window) {
      return window.location.pathname + "?version=" + APP_ENV.VERSION;
    },
    scope: "console",
    project: true
  })
  .add("/console/notifications", {
    template: "/console/notifications?version=" + APP_ENV.VERSION,
    scope: "console"
  })
  .add("/console/settings", {
    template: "/console/settings?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/settings/:tab", {
    template: "/console/settings?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/webhooks", {
    template: "/console/webhooks?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/webhooks/:tab", {
    template: "/console/webhooks?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/keys", {
    template: "/console/keys?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/keys/:tab", {
    template: "/console/keys?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/tasks", {
    template: "/console/tasks?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/tasks/:tab", {
    template: "/console/tasks?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/database", {
    template: "/console/database?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/database/collection", {
    template: function(window) {
      return window.location.pathname + window.location.search + '&version=' + APP_ENV.VERSION;
    },
    scope: "console",
    project: true
  })
  .add("/console/database/collection/:tab", {
    template: function(window) {
      return window.location.pathname + window.location.search + '&version=' + APP_ENV.VERSION;
    },
    scope: "console",
    project: true
  })
  .add("/console/database/document", {
    template: function(window) {
      return window.location.pathname + window.location.search + '&version=' + APP_ENV.VERSION;
    },
    scope: "console",
    project: true
  })
  .add("/console/database/document/:tab", {
    template: function(window) {
      return window.location.pathname + window.location.search + '&version=' + APP_ENV.VERSION;
    },
    scope: "console",
    project: true
  })
  .add("/console/storage", {
    template: "/console/storage?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/storage/:tab", {
    template: "/console/storage?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/users", {
    template: "/console/users?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/users/view", {
    template: "/console/users/view?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/users/view/:tab", {
    template: "/console/users/view?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  })
  .add("/console/users/:tab", {
    template: "/console/users?version=" + APP_ENV.VERSION,
    scope: "console",
    project: true
  });
