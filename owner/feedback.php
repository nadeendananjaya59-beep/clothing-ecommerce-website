<?php

/**
 * Product Feedback & Review Management Module - Shop Owner Dashboard
 */
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
require_owner_login();

$owner_name = $_SESSION['owner_name'] ?? 'Owner';
$shop_name  = $_SESSION['owner_shop_name'] ?? 'Your Shop';
$shop_id    = $_SESSION['owner_shop_id'] ?? 0;
$initials   = strtoupper(substr($owner_name, 0, 1) . (strpos($owner_name, ' ') !== false ? substr($owner_name, strpos($owner_name, ' ') + 1, 1) : ''));

// ─── Handle Approve / Reject Actions ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['feedback_id'])) {
    $f_id   = (int)$_POST['feedback_id'];
    $action = $_POST['action'];

    // Verify feedback belongs to owner's shop
    $verify_res = mysqli_query($conn, "SELECT uf.feedback_id FROM user_feedback uf 
                                       JOIN products p ON uf.product_id = p.product_id 
                                       WHERE uf.feedback_id = $f_id AND p.shop_id = $shop_id LIMIT 1");
    if (mysqli_num_rows($verify_res) > 0) {
        if ($action === 'approve') {
            mysqli_query($conn, "UPDATE user_feedback SET status = 'Approved' WHERE feedback_id = $f_id");
            set_flash('success', 'Review approved successfully.');
        } elseif ($action === 'reject') {
            mysqli_query($conn, "UPDATE user_feedback SET status = 'Rejected' WHERE feedback_id = $f_id");
            set_flash('success', 'Review rejected.');
        }
    } else {
        set_flash('danger', 'Invalid action request.');
    }
    header('Location: feedback.php');
    exit();
}

// ─── Summary Stats (Filtered by Shop ID) ──────────────────
// 1. Total Reviews
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM user_feedback uf JOIN products p ON uf.product_id = p.product_id WHERE p.shop_id = $shop_id");
$total_reviews = mysqli_fetch_assoc($r)['c'];

// 2. Average Rating (Approved reviews)
$r = mysqli_query($conn, "SELECT AVG(uf.rating) AS avg_rate FROM user_feedback uf JOIN products p ON uf.product_id = p.product_id WHERE p.shop_id = $shop_id AND uf.status = 'Approved'");
$avg_rating = (float)mysqli_fetch_assoc($r)['avg_rate'];

// 3. Pending Feedbacks
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM user_feedback uf JOIN products p ON uf.product_id = p.product_id WHERE p.shop_id = $shop_id AND uf.status = 'Pending'");
$pending_count = mysqli_fetch_assoc($r)['c'];

// ─── Filters ─────────────────────────────────────────────
$f_search = trim($_GET['search'] ?? '');
$f_rating = trim($_GET['rating'] ?? '');
$f_status = trim($_GET['status'] ?? '');

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;

$where = ["p.shop_id = $shop_id"];

if ($f_search !== '') {
    $search_esc = mysqli_real_escape_string($conn, $f_search);
    $where[] = "(p.product_name LIKE '%$search_esc%' OR c.full_name LIKE '%$search_esc%' OR uf.feedback_text LIKE '%$search_esc%')";
}
if ($f_rating !== '') {
    $rate_esc = (int)$f_rating;
    $where[] = "uf.rating = $rate_esc";
}
if ($f_status !== '') {
    $status_esc = mysqli_real_escape_string($conn, $f_status);
    $where[] = "uf.status = '$status_esc'";
}

$where_sql = implode(' AND ', $where);

// Count total matching reviews
$count_res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM user_feedback uf 
                                  JOIN products p ON uf.product_id = p.product_id 
                                  JOIN customers c ON uf.customer_id = c.customer_id
                                  WHERE $where_sql");
$total_rows = mysqli_fetch_assoc($count_res)['c'];
$total_pages = max(1, ceil($total_rows / $per_page));

// Fetch main reviews list
$reviews_sql = "SELECT uf.*, p.product_name, c.full_name AS customer_name, c.email AS customer_email 
                FROM user_feedback uf 
                JOIN products p ON uf.product_id = p.product_id 
                JOIN customers c ON uf.customer_id = c.customer_id 
                WHERE $where_sql 
                ORDER BY uf.feedback_date DESC 
                LIMIT $per_page OFFSET $offset";
$reviews_res = mysqli_query($conn, $reviews_sql);
$reviews = [];
if ($reviews_res) {
    while ($row = mysqli_fetch_assoc($reviews_res)) {
        $reviews[] = $row;
    }
}

// ─── 4. Highest Rated Products (Approved) ─────────────────
$highest_sql = "SELECT p.product_name, AVG(uf.rating) AS avg_rate, COUNT(uf.feedback_id) AS total_reviews 
                FROM user_feedback uf 
                JOIN products p ON uf.product_id = p.product_id 
                WHERE p.shop_id = $shop_id AND uf.status = 'Approved' 
                GROUP BY p.product_id 
                ORDER BY avg_rate DESC, total_reviews DESC 
                LIMIT 5";
$highest_res = mysqli_query($conn, $highest_sql);
$highest_products = [];
if ($highest_res) {
    while ($row = mysqli_fetch_assoc($highest_res)) {
        $highest_products[] = $row;
    }
}

// ─── 5. Lowest Rated Products (Approved) ──────────────────
$lowest_sql = "SELECT p.product_name, AVG(uf.rating) AS avg_rate, COUNT(uf.feedback_id) AS total_reviews 
               FROM user_feedback uf 
               JOIN products p ON uf.product_id = p.product_id 
               WHERE p.shop_id = $shop_id AND uf.status = 'Approved' 
               GROUP BY p.product_id 
               ORDER BY avg_rate ASC, total_reviews DESC 
               LIMIT 5";
$lowest_res = mysqli_query($conn, $lowest_sql);
$lowest_products = [];
if ($lowest_res) {
    while ($row = mysqli_fetch_assoc($lowest_res)) {
        $lowest_products[] = $row;
    }
}

// Helper to draw stars
function draw_stars($rating)
{
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="fas fa-star" style="color:#fbbf24; font-size:0.8rem; margin-right:2px;"></i>';
        } else {
            $html .= '<i class="far fa-star" style="color:var(--text-muted); font-size:0.8rem; margin-right:2px;"></i>';
        }
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback & Reviews | <?= htmlspecialchars($shop_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/owner.css">
    <style>
        /* ── Summary Stats ── */
        .fb-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media(max-width: 768px) {
            .fb-grid {
                grid-template-columns: 1fr;
            }
        }

        .fb-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
            transition: all var(--transition);
        }

        .fb-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
        }

        .fb-card::before {
            content: '';
            position: absolute;
            top: -25px;
            right: -15px;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            opacity: .08;
        }

        .fc-purple {
            border-top: 3px solid #6c63ff;
        }

        .fc-purple::before {
            background: #6c63ff;
        }

        .fc-purple .fc-icon {
            background: rgba(108, 99, 255, .15);
            color: #8b84ff;
        }

        .fc-amber {
            border-top: 3px solid #fbbf24;
        }

        .fc-amber::before {
            background: #fbbf24;
        }

        .fc-amber .fc-icon {
            background: rgba(251, 191, 36, .15);
            color: #fbbf24;
        }

        .fc-blue {
            border-top: 3px solid #3b82f6;
        }

        .fc-blue::before {
            background: #3b82f6;
        }

        .fc-blue .fc-icon {
            background: rgba(59, 130, 246, .15);
            color: #60a5fa;
        }

        .fc-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 1rem;
        }

        .fc-sub {
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: .2rem;
        }

        .fc-val {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1;
        }

        /* Filter block */
        .filter-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: .4rem;
            flex: 1;
            min-width: 160px;
        }

        .filter-group label {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-muted);
        }

        .filter-group input,
        .filter-group select {
            background: var(--bg-input);
            border: 1.5px solid var(--border-light);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: .875rem;
            padding: .65rem .9rem;
            outline: none;
            font-family: 'Inter', sans-serif;
            transition: border-color var(--transition);
        }

        .filter-group input:focus,
        .filter-group select:focus {
            border-color: var(--primary);
        }

        .filter-group select option {
            background: #1a1a2e;
        }

        .btn-filter {
            padding: .7rem 1.4rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: .875rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: .4rem;
            font-family: 'Inter', sans-serif;
            transition: all var(--transition);
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 99, 255, .4);
        }

        .btn-reset {
            padding: .7rem 1.1rem;
            background: transparent;
            border: 1.5px solid var(--border-light);
            border-radius: 8px;
            color: var(--text-secondary);
            font-size: .875rem;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: all var(--transition);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: .4rem;
        }

        .btn-reset:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Tables & Lists */
        .card-box {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-box-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid var(--border-light);
        }

        .card-box-header h2 {
            font-size: 1rem;
            font-weight: 700;
            margin: 0;
        }

        .fb-table {
            width: 100%;
            border-collapse: collapse;
        }

        .fb-table th {
            text-align: left;
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-muted);
            font-weight: 700;
            padding: .8rem 1rem;
            border-bottom: 1px solid var(--border-light);
            background: rgba(255, 255, 255, .01);
        }

        .fb-table td {
            padding: 1rem;
            font-size: .86rem;
            color: var(--text-primary);
            border-bottom: 1px solid rgba(255, 255, 255, .04);
            vertical-align: middle;
        }

        .fb-table tr:last-child td {
            border-bottom: none;
        }

        .fb-table tbody tr:hover td {
            background: rgba(108, 99, 255, .04);
        }

        /* Action Buttons */
        .btn-action {
            border: none;
            padding: .4rem .7rem;
            border-radius: 6px;
            font-size: .75rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            transition: all var(--transition);
        }

        .btn-approve {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
        }

        .btn-approve:hover {
            background: #10b981;
            color: #fff;
        }

        .btn-reject {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
        }

        .btn-reject:hover {
            background: #ef4444;
            color: #fff;
        }

        /* Status pills */
        .status-pill {
            display: inline-block;
            padding: .2rem .65rem;
            border-radius: 20px;
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .sp-approved {
            background: rgba(16, 185, 129, .12);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, .25);
        }

        .sp-pending {
            background: rgba(245, 158, 11, .12);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, .25);
        }

        .sp-rejected {
            background: rgba(239, 68, 68, .12);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, .25);
        }

        .two-cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        @media(max-width: 900px) {
            .two-cols {
                grid-template-columns: 1fr;
            }
        }

        /* Pagination */
        .pagination {
            display: flex;
            align-items: center;
            gap: .4rem;
            padding: 1.2rem 1.5rem;
            border-top: 1px solid var(--border-light);
        }

        .pg-btn {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .82rem;
            font-weight: 600;
            border: 1px solid var(--border-light);
            background: transparent;
            color: var(--text-secondary);
            cursor: pointer;
            text-decoration: none;
            transition: all var(--transition);
        }

        .pg-btn:hover,
        .pg-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .pg-btn.disabled {
            opacity: .35;
            pointer-events: none;
        }

        .pg-info {
            margin-left: auto;
            font-size: .8rem;
            color: var(--text-muted);
        }
    </style>
</head>

<body>

    <!-- ═══ TOPBAR ═══ -->
    <div class="topbar">
        <a href="dashboard.php" class="topbar-brand">
            <div class="t-icon">🏪</div>
            <span><?= htmlspecialchars($shop_name) ?></span>
        </a>
        <div class="topbar-right">
            <div class="user-pill">
                <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
                <span><?= htmlspecialchars($owner_name) ?></span>
            </div>
            <a href="logout.php" class="btn-logout">
                <i class="fas fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>

    <div class="dashboard-wrapper">
        <div class="dashboard-container">
            <?php require_once __DIR__ . '/includes/sidebar_quick_actions.php'; ?>

            <div class="dashboard-main">

                <!-- Header -->
                <div class="dashboard-header">
                    <h1>💬 Feedback & Reviews</h1>
                    <p>Review customer comments, moderate pending status, and view product rating metrics.</p>
                </div>

                <!-- Display Flash Messages -->
                <?php if ($flash = get_flash()): ?>
                    <div class="alert alert-<?= $flash['type'] ?>" style="margin-bottom:1.5rem; padding:1rem; border-radius:8px; background:rgba(108,99,255,0.15); color:var(--text-primary); border:1px solid var(--primary);">
                        <?= htmlspecialchars($flash['message']) ?>
                    </div>
                <?php endif; ?>

                <!-- ══ Feedback Stats Grid ══ -->
                <div class="fb-grid">
                    <div class="fb-card fc-purple">
                        <div class="fc-icon"><i class="fas fa-comments"></i></div>
                        <div class="fc-sub">Total Reviews</div>
                        <div class="fc-val"><?= number_format($total_reviews) ?> <span style="font-size:1rem; font-weight:500; color:var(--text-secondary);">received</span></div>
                    </div>
                    <div class="fb-card fc-amber">
                        <div class="fc-icon"><i class="fas fa-star-half-stroke"></i></div>
                        <div class="fc-sub">Average Rating</div>
                        <div class="fc-val"><?= number_format($avg_rating, 2) ?> <span style="font-size:1rem; font-weight:500; color:var(--text-secondary);">/ 5.0</span></div>
                    </div>
                    <div class="fb-card fc-blue">
                        <div class="fc-icon"><i class="fas fa-hourglass-half"></i></div>
                        <div class="fc-sub">Pending Feedbacks</div>
                        <div class="fc-val"><?= number_format($pending_count) ?> <span style="font-size:1rem; font-weight:500; color:var(--text-secondary);">items</span></div>
                    </div>
                </div>

                <!-- ══ Filters ══ -->
                <div class="filter-card">
                    <form method="GET" action="feedback.php">
                        <div class="filter-row">
                            <div class="filter-group" style="flex:2; min-width:200px;">
                                <label><i class="fas fa-magnifying-glass"></i> Search Reviews</label>
                                <input type="text" name="search" placeholder="Search by customer, product, comment text..." value="<?= htmlspecialchars($f_search) ?>">
                            </div>

                            <div class="filter-group">
                                <label><i class="fas fa-star"></i> Rating</label>
                                <select name="rating">
                                    <option value="">All Ratings</option>
                                    <option value="5" <?= $f_rating === '5' ? 'selected' : '' ?>>5 Stars</option>
                                    <option value="4" <?= $f_rating === '4' ? 'selected' : '' ?>>4 Stars</option>
                                    <option value="3" <?= $f_rating === '3' ? 'selected' : '' ?>>3 Stars</option>
                                    <option value="2" <?= $f_rating === '2' ? 'selected' : '' ?>>2 Stars</option>
                                    <option value="1" <?= $f_rating === '1' ? 'selected' : '' ?>>1 Star</option>
                                </select>
                            </div>

                            <div class="filter-group">
                                <label><i class="fas fa-circle-half-stroke"></i> Status</label>
                                <select name="status">
                                    <option value="">All Statuses</option>
                                    <option value="Pending" <?= $f_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="Approved" <?= $f_status === 'Approved' ? 'selected' : '' ?>>Approved</option>
                                    <option value="Rejected" <?= $f_status === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                                </select>
                            </div>

                            <div style="display:flex; gap:.5rem;">
                                <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply</button>
                                <a href="feedback.php" class="btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- ══ Reviews Listing Table ══ -->
                <div class="card-box">
                    <div class="card-box-header">
                        <h2>Product Customer Reviews</h2>
                        <span class="count-badge" style="background:rgba(108,99,255,.15); color:var(--primary-light); padding:.2rem .65rem; border-radius:20px; font-size:.75rem; font-weight:700; border:1px solid rgba(108,99,255,.25);"><?= number_format($total_rows) ?> matches</span>
                    </div>

                    <?php if (!empty($reviews)): ?>
                        <div style="overflow-x:auto;">
                            <table class="fb-table">
                                <thead>
                                    <tr>
                                        <th>Feedback ID</th>
                                        <th>Product Details</th>
                                        <th>Customer Details</th>
                                        <th>Rating</th>
                                        <th style="max-width:300px;">Review Comment</th>
                                        <th>Status</th>
                                        <th>Action Buttons</th>
                                        <th>Date Posted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reviews as $rev): ?>
                                        <tr>
                                            <td style="color:var(--primary-light); font-weight:700;">#<?= $rev['feedback_id'] ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($rev['product_name']) ?></strong>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($rev['customer_name']) ?></strong><br>
                                                <span style="font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars($rev['customer_email']) ?></span>
                                            </td>
                                            <td><?= draw_stars($rev['rating']) ?></td>
                                            <td style="max-width:300px; font-size:0.82rem; color:var(--text-secondary);">
                                                <?= nl2br(htmlspecialchars($rev['feedback_text'])) ?>
                                            </td>
                                            <td>
                                                <span class="status-pill sp-<?= strtolower($rev['status']) ?>">
                                                    <?= $rev['status'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($rev['status'] === 'Pending'): ?>
                                                    <div style="display:flex; gap:0.4rem;">
                                                        <form method="POST" action="feedback.php" style="display:inline;">
                                                            <input type="hidden" name="feedback_id" value="<?= $rev['feedback_id'] ?>">
                                                            <input type="hidden" name="action" value="approve">
                                                            <button type="submit" class="btn-action btn-approve"><i class="fas fa-check"></i> Approve</button>
                                                        </form>
                                                        <form method="POST" action="feedback.php" style="display:inline;">
                                                            <input type="hidden" name="feedback_id" value="<?= $rev['feedback_id'] ?>">
                                                            <input type="hidden" name="action" value="reject">
                                                            <button type="submit" class="btn-action btn-reject"><i class="fas fa-ban"></i> Reject</button>
                                                        </form>
                                                    </div>
                                                <?php elseif ($rev['status'] === 'Approved'): ?>
                                                    <form method="POST" action="feedback.php" style="display:inline;">
                                                        <input type="hidden" name="feedback_id" value="<?= $rev['feedback_id'] ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <button type="submit" class="btn-action btn-reject"><i class="fas fa-ban"></i> Reject</button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" action="feedback.php" style="display:inline;">
                                                        <input type="hidden" name="feedback_id" value="<?= $rev['feedback_id'] ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" class="btn-action btn-approve"><i class="fas fa-check"></i> Approve</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                            <td style="color:var(--text-muted); font-size:0.8rem;">
                                                <?= date('d M Y', strtotime($rev['feedback_date'])) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <?php
                            $params = array_filter(['search' => $f_search, 'rating' => $f_rating, 'status' => $f_status]);
                            $query_string = http_build_query($params);
                            $base_url = 'feedback.php?' . ($query_string ? $query_string . '&' : '');
                            ?>
                            <div class="pagination">
                                <a class="pg-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $base_url ?>page=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
                                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                                    <a class="pg-btn <?= $p === $page ? 'active' : '' ?>" href="<?= $base_url ?>page=<?= $p ?>"><?= $p ?></a>
                                <?php endfor; ?>
                                <a class="pg-btn <?= $page >= $total_pages ? 'disabled' : '' ?>" href="<?= $base_url ?>page=<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
                                <span class="pg-info">Page <?= $page ?> of <?= $total_pages ?> &nbsp;·&nbsp; <?= number_format($total_rows) ?> reviews</span>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div style="text-align:center; padding:3rem 2rem;">
                            <i class="fas fa-comments-slash" style="font-size:3rem; color:var(--text-muted); margin-bottom:1rem; display:block;"></i>
                            <p style="color:var(--text-muted); margin:0;">No product reviews found match filters.</p>
                            <a href="feedback.php" class="btn-reset" style="display:inline-flex; margin-top:1rem;"><i class="fas fa-rotate-left"></i> Clear Filters</a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ══ Lists (Highest Rated & Lowest Rated) ══ -->
                <div class="two-cols">
                    <!-- Highest Rated Products -->
                    <div class="card-box" style="margin:0;">
                        <div class="card-box-header">
                            <h2><i class="fas fa-face-smile" style="color:#10b981; margin-right:0.4rem;"></i> Highest Rated Products</h2>
                        </div>
                        <?php if (!empty($highest_products)): ?>
                            <table class="fb-table">
                                <thead>
                                    <tr>
                                        <th>Product Name</th>
                                        <th style="text-align:center;">Avg Rating</th>
                                        <th style="text-align:center;">Total Reviews</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($highest_products as $hp): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($hp['product_name']) ?></strong></td>
                                            <td style="text-align:center;">
                                                <div style="display:inline-flex; align-items:center; gap:0.3rem;">
                                                    <strong style="color:#fbbf24;"><?= number_format($hp['avg_rate'], 1) ?></strong>
                                                    <i class="fas fa-star" style="color:#fbbf24; font-size:0.75rem;"></i>
                                                </div>
                                            </td>
                                            <td style="text-align:center; color:var(--text-secondary);"><?= $hp['total_reviews'] ?> reviews</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="color:var(--text-muted); padding:1.5rem; text-align:center;">No feedback data received yet.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Lowest Rated Products -->
                    <div class="card-box" style="margin:0;">
                        <div class="card-box-header">
                            <h2><i class="fas fa-face-frown" style="color:#ef4444; margin-right:0.4rem;"></i> Lowest Rated Products</h2>
                        </div>
                        <?php if (!empty($lowest_products)): ?>
                            <table class="fb-table">
                                <thead>
                                    <tr>
                                        <th>Product Name</th>
                                        <th style="text-align:center;">Avg Rating</th>
                                        <th style="text-align:center;">Total Reviews</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lowest_products as $lp): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($lp['product_name']) ?></strong></td>
                                            <td style="text-align:center;">
                                                <div style="display:inline-flex; align-items:center; gap:0.3rem;">
                                                    <strong style="color:#ef4444;"><?= number_format($lp['avg_rate'], 1) ?></strong>
                                                    <i class="fas fa-star" style="color:#ef4444; font-size:0.75rem;"></i>
                                                </div>
                                            </td>
                                            <td style="text-align:center; color:var(--text-secondary);"><?= $lp['total_reviews'] ?> reviews</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="color:var(--text-muted); padding:1.5rem; text-align:center;">No feedback data received yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Back to Dashboard -->
                <div style="margin-top: 2rem;">
                    <a href="dashboard.php" class="btn-reset" style="display:inline-flex;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                </div>

            </div>
        </div>

        <script>
            // Auto-submit dropdown selector on change
            document.querySelectorAll('.filter-group select').forEach(function(select) {
                select.addEventListener('change', function() {
                    this.closest('form').submit();
                });
            });
        </script>
</body>

</html>