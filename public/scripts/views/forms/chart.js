(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-chart",
    controller: function(element, container, date, document) {
      let child = document.createElement("canvas");
      let sources = element.getAttribute('data-forms-chart');
      let colors = ['#29b5d9' /* blue */, '#4eb55b' /* green */, '#fba233', /* orange */,];

      child.width = 500;
      child.height = 175;

      let config = {
        type: "line",
        data: {
          labels: [],
          datasets: []
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

      sources = sources.split(',');

      for (let i = 0; i < sources.length; i++) {
        let label = sources[i].substring(0, sources[i].indexOf('='));
        let path = sources[i].substring(sources[i].indexOf('=') + 1);
        let data = container.path(path);

        config.data.labels[i] = label;
        config.data.datasets[i] = {};
        config.data.datasets[i].label = label;
        config.data.datasets[i].borderColor = colors[i];
        config.data.datasets[i].backgroundColor = colors[i] + '36';
        config.data.datasets[i].borderWidth = 2;
        config.data.datasets[i].data = [0, 0, 0, 0, 0, 0, 0];
        config.data.datasets[i].fill = true;

        if(!data) {
          return;
        }
        
        for (let x = 0; x < data.length; x++) {
          config.data.datasets[i].data[x] = data[x].value;
          config.data.labels[x] = date.format("d F Y", data[x].date);
        }
      }

      element.innerHTML = "";

      element.appendChild(child);

      container.set("chart", new Chart(child.getContext("2d"), config), true);

      element.dataset["canvas"] = true;
    }
  });
})(window);
