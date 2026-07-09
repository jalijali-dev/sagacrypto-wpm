<?php
declare(strict_types=1);

/**
 * Placeholder data for CMS layout previews (ERD-oriented, no persistence).
 */
return [
    'erd_stats' => [
        ['key' => 'active_products', 'label' => 'Active products', 'value' => '28', 'hint' => '6 draft, 22 published'],
        ['key' => 'gallery_items', 'label' => 'Gallery items', 'value' => '42', 'hint' => '9 hidden from storefront'],
        ['key' => 'contact_messages', 'label' => 'Contact messages', 'value' => '14', 'hint' => '3 unread (dummy)'],
        ['key' => 'published_pages', 'label' => 'Published pages', 'value' => '11', 'hint' => 'Landing + special + static'],
        ['key' => 'seo_redirects', 'label' => 'SEO redirects', 'value' => '8', 'hint' => '301 rules (sample)'],
        ['key' => 'media_files', 'label' => 'Media files', 'value' => '156', 'hint' => 'Images + documents (sample)'],
    ],
    'recent_products' => [
        ['name' => 'Birthday Cake — Pastel Bloom', 'sku' => 'VC-BDAY-001', 'category' => 'Birthday Cake', 'price' => 'Rp 850.000', 'status' => 'Published'],
        ['name' => 'Bento Cake — Mini Heart', 'sku' => 'VC-BENTO-014', 'category' => 'Bento Cake', 'price' => 'Rp 185.000', 'status' => 'Published'],
        ['name' => 'Dessert Box — Berry Trio', 'sku' => 'VC-DSRT-008', 'category' => 'Dessert Box', 'price' => 'Rp 320.000', 'status' => 'Draft'],
        ['name' => 'Korean Cake — Lilac Mist', 'sku' => 'VC-KR-003', 'category' => 'Korean Cake', 'price' => 'Rp 520.000', 'status' => 'Published'],
    ],
    'contact_messages' => [
        ['from' => 'Nadia Rahma', 'email' => 'nadia@example.com', 'subject' => 'Konsultasi custom wedding cake', 'time' => '2 jam lalu', 'badge' => 'New'],
        ['from' => 'Raka Pratama', 'email' => 'raka@example.com', 'subject' => 'Request pricelist dessert box', 'time' => 'Kemarin', 'badge' => ''],
        ['from' => 'Mira Lestari', 'email' => 'mira@example.com', 'subject' => 'Follow up pesanan #VC-1024', 'time' => '2 hari lalu', 'badge' => ''],
    ],
    'pages_list' => [
        ['title' => 'Beranda', 'slug' => 'home', 'type' => 'Static', 'status' => 'Published', 'updated' => '12 Mei 2026'],
        ['title' => 'Katalog', 'slug' => 'catalog', 'type' => 'Static', 'status' => 'Draft', 'updated' => '10 Mei 2026'],
        ['title' => 'Kontak', 'slug' => 'contact', 'type' => 'Static', 'status' => 'Published', 'updated' => '08 Mei 2026'],
    ],
    'landing_page' => [
        ['field' => 'Hero title', 'value' => 'Kue Homemade Premium…', 'locale' => 'id', 'updated' => '11 Mei 2026'],
        ['field' => 'Hero CTA label', 'value' => 'Pesan Sekarang', 'locale' => 'id', 'updated' => '11 Mei 2026'],
        ['field' => 'Hero desktop image', 'value' => '(empty — placeholder)', 'locale' => 'all', 'updated' => '—'],
    ],
    'special_pages' => [
        ['title' => 'Promo Ramadan', 'slug' => 'promo-ramadan', 'purpose' => 'Seasonal campaign', 'status' => 'Draft'],
        ['title' => 'Wedding Packages', 'slug' => 'wedding-packages', 'purpose' => 'Lead capture', 'status' => 'Published'],
    ],
    'banners' => [
        ['name' => 'Home top ribbon', 'placement' => 'global_header', 'schedule' => 'Always on', 'status' => 'Active'],
        ['name' => 'Dessert week promo', 'placement' => 'home_hero_secondary', 'schedule' => '1–7 Jun 2026', 'status' => 'Scheduled'],
    ],
    'product_categories' => [
        ['name' => 'Birthday Cake', 'slug' => 'birthday-cake', 'products' => '8', 'visible' => 'Yes', 'sort' => '1'],
        ['name' => 'Bento Cake', 'slug' => 'bento-cake', 'products' => '5', 'visible' => 'Yes', 'sort' => '2'],
        ['name' => 'Dessert Box', 'slug' => 'dessert-box', 'products' => '6', 'visible' => 'Yes', 'sort' => '3'],
    ],
    'product_images' => [
        ['product' => 'Birthday Cake — Pastel Bloom', 'role' => 'Primary', 'path' => '/media/products/vc-bday-001.jpg', 'alt' => 'Pastel tier cake', 'sort' => '1'],
        ['product' => 'Bento Cake — Mini Heart', 'role' => 'Gallery', 'path' => '/media/products/vc-bento-014-2.jpg', 'alt' => 'Top view bento', 'sort' => '2'],
    ],
    'product_tags' => [
        ['tag' => 'custom', 'slug' => 'custom', 'usage' => '14 products', 'visible' => 'Yes'],
        ['tag' => 'wedding', 'slug' => 'wedding', 'usage' => '6 products', 'visible' => 'Yes'],
        ['tag' => 'halal-friendly', 'slug' => 'halal-friendly', 'usage' => '3 products', 'visible' => 'Yes'],
    ],
    'gallery' => [
        ['title' => 'Showcase 01', 'alt' => 'Tiered pastel cake', 'status' => 'Visible', 'order' => '1'],
        ['title' => 'Showcase 02', 'alt' => 'Bento set', 'status' => 'Visible', 'order' => '2'],
        ['title' => 'Showcase 03', 'alt' => 'Dessert box grid', 'status' => 'Hidden', 'order' => '3'],
    ],
    'media_library' => [
        ['filename' => 'logo-master.png', 'folder' => '/brand', 'size' => '212 KB', 'mime' => 'image/png', 'uploaded' => '01 Mei 2026'],
        ['filename' => 'hero-spring.jpg', 'folder' => '/banners', 'size' => '1.1 MB', 'mime' => 'image/jpeg', 'uploaded' => '05 Mei 2026'],
        ['filename' => 'price-list-q2.pdf', 'folder' => '/docs', 'size' => '420 KB', 'mime' => 'application/pdf', 'uploaded' => '09 Mei 2026'],
    ],
    'testimonials' => [
        ['customer' => 'Nadya P.', 'rating' => '5', 'city' => 'Jakarta', 'snippet' => 'Cantik banget! Detailnya rapi...', 'status' => 'Shown'],
        ['customer' => 'Rania S.', 'rating' => '5', 'city' => 'Bandung', 'snippet' => 'Packagingnya berasa luxury...', 'status' => 'Shown'],
    ],
    'seo_redirects' => [
        ['from_path' => '/old-menu', 'to_path' => '/catalog', 'code' => '301', 'active' => 'Yes', 'notes' => 'Legacy URL'],
        ['from_path' => '/promo-2025', 'to_path' => '/promo-ramadan', 'code' => '302', 'active' => 'Yes', 'notes' => 'Seasonal swap'],
    ],
    'seo_schema' => [
        ['page' => 'Home', 'type' => 'LocalBusiness + WebSite', 'valid' => 'Valid (sample)', 'last_check' => '12 Mei 2026'],
        ['page' => 'Contact', 'type' => 'ContactPage', 'valid' => 'Warnings (sample)', 'last_check' => '10 Mei 2026'],
    ],
    'admins' => [
        ['name' => 'Admin WPM', 'email' => 'admin@wpm.local', 'role' => 'Super Admin', 'last_login' => 'Hari ini, 09:12'],
        ['name' => 'Editor Demo', 'email' => 'editor@wpm.local', 'role' => 'Editor', 'last_login' => 'Kemarin'],
    ],
    'site_settings_preview' => [
        ['label' => 'Nama situs', 'value' => 'WPM', 'group' => 'General'],
        ['label' => 'WhatsApp bisnis', 'value' => '6281234567890', 'group' => 'Contact'],
        ['label' => 'Meta title default', 'value' => 'WPM | Your Site Tagline', 'group' => 'SEO'],
        ['label' => 'Default OG image', 'value' => '/media/og-default.jpg', 'group' => 'SEO'],
    ],
];
