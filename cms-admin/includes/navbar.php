<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Dashboard';
?>
<div class="admin-main">
    <header class="admin-navbar">
        <div class="admin-navbar__left">
            <button type="button" class="admin-navbar__toggle" id="admin-sidebar-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="admin-sidebar">
                <span class="admin-navbar__toggle-bars" aria-hidden="true"></span>
            </button>
            <h1 class="admin-navbar__title"><?= cms_esc($pageTitle) ?></h1>
        </div>
        <div class="admin-navbar__center">
            <label class="admin-search" data-pages-prefix="<?= cms_esc(cms_pages_prefix()) ?>">
                <span class="visually-hidden">Search</span>
                <input type="search"
                       id="admin-search-input"
                       class="admin-search__input"
                       placeholder="Search products, pages, gallery, testimonials, messages…"
                       autocomplete="off"
                       data-search-action="<?= cms_esc(cms_action_href('search.php')) ?>">
                <div class="admin-search__results" id="admin-search-results" hidden></div>
            </label>
        </div>
        <div class="admin-navbar__right">
            <button type="button" class="admin-bell" title="Notifications (coming soon)" aria-label="Notifications" disabled>
                <span class="admin-bell__icon" aria-hidden="true">🔔</span>
                <span class="admin-bell__dot" aria-hidden="true"></span>
            </button>
            <label class="theme-switcher" for="theme-switcher">
                <span class="theme-switcher__icon" aria-hidden="true">🎨</span>
                <select class="theme-switcher__select"
                        id="theme-switcher"
                        aria-label="Select colour theme"
                        data-theme-action="<?= cms_esc(cms_action_href('theme-update.php')) ?>"
                        data-csrf-token="<?= cms_esc(cms_csrf_token()) ?>">
                    <option value="deep-purple">Purple</option>
                    <option value="dark-modern">Dark</option>
                    <option value="light-modern">Light</option>
                </select>
            </label>
            <a class="admin-btn admin-btn--ghost" href="<?= cms_esc(cms_public_site_url()) ?>" target="_blank" rel="noopener">View Site</a>
        </div>
    </header>
