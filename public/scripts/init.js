// Init

window.ls.error = function () {
  return function (error) {
    window.console.error(error);

    if (window.location.pathname !== '/console') {
      window.location = '/console';
    }
  };
};

window.addEventListener("error", function (event) {
  console.error("ERROR-EVENT:", event.error.message, event.error.stack);
});

document.addEventListener("account.deleteSession", function () {
  window.location = "/auth/signin";
});

document.addEventListener("account.create", function () {
  let container = window.ls.container;
  let form = container.get('serviceForm');
  let sdk = container.get('console');

  let promise = sdk.account.createSession(form.email, form.password);

  container.set("serviceForm", {}, true, true); // Remove sensetive data when not needed

  let subscribe = function (c) {

    if (subscribe) {
      return
    }
  };

  promise.then(function () {
    var subscribe = document.getElementById('newsletter').checked;
    if (subscribe) {
      fetch('https://appwrite.io/v1/newsletter/subscribe', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          name: form.name,
          email: form.email,
        }),
      }).finally(function () {
        window.location = '/console';
      });
    } else {
      window.location = '/console';
    }
  }, function (error) {
    window.location = '/auth/signup?failure=1';
  });
});