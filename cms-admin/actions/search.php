<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

/**
 * Global admin search — AJAX endpoint for the navbar search box.
 * Searches across Products, Pages & Articles, Gallery, Testimonials, and
 * Contact Messages, and returns a small grouped result set as JSON.
 *
 * GET /cms-admin/actions/search.php?q=term
 */

header('Content-Type: application/json');

$q = trim((string) ($_GET['q'] ?? ''));

if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'query' => $q, 'results' => []]);
    exit;
}

$like = '%' . $q . '%';
$results = [];

/**
 * Run one search query and append its rows (already shaped as result
 * items) to $results. Wrapped per-table so one missing/broken table
 * never breaks the whole search.
 */
$runSearch = static function (PDO $pdo, string $sql, array $params, callable $mapRow) use (&$results): void {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $results[] = $mapRow($row);
        }
    } catch (PDOException $e) {
        // Silently skip this category — a schema hiccup in one table
        // shouldn't take down search for everything else.
    }
};

// ── Products ────────────────────────────────────────────────────────────
$runSearch(
    $pdo,
    'SELECT id, name, slug FROM products WHERE name LIKE :q OR slug LIKE :q ORDER BY name LIMIT 5',
    ['q' => $like],
    static fn (array $row): array => [
        'type'     => 'Products',
        'title'    => (string) $row['name'],
        'subtitle' => '/' . $row['slug'],
        'url'      => 'products.php?edit=' . (int) $row['id'],
    ]
);

// ── Pages & Articles ────────────────────────────────────────────────────
$runSearch(
    $pdo,
    'SELECT page_id, title, slug FROM pages WHERE title LIKE :q OR slug LIKE :q ORDER BY title LIMIT 5',
    ['q' => $like],
    static fn (array $row): array => [
        'type'     => 'Pages & Articles',
        'title'    => (string) $row['title'],
        'subtitle' => '/' . $row['slug'],
        'url'      => 'pages.php?edit=' . (int) $row['page_id'],
    ]
);

// ── Gallery ──────────────────────────────────────────────────────────────
$runSearch(
    $pdo,
    'SELECT id, title FROM gallery WHERE title LIKE :q ORDER BY title LIMIT 5',
    ['q' => $like],
    static fn (array $row): array => [
        'type'     => 'Gallery',
        'title'    => (string) $row['title'],
        'subtitle' => 'Gallery item',
        'url'      => 'gallery.php?edit=' . (int) $row['id'],
    ]
);

// ── Testimonials ─────────────────────────────────────────────────────────
$runSearch(
    $pdo,
    'SELECT id, client_name, content FROM testimonials WHERE client_name LIKE :q OR content LIKE :q ORDER BY client_name LIMIT 5',
    ['q' => $like],
    static fn (array $row): array => [
        'type'     => 'Testimonials',
        'title'    => (string) $row['client_name'],
        'subtitle' => mb_strimwidth((string) $row['content'], 0, 60, '…'),
        'url'      => 'testimonials.php?edit=' . (int) $row['id'],
    ]
);

// ── Contact Messages ─────────────────────────────────────────────────────
$runSearch(
    $pdo,
    'SELECT id, full_name, email, message FROM contact_messages
     WHERE full_name LIKE :q OR email LIKE :q OR message LIKE :q
     ORDER BY created_at DESC LIMIT 5',
    ['q' => $like],
    static fn (array $row): array => [
        'type'     => 'Contact Messages',
        'title'    => (string) $row['full_name'],
        'subtitle' => (string) $row['email'],
        'url'      => 'contact-messages.php?view=' . (int) $row['id'],
    ]
);

echo json_encode(['ok' => true, 'query' => $q, 'results' => $results], JSON_UNESCAPED_SLASHES);
