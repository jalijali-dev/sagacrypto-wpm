/* WPM Crypto Portal — front-end interactions (lightweight, no dependencies) */
(function () {
  var toggle = document.getElementById("crypto-nav-toggle");
  var mobile = document.getElementById("crypto-nav-mobile");
  var closeBtn = document.getElementById("crypto-nav-mobile-close");

  function openMobile() {
    if (mobile) { mobile.classList.add("is-open"); }
  }
  function closeMobile() {
    if (mobile) { mobile.classList.remove("is-open"); }
  }

  if (toggle) { toggle.addEventListener("click", openMobile); }
  if (closeBtn) { closeBtn.addEventListener("click", closeMobile); }
  if (mobile) {
    mobile.addEventListener("click", function (e) {
      if (e.target === mobile) { closeMobile(); }
    });
    mobile.querySelectorAll("a").forEach(function (a) {
      a.addEventListener("click", closeMobile);
    });
  }

  window.addEventListener("keydown", function (e) {
    if (e.key === "Escape") { closeMobile(); }
  });

  /* Highlight the current section's nav link while scrolling */
  var sections = Array.prototype.slice.call(document.querySelectorAll("main [id]"));
  var navLinks = Array.prototype.slice.call(document.querySelectorAll(".crypto-nav__menu a, .crypto-nav__mobile-panel a"));

  function setActive(id) {
    navLinks.forEach(function (a) {
      var match = a.getAttribute("href") === "#" + id;
      a.classList.toggle("is-active", match);
    });
  }

  if (sections.length && "IntersectionObserver" in window) {
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) { setActive(entry.target.id); }
        });
      },
      { rootMargin: "-45% 0px -50% 0px", threshold: 0 }
    );
    sections.forEach(function (s) { observer.observe(s); });
  }
}());
