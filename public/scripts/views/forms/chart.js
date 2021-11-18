(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-chart",
    controller: function(element, container, date, document) {
      let wrapper = document.createElement("div");
      let child = document.createElement("canvas");
      let sources = element.getAttribute('data-forms-chart');
      let width = element.getAttribute('data-width') || 500;
      let height = element.getAttribute('data-height') || 175;
      let showXAxis = element.getAttribute('data-show-x-axis') || false;
      let showYAxis = element.getAttribute('data-show-y-axis') || false;
      let colors = (element.getAttribute('data-colors') || 'blue,green,orange,red').split(',');
      let themes = {'blue': '#29b5d9', 'green': '#4eb55b', 'orange': '#fba233', 'red': '#dc3232', 'create': '#00b680', 'read': '#009cde', 'update': '#696fd7', 'delete': '#da5d95',};
      let range = {'24h': 'H:i', '7d': 'd F Y', '30d': 'd F Y', '90d': 'd F Y'}

      element.parentNode.insertBefore(wrapper, element.nextSibling);

      wrapper.classList.add('content');
      
      child.width = width;
      child.height = height;

      sources = sources.split(',');

      wrapper.appendChild(child);

      let chart = null;

      let check = function() {

        let config = {
          type: "line",
          data: {
            labels: [],
            datasets: []
          },
          options: {
            responsive: true,
            tooltip: {
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
                  display: showXAxis
                }
              ],
              yAxes: [
                {
                  display: showYAxis,
                  ticks: {
                    fontColor: "#8f8f8f"
                  }
                }
              ]
            }
          }
        };

        for (let i = 0; i < sources.length; i++) {
          let label = sources[i].substring(0, sources[i].indexOf('='));
          let path = sources[i].substring(sources[i].indexOf('=') + 1);
          let usage = container.get('usage');
          let data = usage[path];
          let value = JSON.parse(element.value);

          config.data.labels[i] = label;
          config.data.datasets[i] = {};
          config.data.datasets[i].label = label;
          config.data.datasets[i].borderColor = themes[colors[i]];
          config.data.datasets[i].backgroundColor = themes[colors[i]] + '36';
          config.data.datasets[i].borderWidth = 2;
          config.data.datasets[i].data = [0, 0, 0, 0, 0, 0, 0];
          config.data.datasets[i].fill = true;

          if(!data) {
            return;
          }

          let dateFormat = (value.range && range[value.range]) ? range[value.range] : 'd F Y';
          
          for (let x = 0; x < data.length; x++) {
            config.data.datasets[i].data[x] = data[x].value;
            config.data.labels[x] = date.format(dateFormat, data[x].date);
          }
        }
        
        if(chart) {
          chart.destroy();
        }
        else {
        }
        chart = new Chart(child.getContext("2d"), config);
        wrapper.dataset["canvas"] = true;
      }

      check();

      element.addEventListener('change', check);
    }
  });
})(window);