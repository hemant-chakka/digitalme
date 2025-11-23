document.addEventListener("DOMContentLoaded", function () {
  let acFormInterval = setInterval(function () {
    let acForm = document.querySelector("#ac-form form");
    if (acForm) {
      clearInterval(acFormInterval);
      acForm.addEventListener("submit", function () {
        const acEmail = acForm.querySelector('input[name="email"]');
        if (acEmail && acEmail.value) {
          document.cookie = "sacd_email=" + btoa(acEmail.value) + "; path=/; max-age=604800";
        }
      });
    }
  }, 300);
});
