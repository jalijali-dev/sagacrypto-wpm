<?php
declare(strict_types=1);

$nav = [
    ['id' => 'dashboard', 'label' => 'Dashboard', 'href' => cms_dashboard_href(), 'icon' => 'grid'],
    ['id' => 'site-settings', 'label' => 'Site Settings', 'href' => cms_nav_href('site-settings.php'), 'icon' => 'gear'],
    ['id' => 'landing-page', 'label' => 'Landing Page', 'href' => cms_nav_href('landing-page.php'), 'icon' => 'home'],
    ['id' => 'banners', 'label' => 'Banners', 'href' => cms_nav_href('banners.php'), 'icon' => 'flag'],
    ['id' => 'special-pages', 'label' => 'Special Pages', 'href' => cms_nav_href('special-pages.php'), 'icon' => 'layers'],
    ['id' => 'pages', 'label' => 'Pages & Articles', 'href' => cms_nav_href('pages.php'), 'icon' => 'file'],
    ['id' => 'products', 'label' => 'Products', 'href' => cms_nav_href('products.php'), 'icon' => 'box'],
    ['id' => 'product-categories', 'label' => 'Product Categories', 'href' => cms_nav_href('product-categories.php'), 'icon' => 'tag'],
    ['id' => 'product-tags', 'label' => 'Product Tags', 'href' => cms_nav_href('product-tags.php'), 'icon' => 'label'],
    ['id' => 'product-images', 'label' => 'Product Images', 'href' => cms_nav_href('product-images.php'), 'icon' => 'photos'],
    ['id' => 'gallery', 'label' => 'Gallery', 'href' => cms_nav_href('gallery.php'), 'icon' => 'image'],
    ['id' => 'media-library', 'label' => 'Media Library', 'href' => cms_nav_href('media-library.php'), 'icon' => 'folder'],
    ['id' => 'testimonials', 'label' => 'Testimonials', 'href' => cms_nav_href('testimonials.php'), 'icon' => 'chat'],
    ['id' => 'contact-messages', 'label' => 'Contact Messages', 'href' => cms_nav_href('contact-messages.php'), 'icon' => 'mail'],
    ['id' => 'seo-dashboard', 'label' => 'SEO Dashboard', 'href' => cms_nav_href('seo-dashboard.php'), 'icon' => 'chart'],
    ['id' => 'seo-redirects', 'label' => 'SEO Redirects', 'href' => cms_nav_href('seo-redirects.php'), 'icon' => 'link'],
    ['id' => 'seo-schema', 'label' => 'SEO Schema', 'href' => cms_nav_href('seo-schema.php'), 'icon' => 'code'],
];

$aiNavGroup = [
    'label' => 'AI Management',
    'icon' => 'ai',
    'items' => [
        ['id' => 'ai-sandbox', 'label' => 'AI Sandbox', 'href' => cms_nav_href('ai-sandbox.php'), 'icon' => 'pencil'],
        ['id' => 'prompt-control', 'label' => 'Prompt Control', 'href' => cms_nav_href('prompt-control.php'), 'icon' => 'code'],
        ['id' => 'ai-credentials', 'label' => 'AI Credentials', 'href' => cms_nav_href('ai-credentials.php'), 'icon' => 'key'],
        ['id' => 'ai-models', 'label' => 'AI Models', 'href' => cms_nav_href('ai-models.php'), 'icon' => 'puzzle'],
        ['id' => 'ai-agent-settings', 'label' => 'AI Agent Settings', 'href' => cms_nav_href('ai-agent-settings.php'), 'icon' => 'person'],
        ['id' => 'admins', 'label' => 'Admin Users', 'href' => cms_nav_href('admins.php'), 'icon' => 'users'],
    ],
];

$currentNav = $currentNav ?? '';
?>
<aside class="admin-sidebar" id="admin-sidebar" aria-label="Primary">
    <div class="admin-sidebar__brand">
        <a class="admin-sidebar__logo" href="<?= cms_esc(cms_dashboard_href()) ?>">
            <span class="admin-sidebar__logo-badge" aria-hidden="true"><?= cms_esc(CMS_ADMIN_NAME) ?></span>
            <span class="admin-sidebar__titles">
                <span class="admin-sidebar__name"><?= cms_esc(CMS_ADMIN_NAME) ?></span>
                <span class="admin-sidebar__tag"><?= cms_esc(CMS_ADMIN_TAGLINE) ?></span>
            </span>
        </a>
        <button type="button" class="admin-sidebar__close" id="admin-sidebar-close" aria-label="Close menu">&times;</button>
    </div>
    <nav class="admin-sidebar__nav" aria-label="CMS sections">
        <?php foreach ($nav as $item) :
            $active = $currentNav === $item['id'];
            ?>
            <a class="admin-navlink<?= $active ? ' is-active' : '' ?>" href="<?= cms_esc($item['href']) ?>" data-icon="<?= cms_esc($item['icon']) ?>">
                <span class="admin-navlink__icon" aria-hidden="true"></span>
                <span class="admin-navlink__label"><?= cms_esc($item['label']) ?></span>
            </a>
        <?php endforeach; ?>

        <div class="admin-sidebar__group">
            <div class="admin-sidebar__group-label">
                <span class="admin-sidebar__group-icon" data-icon="<?= cms_esc($aiNavGroup['icon']) ?>" aria-hidden="true"></span>
                <span><?= cms_esc($aiNavGroup['label']) ?></span>
            </div>
            <?php foreach ($aiNavGroup['items'] as $item) :
                $active = $currentNav === $item['id'];
                ?>
                <a class="admin-navlink<?= $active ? ' is-active' : '' ?>" href="<?= cms_esc($item['href']) ?>" data-icon="<?= cms_esc($item['icon']) ?>">
                    <span class="admin-navlink__icon" aria-hidden="true"></span>
                    <span class="admin-navlink__label"><?= cms_esc($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>
    <div class="admin-sidebar__account">
        <div class="admin-account">
            <?php
            $sidebarAdminName = $_SESSION['cms_admin_name'] ?? 'Admin';
            $sidebarAdminRole = $_SESSION['cms_admin_role'] ?? 'Super Admin';
            $sidebarAdminInitial = mb_strtoupper(mb_substr(trim($sidebarAdminName) !== '' ? trim($sidebarAdminName) : 'A', 0, 1, 'UTF-8'), 'UTF-8');
            ?>
            <div class="admin-account__avatar" aria-hidden="true"><?= cms_esc($sidebarAdminInitial) ?></div>
            <div class="admin-account__meta">
                <div class="admin-account__name"><?= cms_esc($sidebarAdminName) ?></div>
                <div class="admin-account__role"><?= cms_esc($sidebarAdminRole) ?></div>
            </div>
            <div class="admin-account__actions">
                <a class="admin-iconbtn" href="<?= cms_esc(cms_nav_href('site-settings.php')) ?>" title="Site settings" aria-label="Site settings">⚙</a>
                <a class="admin-iconbtn" href="<?= cms_esc(cms_logout_href()) ?>" title="Logout" aria-label="Logout">⎋</a>
            </div>
        </div>
    </div>
</aside>
