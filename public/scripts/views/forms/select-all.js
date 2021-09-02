(function(window) {
    "use strict";
  
    window.ls.container.get("view").add({
      selector: "data-forms-select-all",
      controller: function(element) {
        let select = document.createElement("button");
        let unselect = document.createElement("button");
  
        select.textContent = element.dataset.formsSelectAllText || 'Select All';
        unselect.textContent = element.dataset.formsUnselectAllText || 'Unselect All';
  
        select.classList.add('link');
        select.classList.add('margin-top-tiny');
        select.classList.add('margin-start-small');
        select.classList.add('text-size-small');
        select.classList.add('pull-end');
        unselect.classList.add('link');
        unselect.classList.add('margin-top-tiny');
        unselect.classList.add('margin-start-small');
        unselect.classList.add('text-size-small');
        unselect.classList.add('pull-end');
  
        // select.disabled = true;
        // unselect.disabled = true;
  
        select.type = 'button';
        unselect.type = 'button';
  
        element.parentNode.insertBefore(select, element);
        element.parentNode.insertBefore(unselect, element);
  
        select.addEventListener('click', function () {
          let checkboxes = element.querySelectorAll("input[type='checkbox']");
  
          for(var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = true;
            checkboxes[i].dispatchEvent(new Event('change'));
          }
        })
  
        unselect.addEventListener('click', function () {
          let checkboxes = element.querySelectorAll("input[type='checkbox']");
  
          for(var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = false;
            checkboxes[i].dispatchEvent(new Event('change'));
          }
        })
  
      }
    });
  })(window);
