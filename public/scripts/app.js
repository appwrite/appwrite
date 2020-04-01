// Views

window.ls.container
  .get("view")
  .add({
    selector: "data-acl",
    controller: function(element, document, router, alerts) {
      document.body.classList.remove("console");
      document.body.classList.remove("home");

      document.body.classList.add(router.getCurrent().view.scope);

      if (!router.getCurrent().view.project) {
        document.body.classList.add("hide-nav");
        document.body.classList.remove("show-nav");
      } else {
        document.body.classList.add("show-nav");
        document.body.classList.remove("hide-nav");
      }

      // Special case for console index page

      if ("/console" === router.getCurrent().path) {
        document.body.classList.add("index");
      } else {
        document.body.classList.remove("index");
      }
    }
  })
  .add({
    selector: "data-forms-headers",
    controller: function(element) {
      let key = document.createElement("input");
      let value = document.createElement("input");
      let wrap = document.createElement("div");
      let cell1 = document.createElement("div");
      let cell2 = document.createElement("div");

      key.type = "text";
      key.className = "margin-bottom-no";
      key.placeholder = "Key";
      value.type = "text";
      value.className = "margin-bottom-no";
      value.placeholder = "Value";

      wrap.className = "row thin margin-bottom-small";
      cell1.className = "col span-6";
      cell2.className = "col span-6";

      element.parentNode.insertBefore(wrap, element);
      cell1.appendChild(key);
      cell2.appendChild(value);
      wrap.appendChild(cell1);
      wrap.appendChild(cell2);

      key.addEventListener("input", function() {
        syncA();
      });

      value.addEventListener("input", function() {
        syncA();
      });

      element.addEventListener("change", function() {
        syncB();
      });

      let syncA = function() {
        element.value =
          key.value.toLowerCase() + ":" + value.value.toLowerCase();
      };

      let syncB = function() {
        let split = element.value.toLowerCase().split(":");
        key.value = split[0] || "";
        value.value = split[1] || "";

        key.value = key.value.trim();
        value.value = value.value.trim();
      };

      syncB();
    }
  })
  .add({
    selector: "data-prism",
    controller: function(window, document, element, alerts) {
      Prism.highlightElement(element);

      let copy = document.createElement("i");

      copy.className = "icon-docs copy";
      copy.title = "Copy to Clipboard";
      copy.textContent = "Click Here to Copy";

      copy.addEventListener("click", function() {
        window.getSelection().removeAllRanges();

        let range = document.createRange();

        range.selectNode(element);

        window.getSelection().addRange(range);

        try {
          document.execCommand("copy");
          alerts.add({ text: "Copied to clipboard", class: "" }, 3000);
        } catch (err) {
          alerts.add({ text: "Failed to copy text ", class: "error" }, 3000);
        }

        window.getSelection().removeAllRanges();
      });

      element.parentNode.parentNode.appendChild(copy);
    }
  })
  .add({
    selector: "data-ls-ui-chart",
    controller: function(element, container, date, document) {
      let child = document.createElement("canvas");

      child.width = 500;
      child.height = 175;

      let stats = container.get("usage");

      if (!stats || !stats["requests"] || !stats["requests"]["data"]) {
        return;
      }

      let config = {
        type: "line",
        data: {
          labels: [],
          datasets: [
            {
              label: "Requests",
              backgroundColor: "rgba(230, 248, 253, 0.3)",
              borderColor: "#29b5d9",
              borderWidth: 2,
              data: [0, 0, 0, 0, 0, 0, 0],
              fill: true
            }
          ]
        },
        options: {
          responsive: true,
          title: {
            display: false,
            text: "Stats"
          },
          legend: {
            display: false
          },
          tooltips: {
            mode: "index",
            intersect: false,
            caretPadding: 0
          },
          hover: {
            mode: "nearest",
            intersect: true
          },
          scales: {
            xAxes: [
              {
                display: false
              }
            ],
            yAxes: [
              {
                display: false
              }
            ]
          }
        }
      };

      for (let i = 0; i < stats["requests"]["data"].length; i++) {
        config.data.datasets[0].data[i] = stats["requests"]["data"][i].value;
        config.data.labels[i] = date.format(
          "d F Y",
          stats["requests"]["data"][i].date
        );
      }

      element.innerHTML = "";

      element.appendChild(child);

      container.set("chart", new Chart(child.getContext("2d"), config), true);

      element.dataset["canvas"] = true;
    }
  });
