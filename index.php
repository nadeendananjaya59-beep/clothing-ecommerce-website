<?php
function storefront_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function storefront_excerpt($value, $limit = 120) {
    $value = trim((string) $value);
    if ($value === '') {
        return 'Designed for fast-moving commerce teams that need elegant product presentation and confident selling tools.';
    }

    if (function_exists('mb_strlen') && mb_strlen($value) <= $limit) {
        return $value;
    }

    if (!function_exists('mb_strlen') && strlen($value) <= $limit) {
        return $value;
    }

    $slice = function_exists('mb_substr') ? mb_substr($value, 0, $limit - 3) : substr($value, 0, $limit - 3);
    return rtrim($slice) . '...';
}

function storefront_price_label($amount, $fallback = 'Price on request') {
    if ($amount === null || $amount === '') {
        return $fallback;
    }

    return 'LKR ' . number_format((float) $amount, 2);
}

function storefront_discount_percentage($selling_price, $discount_price) {
    $selling_price = (float) $selling_price;
    $discount_price = (float) $discount_price;

    if ($selling_price <= 0 || $discount_price <= 0 || $discount_price >= $selling_price) {
        return null;
    }

    return (int) round((($selling_price - $discount_price) / $selling_price) * 100);
}

function storefront_placeholder_image($title, $subtitle) {
    $title = substr((string) $title, 0, 36);
    $subtitle = substr((string) $subtitle, 0, 42);

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 960" role="img" aria-label="' . storefront_escape($title) . '">'
        . '<defs>'
        . '<linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%">'
        . '<stop offset="0%" stop-color="#6C63FF"/>'
        . '<stop offset="50%" stop-color="#8B5CF6"/>'
        . '<stop offset="100%" stop-color="#4F46E5"/>'
        . '</linearGradient>'
        . '</defs>'
        . '<rect width="800" height="960" fill="#F8FAFC"/>'
        . '<rect x="48" y="48" width="704" height="864" rx="44" fill="url(#g)" opacity="0.14"/>'
        . '<circle cx="624" cy="196" r="142" fill="#8B5CF6" opacity="0.24"/>'
        . '<circle cx="196" cy="764" r="170" fill="#6C63FF" opacity="0.22"/>'
        . '<rect x="150" y="220" width="500" height="420" rx="36" fill="#FFFFFF" opacity="0.92"/>'
        . '<rect x="224" y="308" width="352" height="24" rx="12" fill="#6C63FF" opacity="0.18"/>'
        . '<rect x="224" y="358" width="268" height="24" rx="12" fill="#8B5CF6" opacity="0.16"/>'
        . '<rect x="224" y="438" width="208" height="120" rx="24" fill="#4F46E5" opacity="0.12"/>'
        . '<text x="110" y="734" fill="#1E293B" font-family="Manrope, Arial, sans-serif" font-size="42" font-weight="700">' . storefront_escape($title) . '</text>'
        . '<text x="110" y="792" fill="#475569" font-family="Manrope, Arial, sans-serif" font-size="24">' . storefront_escape($subtitle) . '</text>'
        . '</svg>';

    return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
}

function storefront_media_path($image_path, $title, $subtitle) {
    $image_path = trim(str_replace('\\', '/', (string) $image_path));
    if ($image_path !== '') {
        $relative = strpos($image_path, 'owner/') === 0 ? $image_path : 'owner/' . ltrim($image_path, '/');
        $absolute = __DIR__ . '/' . $relative;
        if (is_file($absolute)) {
            return $relative;
        }
    }

    return storefront_placeholder_image($title, $subtitle);
}

$storefront_ready = false;
$fallback_mode = false;
$featured_products = [];
$signature_deals = [];
$category_spotlights = [];
$stats = [
    'available_products' => 0,
    'category_count' => 0,
    'inventory_units' => 0,
    'shop_count' => 0
];

$reviews = [
    [
        'name' => 'Ayesha Ramanayake',
        'role' => 'Style-first shopper',
        'quote' => 'The experience feels polished and premium. Product discovery is clean, fast, and genuinely enjoyable on mobile.',
        'rating' => 5
    ],
    [
        'name' => 'Nimal Perera',
        'role' => 'Frequent online shopper',
        'quote' => 'Everything feels clear and premium. It is easy to compare products, spot promotions, and move through the page without friction.',
        'rating' => 5
    ],
    [
        'name' => 'Sofia Khan',
        'role' => 'Brand consultant',
        'quote' => 'The interface carries the confidence of larger marketplaces while still giving the catalog room to feel curated and intentional.',
        'rating' => 5
    ]
];

$db = @mysqli_connect('localhost', 'root', '', 'ecommerce_db');
if ($db) {
    mysqli_set_charset($db, 'utf8mb4');
    $storefront_ready = true;

    $stats_result = mysqli_query(
        $db,
        "SELECT
            SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) AS available_products,
            COUNT(DISTINCT CASE WHEN category_id IS NOT NULL THEN category_id END) AS category_count,
            COALESCE(SUM(stock_qty), 0) AS inventory_units,
            COUNT(DISTINCT CASE WHEN shop_id IS NOT NULL THEN shop_id END) AS shop_count
         FROM products"
    );
    if ($stats_result) {
        $stats_row = mysqli_fetch_assoc($stats_result);
        if ($stats_row) {
            $stats['available_products'] = (int) ($stats_row['available_products'] ?? 0);
            $stats['category_count'] = (int) ($stats_row['category_count'] ?? 0);
            $stats['inventory_units'] = (int) ($stats_row['inventory_units'] ?? 0);
            $stats['shop_count'] = (int) ($stats_row['shop_count'] ?? 0);
        }
        mysqli_free_result($stats_result);
    }

    $featured_result = mysqli_query(
        $db,
        "SELECT
            p.product_id,
            p.shop_id,
            p.product_name,
            p.description,
            p.selling_price,
            p.discount_price,
            p.stock_qty,
            p.status,
            p.created_at,
            COALESCE(c.category_name, 'Signature Collection') AS category_name,
            COALESCE(b.brand_name, 'Independent Label') AS brand_name,
            (
                SELECT pi.image_path
                FROM product_images pi
                WHERE pi.product_id = p.product_id
                ORDER BY pi.is_main DESC, pi.image_id ASC
                LIMIT 1
            ) AS image_path
         FROM products p
         LEFT JOIN categories c ON c.category_id = p.category_id
         LEFT JOIN brands b ON b.brand_id = p.brand_id
         WHERE p.status = 'Available'
         ORDER BY
            CASE WHEN p.discount_price IS NOT NULL AND p.discount_price > 0 THEN 0 ELSE 1 END,
            p.created_at DESC,
            p.stock_qty DESC
         LIMIT 8"
    );

    if ($featured_result) {
        while ($row = mysqli_fetch_assoc($featured_result)) {
            $row['product_id'] = (int) ($row['product_id'] ?? 0);
            $row['shop_id'] = (int) ($row['shop_id'] ?? 0);
            $row['stock_qty'] = (int) ($row['stock_qty'] ?? 0);
            $row['selling_price'] = (float) ($row['selling_price'] ?? 0);
            $row['discount_price'] = $row['discount_price'] !== null ? (float) $row['discount_price'] : null;
            $featured_products[] = $row;
        }
        mysqli_free_result($featured_result);
    }

    $category_result = mysqli_query(
        $db,
        "SELECT
            COALESCE(c.category_name, 'Signature Collection') AS category_name,
            COUNT(DISTINCT p.product_id) AS product_count,
            MIN(COALESCE(NULLIF(p.discount_price, 0), p.selling_price)) AS starting_price,
            ROUND(AVG(p.selling_price), 2) AS average_price
         FROM products p
         LEFT JOIN categories c ON c.category_id = p.category_id
         WHERE p.status = 'Available'
         GROUP BY category_name
         ORDER BY product_count DESC, category_name ASC
         LIMIT 4"
    );

    if ($category_result) {
        while ($row = mysqli_fetch_assoc($category_result)) {
            $row['product_count'] = (int) ($row['product_count'] ?? 0);
            $row['starting_price'] = $row['starting_price'] !== null ? (float) $row['starting_price'] : null;
            $row['average_price'] = $row['average_price'] !== null ? (float) $row['average_price'] : null;
            $category_spotlights[] = $row;
        }
        mysqli_free_result($category_result);
    }

    mysqli_close($db);
}

if (empty($featured_products)) {
    $fallback_mode = true;
    $featured_products = [
        [
            'product_id' => 301,
            'shop_id' => 1,
            'product_name' => 'Aerial Weave Overshirt',
            'description' => 'Lightweight layering piece with a tailored silhouette, matte hardware, and movement-friendly comfort.',
            'selling_price' => 4290.00,
            'discount_price' => 3690.00,
            'stock_qty' => 18,
            'status' => 'Available',
            'created_at' => date('Y-m-d H:i:s'),
            'category_name' => 'Overshirts',
            'brand_name' => 'Echelon Studio',
            'image_path' => ''
        ],
        [
            'product_id' => 302,
            'shop_id' => 1,
            'product_name' => 'Cityline Structured Tee',
            'description' => 'Premium cotton tee with a clean drape, reinforced neckline, and elevated everyday finish.',
            'selling_price' => 1890.00,
            'discount_price' => null,
            'stock_qty' => 42,
            'status' => 'Available',
            'created_at' => date('Y-m-d H:i:s'),
            'category_name' => 'T-Shirts',
            'brand_name' => 'North Quarter',
            'image_path' => ''
        ],
        [
            'product_id' => 303,
            'shop_id' => 1,
            'product_name' => 'Harbor Taper Denim',
            'description' => 'Slim-taper denim designed for all-day wear, with refined washes and high-retention stretch.',
            'selling_price' => 5290.00,
            'discount_price' => 4690.00,
            'stock_qty' => 14,
            'status' => 'Available',
            'created_at' => date('Y-m-d H:i:s'),
            'category_name' => 'Denim',
            'brand_name' => 'Madeform',
            'image_path' => ''
        ],
        [
            'product_id' => 304,
            'shop_id' => 1,
            'product_name' => 'Monarch Knit Polo',
            'description' => 'Soft-touch knit polo that blends business-casual polish with weekend-ready comfort.',
            'selling_price' => 3590.00,
            'discount_price' => null,
            'stock_qty' => 26,
            'status' => 'Available',
            'created_at' => date('Y-m-d H:i:s'),
            'category_name' => 'Polos',
            'brand_name' => 'Atelier Mode',
            'image_path' => ''
        ],
        [
            'product_id' => 305,
            'shop_id' => 1,
            'product_name' => 'Summit Utility Jacket',
            'description' => 'Weather-ready outer layer with modular pockets, subtle sheen, and premium finishes.',
            'selling_price' => 7990.00,
            'discount_price' => 6990.00,
            'stock_qty' => 9,
            'status' => 'Available',
            'created_at' => date('Y-m-d H:i:s'),
            'category_name' => 'Outerwear',
            'brand_name' => 'Axis Collective',
            'image_path' => ''
        ],
        [
            'product_id' => 306,
            'shop_id' => 1,
            'product_name' => 'Contour Linen Trousers',
            'description' => 'Relaxed premium trousers with breathable texture, tapered hems, and elevated finishing.',
            'selling_price' => 4890.00,
            'discount_price' => null,
            'stock_qty' => 22,
            'status' => 'Available',
            'created_at' => date('Y-m-d H:i:s'),
            'category_name' => 'Trousers',
            'brand_name' => 'House Meridian',
            'image_path' => ''
        ]
    ];
}

if (empty($category_spotlights)) {
    $category_spotlights = [
        ['category_name' => 'T-Shirts', 'product_count' => 18, 'starting_price' => 1590.00, 'average_price' => 2190.00],
        ['category_name' => 'Denim', 'product_count' => 12, 'starting_price' => 3890.00, 'average_price' => 4690.00],
        ['category_name' => 'Outerwear', 'product_count' => 9, 'starting_price' => 5590.00, 'average_price' => 7190.00],
        ['category_name' => 'Polos', 'product_count' => 11, 'starting_price' => 2490.00, 'average_price' => 3190.00]
    ];
}

if ($stats['available_products'] === 0) {
    $stats['available_products'] = count($featured_products);
}
if ($stats['category_count'] === 0) {
    $stats['category_count'] = count($category_spotlights);
}
if ($stats['inventory_units'] === 0) {
    foreach ($featured_products as $product) {
        $stats['inventory_units'] += (int) $product['stock_qty'];
    }
}
if ($stats['shop_count'] === 0) {
    $stats['shop_count'] = 1;
}

$hero_product = $featured_products[0];
$signature_deals = $featured_products;
usort($signature_deals, function ($left, $right) {
    $left_discount = storefront_discount_percentage($left['selling_price'], $left['discount_price']) ?? 0;
    $right_discount = storefront_discount_percentage($right['selling_price'], $right['discount_price']) ?? 0;

    return ($right_discount <=> $left_discount)
        ?: ((float) $right['selling_price'] <=> (float) $left['selling_price'])
        ?: strcasecmp((string) $left['product_name'], (string) $right['product_name']);
});
$signature_deals = array_slice($signature_deals, 0, 4);
$new_arrivals = array_slice($featured_products, 0, 4);
$nav_categories = array_slice($category_spotlights, 0, 4);
$hero_image = storefront_media_path($hero_product['image_path'] ?? '', $hero_product['product_name'], $hero_product['category_name']);
$hero_discount = storefront_discount_percentage($hero_product['selling_price'], $hero_product['discount_price']);
$hero_price = $hero_product['discount_price'] !== null && $hero_product['discount_price'] > 0
    ? storefront_price_label($hero_product['discount_price'])
    : storefront_price_label($hero_product['selling_price']);

$promotion_cards = [
    [
        'eyebrow' => 'Storefront spotlight',
        'title' => 'Designed to showcase collections with confidence',
        'copy' => 'Blend premium storytelling, polished layouts, and strong visual hierarchy to guide shoppers smoothly from discovery to decision.',
        'link' => '#products',
        'link_label' => 'Shop featured arrivals'
    ],
    [
        'eyebrow' => 'Seasonal spotlight',
        'title' => 'Lead with your strongest category and move inventory faster',
        'copy' => 'Feature ' . $nav_categories[0]['category_name'] . ' with strategic pricing, sharper imagery, and friction-free discovery across desktop and mobile journeys.',
        'link' => '#products',
        'link_label' => 'Browse the collection'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="E-Commerce Management System storefront UI with dynamic products, premium visuals, and responsive commerce-first design.">
    <title>E-Commerce Management System</title>
    <script>document.documentElement.classList.add('js-enabled');</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/storefront.css">
</head>

<body class="page-loading">
    <div class="page-loader" id="pageLoader" aria-hidden="true">
        <div class="page-loader__orb"></div>
        <div class="page-loader__brand">
            <span class="page-loader__eyebrow">E-Commerce Management System</span>
            <strong>Loading a premium commerce experience</strong>
            <span>Preparing catalog, collections, and interface layers...</span>
        </div>
        <div class="page-loader__bar">
            <span></span>
        </div>
    </div>

    <header class="announcement-bar" id="top">
        <div class="site-shell announcement-bar__inner">
            <p>Storefront UI foundation with dynamic catalog content, premium navigation, and polished mobile-first interactions.</p>
            <a href="#products">Shop featured drops</a>
        </div>
    </header>

    <nav class="site-nav" id="siteNav">
        <div class="site-shell site-nav__inner">
            <a href="#top" class="brand-mark" aria-label="E-Commerce Management System home">
                <span class="brand-mark__icon">EM</span>
                <span class="brand-mark__copy">
                    <strong>E-Commerce Management System</strong>
                    <small>Premium commerce UI foundation</small>
                </span>
            </a>

            <button class="nav-toggle" id="navToggle" type="button" aria-expanded="false" aria-controls="navPanel">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <div class="nav-panel" id="navPanel">
                <ul class="nav-menu">
                    <li><a href="#home">Home</a></li>
                    <li class="nav-menu__has-dropdown">
                        <button type="button" class="nav-dropdown-toggle" data-dropdown-toggle aria-expanded="false">
                            Shop
                            <span class="nav-caret"></span>
                        </button>
                        <div class="dropdown-panel">
                            <a href="#products">
                                <strong>Featured products</strong>
                                <span>Browse the current hero catalog and curated launches.</span>
                            </a>
                            <a href="#promotions">
                                <strong>Campaign banners</strong>
                                <span>Launch new-season promos and spotlight conversion moments.</span>
                            </a>
                            <a href="#newsletter">
                                <strong>Newsletter drops</strong>
                                <span>Stay close to launches, promotions, and seasonal updates.</span>
                            </a>
                        </div>
                    </li>
                    <li class="nav-menu__has-dropdown">
                        <button type="button" class="nav-dropdown-toggle" data-dropdown-toggle aria-expanded="false">
                            Categories
                            <span class="nav-caret"></span>
                        </button>
                        <div class="dropdown-panel">
                            <?php foreach ($nav_categories as $category): ?>
                                <a href="#categories">
                                    <strong><?= storefront_escape($category['category_name']) ?></strong>
                                    <span><?= number_format((int) $category['product_count']) ?> active styles from <?= storefront_price_label($category['starting_price']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </li>
                    <li><a href="#reviews">Reviews</a></li>
                    <li><a href="#newsletter">Newsletter</a></li>
                </ul>

                <div class="nav-actions">
                    <a href="#promotions" class="button button--ghost">View campaigns</a>
                    <a href="#products" class="button button--primary">Shop now</a>
                </div>
            </div>
        </div>
    </nav>

    <main>
        <section class="hero-section" id="home">
            <div class="site-shell hero-section__grid">
                <div class="hero-copy" data-reveal>
                    <span class="eyebrow-pill">Modern commerce interface system</span>
                    <h1>Build a premium storefront experience with the discipline of an enterprise commerce stack.</h1>
                    <p>
                        Inspired by Amazon, Shopify, Daraz, and Alibaba, this UI foundation blends dynamic merchandising,
                        elegant storytelling, and management-ready structure into a responsive storefront built with pure HTML,
                        CSS, JavaScript, PHP, and MySQL.
                    </p>

                    <div class="hero-actions">
                        <a href="#products" class="button button--primary">Explore the catalog</a>
                        <a href="#promotions" class="button button--ghost">Discover deals</a>
                    </div>

                    <div class="hero-metrics">
                        <article class="metric-card">
                            <strong><?= number_format($stats['available_products']) ?>+</strong>
                            <span>Live product records</span>
                        </article>
                        <article class="metric-card">
                            <strong><?= number_format($stats['category_count']) ?></strong>
                            <span>Merchandising categories</span>
                        </article>
                        <article class="metric-card">
                            <strong><?= number_format($stats['inventory_units']) ?></strong>
                            <span>Units in circulation</span>
                        </article>
                    </div>
                </div>

                <div class="hero-showcase glass-card" data-reveal>
                    <div class="hero-showcase__header">
                        <span class="eyebrow-pill eyebrow-pill--soft">Launch highlight</span>
                        <span class="hero-live-pill"><?= $fallback_mode ? 'Curated preview mode' : 'Live catalog data' ?></span>
                    </div>

                    <div class="hero-showcase__visual">
                        <img src="<?= storefront_escape($hero_image) ?>" alt="<?= storefront_escape($hero_product['product_name']) ?>">
                        <div class="hero-floating-note">
                            <span>Top category</span>
                            <strong><?= storefront_escape($hero_product['category_name']) ?></strong>
                        </div>
                    </div>

                    <div class="hero-showcase__body">
                        <div class="hero-showcase__meta">
                            <span><?= storefront_escape($hero_product['brand_name']) ?></span>
                            <span><?= number_format((int) $hero_product['stock_qty']) ?> units ready</span>
                        </div>
                        <h2><?= storefront_escape($hero_product['product_name']) ?></h2>
                        <p><?= storefront_escape(storefront_excerpt($hero_product['description'], 100)) ?></p>

                        <div class="hero-price-row">
                            <div>
                                <strong><?= storefront_escape($hero_price) ?></strong>
                                <?php if ($hero_product['discount_price'] !== null && $hero_product['discount_price'] > 0): ?>
                                    <span><?= storefront_escape(storefront_price_label($hero_product['selling_price'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($hero_discount !== null): ?>
                                <span class="discount-badge">Save <?= (int) $hero_discount ?>%</span>
                            <?php endif; ?>
                        </div>

                        <div class="hero-benefits">
                            <span>Merchandise-led storytelling</span>
                            <span>Responsive discovery flows</span>
                            <span>Fast mobile browsing</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="section-shell section-shell--tint" id="categories">
            <div class="site-shell">
                <div class="section-heading" data-reveal>
                    <div>
                        <span class="eyebrow-pill eyebrow-pill--soft">Category architecture</span>
                        <h2>Shop by curated product lanes</h2>
                    </div>
                    <p>Structured collection entry points inspired by marketplace-scale navigation, tuned for a modern fashion catalog.</p>
                </div>

                <div class="category-grid" data-reveal>
                    <?php foreach ($category_spotlights as $index => $category): ?>
                        <?php
                        $accent_class = 'category-card--violet';
                        if ($index % 3 === 1) {
                            $accent_class = 'category-card--indigo';
                        } elseif ($index % 3 === 2) {
                            $accent_class = 'category-card--slate';
                        }
                        ?>
                        <article class="category-card <?= storefront_escape($accent_class) ?>">
                            <span class="category-card__eyebrow">Collection <?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                            <h3><?= storefront_escape($category['category_name']) ?></h3>
                            <p>Curated styles designed for cleaner discovery, richer merchandising, and higher-intent browsing journeys.</p>
                            <div class="category-card__footer">
                                <strong><?= number_format((int) $category['product_count']) ?> products</strong>
                                <span>From <?= storefront_escape(storefront_price_label($category['starting_price'])) ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="section-shell" id="products">
            <div class="site-shell">
                <div class="section-heading" data-reveal>
                    <div>
                        <span class="eyebrow-pill">Product sections</span>
                        <h2>Featured arrivals and signature deals</h2>
                    </div>
                    <p>Reusable product cards, shimmer placeholders, and commerce-first micro-interactions ready for deeper cart and checkout integration.</p>
                </div>

                <div class="catalog-block" data-reveal>
                    <div class="catalog-block__topline">
                        <div>
                            <h3>Featured Arrivals</h3>
                            <p>Fresh catalog entries with elevated presentation and polished information density.</p>
                        </div>
                        <a href="#newsletter" class="text-link">Get launch alerts</a>
                    </div>

                    <div class="skeleton-grid" aria-hidden="true">
                        <?php for ($i = 0; $i < 4; $i++): ?>
                            <article class="skeleton-card">
                                <span></span>
                                <span></span>
                                <span></span>
                            </article>
                        <?php endfor; ?>
                    </div>

                    <div class="product-grid" data-shell-content>
                        <?php foreach ($new_arrivals as $product): ?>
                            <?php
                            $image = storefront_media_path($product['image_path'] ?? '', $product['product_name'], $product['category_name']);
                            $discount_percent = storefront_discount_percentage($product['selling_price'], $product['discount_price']);
                            $active_price = $product['discount_price'] !== null && $product['discount_price'] > 0
                                ? $product['discount_price']
                                : $product['selling_price'];
                            ?>
                            <article class="product-card product-card--elevated">
                                <div class="product-card__media">
                                    <img src="<?= storefront_escape($image) ?>" alt="<?= storefront_escape($product['product_name']) ?>">
                                    <div class="product-card__chips">
                                        <span><?= storefront_escape($product['category_name']) ?></span>
                                        <?php if ($discount_percent !== null): ?>
                                            <span class="chip chip--sale">-<?= (int) $discount_percent ?>%</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="product-card__body">
                                    <div class="product-card__meta">
                                        <span><?= storefront_escape($product['brand_name']) ?></span>
                                        <span><?= number_format((int) $product['stock_qty']) ?> in stock</span>
                                    </div>
                                    <h3><?= storefront_escape($product['product_name']) ?></h3>
                                    <p><?= storefront_escape(storefront_excerpt($product['description'], 84)) ?></p>
                                    <div class="product-card__footer">
                                        <div class="price-stack">
                                            <strong><?= storefront_escape(storefront_price_label($active_price)) ?></strong>
                                            <?php if ($discount_percent !== null): ?>
                                                <span><?= storefront_escape(storefront_price_label($product['selling_price'])) ?></span>
                                            <?php else: ?>
                                                <span>Premium ready-to-wear</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="product-card__actions">
                                            <button type="button" class="button button--ghost js-demo-trigger" data-demo-message="Wishlist and quick-view flows can plug into this product card.">Preview</button>
                                            <button type="button" class="button button--primary js-demo-trigger" data-demo-message="Cart, checkout, or detail-page integration can connect here next.">Add to bag</button>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="catalog-block catalog-block--secondary" data-reveal>
                    <div class="catalog-block__topline">
                        <div>
                            <h3>Signature Deals</h3>
                            <p>High-impact cards designed for campaigns, margin-friendly bundles, and promotional merchandising.</p>
                        </div>
                        <a href="#promotions" class="text-link">See campaign banners</a>
                    </div>

                    <div class="skeleton-grid" aria-hidden="true">
                        <?php for ($i = 0; $i < 4; $i++): ?>
                            <article class="skeleton-card">
                                <span></span>
                                <span></span>
                                <span></span>
                            </article>
                        <?php endfor; ?>
                    </div>

                    <div class="product-grid product-grid--compact" data-shell-content>
                        <?php foreach ($signature_deals as $product): ?>
                            <?php
                            $image = storefront_media_path($product['image_path'] ?? '', $product['product_name'], $product['category_name']);
                            $discount_percent = storefront_discount_percentage($product['selling_price'], $product['discount_price']);
                            $active_price = $product['discount_price'] !== null && $product['discount_price'] > 0
                                ? $product['discount_price']
                                : $product['selling_price'];
                            ?>
                            <article class="product-card product-card--compact">
                                <div class="product-card__media">
                                    <img src="<?= storefront_escape($image) ?>" alt="<?= storefront_escape($product['product_name']) ?>">
                                </div>
                                <div class="product-card__body">
                                    <div class="product-card__meta">
                                        <span><?= storefront_escape($product['category_name']) ?></span>
                                        <span><?= storefront_escape($product['status']) ?></span>
                                    </div>
                                    <h3><?= storefront_escape($product['product_name']) ?></h3>
                                    <p><?= storefront_escape(storefront_excerpt($product['description'], 66)) ?></p>
                                    <div class="product-card__footer product-card__footer--stacked">
                                        <div class="price-stack">
                                            <strong><?= storefront_escape(storefront_price_label($active_price)) ?></strong>
                                            <?php if ($discount_percent !== null): ?>
                                                <span>Save <?= (int) $discount_percent ?>% today</span>
                                            <?php else: ?>
                                                <span>Fresh premium pricing</span>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" class="button button--ghost js-demo-trigger" data-demo-message="Promotional quick action ready for coupon, bundle, or detail-page logic.">Launch deal</button>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="section-shell section-shell--contrast" id="promotions">
            <div class="site-shell">
                <div class="section-heading section-heading--light" data-reveal>
                    <div>
                        <span class="eyebrow-pill eyebrow-pill--soft">Promotional banners</span>
                        <h2>Campaign spaces with premium commerce energy</h2>
                    </div>
                    <p>Glassmorphism surfaces, stronger hierarchy, and CTA placement designed to support launches, discount campaigns, and brand storytelling.</p>
                </div>

                <div class="promo-grid" data-reveal>
                    <?php foreach ($promotion_cards as $card): ?>
                        <article class="promo-card glass-card glass-card--dark">
                            <span class="promo-card__eyebrow"><?= storefront_escape($card['eyebrow']) ?></span>
                            <h3><?= storefront_escape($card['title']) ?></h3>
                            <p><?= storefront_escape($card['copy']) ?></p>
                            <a href="<?= storefront_escape($card['link']) ?>" class="button button--primary"><?= storefront_escape($card['link_label']) ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="section-shell" id="reviews">
            <div class="site-shell">
                <div class="section-heading" data-reveal>
                    <div>
                        <span class="eyebrow-pill">Customer reviews</span>
                        <h2>Confidence signals that feel premium, not noisy</h2>
                    </div>
                    <p>Review cards, ratings, and calm visual rhythm inspired by high-performing commerce experiences without overloading the customer journey.</p>
                </div>

                <div class="review-grid" data-reveal>
                    <?php foreach ($reviews as $review): ?>
                        <article class="review-card">
                            <div class="review-card__rating" aria-label="<?= (int) $review['rating'] ?> star rating">
                                <?php for ($i = 0; $i < 5; $i++): ?>
                                    <span class="<?= $i < (int) $review['rating'] ? 'is-active' : '' ?>">★</span>
                                <?php endfor; ?>
                            </div>
                            <p>“<?= storefront_escape($review['quote']) ?>”</p>
                            <div class="review-card__author">
                                <strong><?= storefront_escape($review['name']) ?></strong>
                                <span><?= storefront_escape($review['role']) ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="section-shell section-shell--newsletter" id="newsletter">
            <div class="site-shell">
                <div class="newsletter-card glass-card" data-reveal>
                    <div class="newsletter-copy">
                        <span class="eyebrow-pill eyebrow-pill--soft">Newsletter section</span>
                        <h2>Stay ahead of launches, campaigns, and storefront updates.</h2>
                        <p>Capture intent with a compact, conversion-friendly subscription module that fits naturally into a premium commerce layout.</p>
                    </div>

                    <form class="newsletter-form" id="newsletterForm">
                        <label class="sr-only" for="newsletterEmail">Email address</label>
                        <input type="email" id="newsletterEmail" name="newsletter_email" placeholder="Enter your email address" required>
                        <button type="submit" class="button button--primary">Join the list</button>
                    </form>

                    <p class="newsletter-note" id="newsletterNote">No spam. Just launches, offers, and interface-ready campaign announcements.</p>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="site-shell site-footer__grid">
            <div class="site-footer__brand">
                <a href="#top" class="brand-mark brand-mark--footer">
                    <span class="brand-mark__icon">EM</span>
                    <span class="brand-mark__copy">
                        <strong>E-Commerce Management System</strong>
                        <small>Premium storefront experience</small>
                    </span>
                </a>
                <p>Professional storefront UI with responsive layout primitives, product storytelling surfaces, and a clean visual system built for modern online shopping.</p>
            </div>

            <div>
                <h3>Explore</h3>
                <ul>
                    <li><a href="#home">Home</a></li>
                    <li><a href="#categories">Categories</a></li>
                    <li><a href="#products">Products</a></li>
                    <li><a href="#reviews">Reviews</a></li>
                </ul>
            </div>

            <div>
                <h3>Highlights</h3>
                <ul>
                    <li><a href="#products">Featured arrivals</a></li>
                    <li><a href="#promotions">Seasonal campaigns</a></li>
                    <li><a href="#reviews">Customer reviews</a></li>
                    <li><a href="#newsletter">Newsletter signup</a></li>
                </ul>
            </div>

            <div>
                <h3>Commerce signals</h3>
                <ul>
                    <li><?= number_format($stats['available_products']) ?> active products</li>
                    <li><?= number_format($stats['category_count']) ?> curated categories</li>
                    <li><?= number_format($stats['inventory_units']) ?> units catalogued</li>
                    <li><?= number_format($stats['shop_count']) ?> partner store<?= $stats['shop_count'] === 1 ? '' : 's' ?></li>
                </ul>
            </div>
        </div>

        <div class="site-shell site-footer__bottom">
            <p>&copy; <?= date('Y') ?> E-Commerce Management System. Designed for premium digital commerce experiences.</p>
            <div class="site-footer__links">
                <a href="#newsletter">Newsletter</a>
                <a href="#categories">Collections</a>
                <a href="#top">Back to top</a>
            </div>
        </div>
    </footer>

    <div class="ui-toast" id="uiToast" role="status" aria-live="polite"></div>

    <script>
        window.storefrontMeta = {
            fallbackMode: <?= $fallback_mode ? 'true' : 'false' ?>,
            productCount: <?= count($featured_products) ?>
        };
    </script>
    <script src="assets/js/storefront.js"></script>
</body>

</html>
