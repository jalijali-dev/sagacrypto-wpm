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

  /* Highlight the current section's nav link while scrolling — homepage only
     (other pages set the active nav link server-side via wpm_nav_menu()). */
  if (document.getElementById("beranda")) {
    var sections = Array.prototype.slice.call(document.querySelectorAll("main [id]"));
    var navLinks = Array.prototype.slice.call(document.querySelectorAll(".crypto-nav__menu a, .crypto-nav__mobile-panel a"));

    var setActive = function (id) {
      navLinks.forEach(function (a) {
        var match = a.getAttribute("href") === "#" + id;
        if (match) { a.classList.add("is-active"); }
      });
    };

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
  }

  /* Popup / sticky-bottom ad dismiss buttons */
  var popupAd = document.getElementById("wpm-popup-ad");
  var popupClose = document.getElementById("wpm-popup-ad-close");
  if (popupAd && popupClose) {
    popupClose.addEventListener("click", function () { popupAd.classList.add("is-hidden"); });
    popupAd.addEventListener("click", function (e) {
      if (e.target === popupAd) { popupAd.classList.add("is-hidden"); }
    });
  }
  var stickyAd = document.getElementById("wpm-sticky-ad");
  var stickyClose = document.getElementById("wpm-sticky-ad-close");
  if (stickyAd && stickyClose) {
    stickyClose.addEventListener("click", function () { stickyAd.classList.add("is-hidden"); });
  }

  /* Article page — "copy link" share button */
  var copyBtn = document.getElementById("wpm-copy-link");
  if (copyBtn) {
    copyBtn.addEventListener("click", function () {
      var url = window.location.href;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function () {
          copyBtn.setAttribute("title", "Link disalin!");
        });
      }
    });
  }

  /* Crypto page — auto-refresh countdown (reads data-refresh attribute,
     seconds, set by the page from the provider's refresh_interval). */
  var autoRefresh = document.querySelector("[data-auto-refresh]");
  if (autoRefresh) {
    var seconds = parseInt(autoRefresh.getAttribute("data-auto-refresh"), 10) || 60;
    setTimeout(function () { window.location.reload(); }, seconds * 1000);
  }

  /* Video ads (ad_type='video', autoplay enabled) — the server never adds
     the "autoplay" attribute (see wpm_ad_markup() in site-bootstrap.php),
     it only marks eligible <video> tags with data-autoplay="1". Playback
     is started/stopped here, driven purely by viewport visibility, so an
     autoplaying ad never plays while scrolled off-screen. */
  var autoplayVideos = Array.prototype.slice.call(document.querySelectorAll("video[data-autoplay='1']"));
  if (autoplayVideos.length && "IntersectionObserver" in window) {
    var videoObserver = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.play().catch(function () { /* autoplay blocked — ignore */ });
          } else {
            entry.target.pause();
          }
        });
      },
      { threshold: 0.5 }
    );
    autoplayVideos.forEach(function (v) { videoObserver.observe(v); });
  }
}());
