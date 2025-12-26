document.addEventListener("DOMContentLoaded", () => {
  const email = document.getElementById("email");
  const password = document.getElementById("password");
  const button = document.getElementById("loginButton");
  function validate() {
    if (email.value.trim() !== "" && password.value.trim() !== "") {
      button.disabled = false;
    } else {
      button.disabled = true;
    }
  }
  email.addEventListener("input", validate);
  password.addEventListener("input", validate);
});