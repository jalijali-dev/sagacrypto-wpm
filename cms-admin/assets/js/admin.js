(function () {
  var body = document.body;
  var toggle = document.getElementById("admin-sidebar-toggle");
  var closeBtn = document.getElementById("admin-sidebar-close");
  var scrim = document.getElementById("admin-sidebar-scrim");
  var sidebar = document.getElementById("admin-sidebar");

  function setOpen(open) {
    if (open) {
      body.classList.add("admin-sidebar-open");
      if (toggle) toggle.setAttribute("aria-expanded", "true");
      if (scrim) scrim.removeAttribute("hidden");
    } else {
      body.classList.remove("admin-sidebar-open");
      if (toggle) toggle.setAttribute("aria-expanded", "false");
      if (scrim) scrim.setAttribute("hidden", "hidden");
    }
  }

  if (toggle && sidebar) {
    toggle.addEventListener("click", function () {
      var open = !body.classList.contains("admin-sidebar-open");
      setOpen(open);
    });
  }

  if (closeBtn) {
    closeBtn.addEventListener("click", function () {
      setOpen(false);
    });
  }

  if (scrim) {
    scrim.addEventListener("click", function () {
      setOpen(false);
    });
  }

  window.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      setOpen(false);
    }
  });

  window.addEventListener("resize", function () {
    if (window.matchMedia("(min-width: 901px)").matches) {
      setOpen(false);
    }
  });

  document.querySelectorAll(".admin-alert[data-dismissible] .admin-alert__dismiss").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var root = btn.closest(".admin-alert");
      if (root && root.parentElement) {
        root.parentElement.removeChild(root);
      }
    });
  });

  /** Keep the current sidebar item visible after full page navigation (desktop scrollable nav). */
  function scrollActiveSidebarLinkIntoView() {
    var nav = document.querySelector(".admin-sidebar__nav");
    var active = nav && nav.querySelector("a.admin-navlink.is-active");
    if (!nav || !active) {
      return;
    }
    requestAnimationFrame(function () {
      active.scrollIntoView({ block: "center", inline: "nearest", behavior: "auto" });
    });
  }

  scrollActiveSidebarLinkIntoView();
})();

/* ============================================================
   Theme switcher
   Source of truth: PHP session (cms-admin/actions/theme-update.php
   writes $_SESSION['wpm_theme']; includes/header.php reads it and
   renders data-theme on <html> server-side — no FOUC, and it follows
   the admin across every page navigation). This block:
     1. Applies the chosen theme to <html> immediately on change.
     2. Persists it to the session via fetch() so the next page load
        (any menu, any tab) keeps the same theme.
     3. Also mirrors it to localStorage as a same-tab fallback only.
   ============================================================ */
(function () {
  var THEME_KEY    = "wpm-theme";
  var DEFAULT      = "deep-purple";
  var VALID_THEMES = ["dark-modern", "light-modern", "deep-purple"];
  var html         = document.documentElement;

  function persistToSession(theme, select) {
    var action = select.dataset.themeAction;
    var token  = select.dataset.csrfToken;
    if (!action) { return; }
    fetch(action, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
        "X-CSRF-Token": token || ""
      },
      body: "theme=" + encodeURIComponent(theme),
      credentials: "same-origin"
    }).catch(function () {
      /* Network hiccup: theme still applied for this page view via
         localStorage/dataset; it will just re-sync from session on the
         next successful navigation instead. */
    });
  }

  function applyTheme(theme, select) {
    if (VALID_THEMES.indexOf(theme) === -1) { theme = DEFAULT; }
    html.dataset.theme = theme;
    try { localStorage.setItem(THEME_KEY, theme); } catch (e) {}
    if (select) {
      if (select.value !== theme) { select.value = theme; }
      persistToSession(theme, select);
    }
  }

  function initSelect() {
    var select = document.getElementById("theme-switcher");
    if (!select) { return; }
    var current = html.dataset.theme || DEFAULT;
    select.value = current;
    select.addEventListener("change", function () {
      applyTheme(select.value, select);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initSelect);
  } else {
    initSelect();
  }
}());
