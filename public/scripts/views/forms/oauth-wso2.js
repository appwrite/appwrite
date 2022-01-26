(function (window) {
    "use strict";
  
    window.ls.container.get("view").add({
      selector: "data-forms-oauth-wso2",
      controller: function (element) {
        let container = document.createElement("div");
        let row = document.createElement("div");
        let col1 = document.createElement("div");
        let col2 = document.createElement("div");
        let col3 = document.createElement("div");
        let col4 = document.createElement("div");
        let col5 = document.createElement("div");
        let clientSecret = document.createElement("input");
        let clientSecretLabel = document.createElement("label");
        let clientUrl = document.createElement("input");
        let clientUrlLabel = document.createElement("label");
        let clientAuthorizeEndPoint = document.createElement("input");
        let clientAuthorizeEndPointLabel = document.createElement("label");
        let clientTokenEndPoint = document.createElement("input");
        let clientTokenEndPointLabel = document.createElement("label");
        let clientMeEndPoint = document.createElement("input");
        let clientMeEndPointLabel = document.createElement("label");
  
        clientSecretLabel.textContent = "Client Secret";
        clientUrlLabel.textContent = "Base Url";
        clientAuthorizeEndPointLabel.textContent = "Authorize EndPoint";
        clientTokenEndPointLabel.textContent = "Token EndPoint";
        clientMeEndPointLabel.textContent = "Get User EndPoint";
  
        // row.classList.add("row");
        // row.classList.add("thin");
        container.appendChild(row);
  
        row.appendChild(col1);
        row.appendChild(col2);
        row.appendChild(col3);
        row.appendChild(col4);
        row.appendChild(col5);
  
        col1.classList.add("col");
        col1.classList.add("span-6");
        col1.appendChild(clientSecretLabel);
        col1.appendChild(clientSecret);
  
        col2.classList.add("col");
        col2.classList.add("span-6");
        col2.appendChild(clientUrlLabel);
        col2.appendChild(clientUrl);
  
        col3.classList.add("col");
        col3.classList.add("span-6");
        col3.appendChild(clientAuthorizeEndPointLabel);
        col3.appendChild(clientAuthorizeEndPoint);
  
        col4.classList.add("col");
        col4.classList.add("span-6");
        col4.appendChild(clientTokenEndPointLabel);
        col4.appendChild(clientTokenEndPoint);
  
        col5.classList.add("col");
        col5.classList.add("span-6");
        col5.appendChild(clientMeEndPointLabel);
        col5.appendChild(clientMeEndPoint);
  
        clientSecret.type = "text";
        clientSecret.placeholder = "SHAB13ROFN";
  
        clientUrl.type = "text";
        clientUrl.placeholder = "http://is.wso2.com.br";
  
        clientAuthorizeEndPoint.type = "text";
        clientAuthorizeEndPoint.placeholder = "auth/authorize";
  
        clientTokenEndPoint.type = "text";
        clientTokenEndPoint.placeholder = "auth/token";
        
        clientMeEndPoint.type = "text";
        clientMeEndPoint.placeholder = "users/me";
  
  
        element.parentNode.insertBefore(container, element.nextSibling);
  
        element.addEventListener("change", sync);
        clientSecret.addEventListener("change", update);
        clientUrl.addEventListener("change", update);
        clientAuthorizeEndPoint.addEventListener("change", update);
        clientTokenEndPoint.addEventListener("change", update);
        clientMeEndPoint.addEventListener("change", update);
      
        function update() {
          let json = {};
  
          json.clientSecret = clientSecret.value;
          json.clientUrl = clientUrl.value;
          json.clientAuthorizeEndPoint = clientAuthorizeEndPoint.value;
          json.clientTokenEndPoint = clientTokenEndPoint.value;
          json.clientMeEndPoint = clientMeEndPoint.value;
  
  
          element.value = JSON.stringify(json);
        }
  
        function sync() {
          if (!element.value) {
            return;
          }
  
          let json = {};
  
          try {
            json = JSON.parse(element.value);
          } catch (error) {
            console.error("Failed to parse secret key");
          }
  
          clientUrl.value = json.clientUrl || "";
          clientSecret.value = json.clientSecret || "";
          clientAuthorizeEndPoint.value = json.clientAuthorizeEndPoint || "";
          clientTokenEndPoint.value = json.clientTokenEndPoint || "";
          clientMeEndPoint.value = json.clientMeEndPoint || "";
        }
        sync();
      },
    });
  })(window);
  