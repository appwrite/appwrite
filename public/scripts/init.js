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

  container.set("serviceForm", {}, true, true); // Remove sensitive data when not needed

  promise.then(function () {
    var subscribe = document.getElementById('newsletter').checked;
    if (subscribe) {
      let alerts = container.get('alerts');
      let loaderId = alerts.add({ text: 'Loading...', class: "" }, 0);
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
        alerts.remove(loaderId);
        window.location = '/console';
      });
    } else {
      window.location = '/console';
    }
  }, function (error) {
    window.location = '/auth/signup?failure=1';
  });
});
window.addEventListener("load", async () => {
  const bars = 12;
  const realtime = window.ls.container.get('realtime');
  const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
  let current = {};
  window.ls.container.get('console').subscribe(['project', 'console'], response => {
    switch (response.event) {
      case 'stats.connections':
        for (let project in response.payload) {
          current[project] = response.payload[project] ?? 0;
        }
        break;
      case 'database.attributes.create':
      case 'database.attributes.update':
      case 'database.attributes.delete':
        document.dispatchEvent(new CustomEvent('database.createAttribute'));

        break;
      case 'database.indexes.create':
      case 'database.indexes.update':
      case 'database.indexes.delete':
        document.dispatchEvent(new CustomEvent('database.createIndex'));

        break;
    }

  });

  while (true) {
    let newHistory = {};
    let createdHistory = false;
    for (const project in current) {
      let history = realtime?.history ?? {};

      if (!(project in history)) {
        history[project] = new Array(bars).fill({
          percentage: 0,
          value: 0
        });
      }

      history = history[project];
      history.push({
        percentage: 0,
        value: current[project]
      });
      if (history.length >= bars) {
        history.shift();
      }

      const highest = history.reduce((prev, curr) => {
        return (curr.value > prev) ? curr.value : prev;
      }, 0);

      history = history.map(({ percentage, value }) => {
        createdHistory = true;
        percentage = value === 0 ? 0 : ((Math.round((value / highest) * 10) / 10) * 100);
        if (percentage > 100) percentage = 100;
        else if (percentage == 0 && value != 0) percentage = 5;

        return {
          percentage: percentage,
          value: value
        };
      })
      newHistory[project] = history;
    }

    let currentSnapshot = { ...current };
    for (let index = .1; index <= 1; index += .05) {
      let currentTransition = { ...currentSnapshot };
      for (const project in current) {
        if (project in newHistory) {
          let base = newHistory[project][bars - 2].value;
          let cur = currentSnapshot[project];
          let offset = (cur - base) * index;
          currentTransition[project] = base + Math.floor(offset);
        }
      }

      realtime.setCurrent(currentTransition);
      await sleep(250);
    }

    realtime.setHistory(newHistory);
  }
});

window.formValidation = (form, fields) => {
  const elements = Array.from(form.querySelectorAll('[name]')).reduce((prev, curr) => {
    if(!curr.name) {
      return prev;
    }
    prev[curr.name] = curr;
    return prev;
  }, {});
  const actionHandler = (action, attribute) => {
      switch (action) {
          case "disable":
              elements[attribute].setAttribute("disabled", true);
              elements[attribute].dispatchEvent(new Event('change'));
              break;
          case "enable":
              elements[attribute].removeAttribute("disabled");
              elements[attribute].dispatchEvent(new Event('change'));
              break;
          case "unvalue":
              elements[attribute].value = "";
              break;
          case "check":
            elements[attribute].value = "true";
            break;
          case "uncheck":
            elements[attribute].value = "false";
            break;
      }
  };
  for (const field in fields) {
      for (const attribute in fields[field]) {
          const attr = fields[field][attribute];
          if (Array.isArray(attr)) {
              attr.forEach(action => {
                  if (elements[field].value === "true") {
                      actionHandler(action, attribute);
                  }
              })
          } else {
              const condition = attr.if.some(c => {
                  return elements[c].value === "true";
              });
              if (condition) {
                  for (const thenAction in attr.then) {
                      attr.then[thenAction].forEach(action => {
                          actionHandler(action, thenAction);
                      });
                  }
              } else {
                  for (const elseAction in attr.else) {
                      attr.else[elseAction].forEach(action => {
                          actionHandler(action, elseAction);
                      });
                  }
              }
          }
      }
  }
  form.addEventListener("reset", () => {
    for (const key in fields) {
        if (Object.hasOwnProperty.call(fields, key)) {
            const element = elements[key];
            element.setAttribute("value", "");
            element.removeAttribute("disabled");
            element.dispatchEvent(new Event("change"));
        }
    }
});
};
