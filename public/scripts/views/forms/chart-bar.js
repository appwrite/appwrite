(function (window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-chart-bars",
    controller: (element) => {
      let observer = null;
      let populateChart = () => {
        let history = element.dataset?.history;
        if (history == 0) {
          history = new Array(12).fill({
            percentage: 0,
            value: 0
          });
        } else {
          history = JSON.parse(history);
        }
        element.innerHTML = '';
        history.forEach(({ percentage, value }, index) => {
          const seconds = 50- (index * 5)
          const bar = document.createElement('span');
          bar.classList.add('bar');
          bar.classList.add(`bar-${percentage}`);
          bar.classList.add('tooltip');
          bar.classList.add('down');
          bar.setAttribute('data-tooltip', `${value} (${seconds} seconds ago)`);
          element.appendChild(bar);
        })
      }
      if (observer) {
        observer.disconnect();
      } else {
        observer = new MutationObserver(populateChart);
        observer.observe(element, {
          attributes: true,
          attributeFilter: ['data-history']
        });
      }
      populateChart();
    }
  });
})(window);
