
<?php
require_once __DIR__ . '/../../app/security.php';
require_once __DIR__ . '/../../config/database.php';

require_login();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['agroprocure_rate_csrf'])) {
    $_SESSION['agroprocure_rate_csrf'] = bin2hex(random_bytes(32));
}

function grh($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$csrfToken = $_SESSION['agroprocure_rate_csrf'];
$userId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;

$error = '';
$success = '';

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $success = 'Rate saved successfully. Earlier rate records have been preserved.';
}

$selectedType = $_GET['type'] ?? 'all';
$allowedTypes = ['all', 'purchase', 'selling', 'market_reference'];

if (!in_array($selectedType, $allowedTypes, true)) {
    $selectedType = 'all';
}

$search = trim($_GET['search'] ?? '');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedToken = $_POST['csrf_token'] ?? '';

        if (!is_string($submittedToken) ||
            !hash_equals($csrfToken, $submittedToken)) {
            throw new RuntimeException(
                'Security token expired. Refresh the page and try again.'
            );
        }

        $goodsId = filter_var(
            $_POST['goods_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $priceType = $_POST['price_type'] ?? '';
        $rateInput = trim($_POST['rate'] ?? '');
        $effectiveInput = trim($_POST['effective_at'] ?? '');
        $sourceNote = trim($_POST['source_note'] ?? '');

        if (!$goodsId || $goodsId < 1) {
            throw new RuntimeException('Please select a valid good.');
        }

        if (!in_array(
            $priceType,
            ['purchase', 'selling', 'market_reference'],
            true
        )) {
            throw new RuntimeException('Please select a valid rate type.');
        }

        if (
            $rateInput === '' ||
            !is_numeric($rateInput) ||
            !preg_match('/^\d{1,12}(\.\d{1,2})?$/', $rateInput) ||
            (float)$rateInput <= 0 ||
            (float)$rateInput > 999999999999.99
        ) {
            throw new RuntimeException(
                'Enter a rate greater than zero, with up to two decimal places.'
            );
        }

        if ($effectiveInput === '') {
            throw new RuntimeException('Please select an effective date and time.');
        }

        $effectiveDate = DateTime::createFromFormat(
            '!Y-m-d\TH:i',
            $effectiveInput
        );

        if (
            !$effectiveDate ||
            $effectiveDate->format('Y-m-d\TH:i') !== $effectiveInput
        ) {
            throw new RuntimeException('The effective date and time is invalid.');
        }

        if (strlen($sourceNote) > 255) {
            throw new RuntimeException(
                'The source note must not exceed 255 characters.'
            );
        }

        $checkGood = $pdo->prepare(
            'SELECT id FROM goods WHERE id = ? AND is_active = 1 LIMIT 1'
        );
        $checkGood->execute([$goodsId]);

        if (!$checkGood->fetchColumn()) {
            throw new RuntimeException(
                'The selected good does not exist or is inactive.'
            );
        }

        // Do not allow a new record to rewrite the effective history.
        $checkDate = $pdo->prepare(
            'SELECT id
             FROM goods_price_history
             WHERE goods_id = ?
               AND price_type = ?
               AND (
                    effective_at > ?
                    OR (effective_at = ? AND id > 0)
               )
             LIMIT 1'
        );
        $effectiveSql = $effectiveDate->format('Y-m-d H:i:s');
        $checkDate->execute([
            $goodsId,
            $priceType,
            $effectiveSql,
            $effectiveSql
        ]);

        if ($checkDate->fetchColumn()) {
            throw new RuntimeException(
                'A later rate already exists for this good and rate type. '
                . 'Choose an effective date and time after the latest entry.'
            );
        }

        $insert = $pdo->prepare(
            'INSERT INTO goods_price_history
                (goods_id, price_type, rate, effective_at, source_note, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $insert->execute([
            $goodsId,
            $priceType,
            number_format((float)$rateInput, 2, '.', ''),
            $effectiveSql,
            $sourceNote !== '' ? $sourceNote : null,
            $userId ? (int)$userId : null
        ]);

        // Post/Redirect/Get avoids accidental duplicate submissions.
        header(
            'Location: goods-rate-management.php?saved=1',
            true,
            303
        );
        exit;
    }

    $goodsStmt = $pdo->query(
        'SELECT id, goods_code, goods_name, unit
         FROM goods
         WHERE is_active = 1
         ORDER BY goods_name ASC'
    );
    $goods = $goodsStmt->fetchAll(PDO::FETCH_ASSOC);

    $ratesStmt = $pdo->query(
        'SELECT h.id, h.goods_id, h.price_type, h.rate,
                h.effective_at, h.source_note, g.goods_name, g.goods_code, g.unit
         FROM goods_price_history h
         INNER JOIN goods g ON g.id = h.goods_id
         WHERE g.is_active = 1
           AND NOT EXISTS (
                SELECT 1
                FROM goods_price_history newer
                WHERE newer.goods_id = h.goods_id
                  AND newer.price_type = h.price_type
                  AND (
                       newer.effective_at > h.effective_at
                       OR (
                           newer.effective_at = h.effective_at
                           AND newer.id > h.id
                       )
                  )
           )
         ORDER BY g.goods_name ASC'
    );
    $latestRates = $ratesStmt->fetchAll(PDO::FETCH_ASSOC);

    $currentRates = [];
    foreach ($latestRates as $rateRow) {
        $currentRates[$rateRow['goods_id']][$rateRow['price_type']] = $rateRow;
    }

    $where = [];
    $params = [];

    if ($selectedType !== 'all') {
        $where[] = 'h.price_type = ?';
        $params[] = $selectedType;
    }

    if ($search !== '') {
        $where[] = '(g.goods_name LIKE ? OR g.goods_code LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $historySql = '
        SELECT h.id, h.goods_id, h.price_type, h.rate,
               h.effective_at, h.source_note,
               g.goods_name, g.goods_code, g.unit,
               (
                   SELECT old.rate
                   FROM goods_price_history old
                   WHERE old.goods_id = h.goods_id
                     AND old.price_type = h.price_type
                     AND (
                         old.effective_at < h.effective_at
                         OR (
                             old.effective_at = h.effective_at
                             AND old.id < h.id
                         )
                     )
                   ORDER BY old.effective_at DESC, old.id DESC
                   LIMIT 1
               ) AS previous_rate
        FROM goods_price_history h
        INNER JOIN goods g ON g.id = h.goods_id
    ';

    if ($where) {
        $historySql .= ' WHERE ' . implode(' AND ', $where);
    }

    $historySql .= ' ORDER BY h.effective_at DESC, h.id DESC LIMIT 300';

    $historyStmt = $pdo->prepare($historySql);
    $historyStmt->execute($params);
    $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (RuntimeException $e) {
    $error = $e->getMessage();
    $goods = $goods ?? [];
    $currentRates = $currentRates ?? [];
    $history = $history ?? [];
} catch (PDOException $e) {
    error_log('Goods rate management database error: ' . $e->getMessage());
    $error = 'A database error occurred. Please check the server error log.';
    $goods = $goods ?? [];
    $currentRates = $currentRates ?? [];
    $history = $history ?? [];
}

function grMoney($amount): string
{
    return number_format((float)$amount, 2);
}

function grRateLabel(string $type): string
{
    $labels = [
        'purchase' => 'Purchase',
        'selling' => 'Selling',
        'market_reference' => 'Market Reference'
    ];

    return $labels[$type] ?? $type;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goods Rate Management | AgroProcure</title>
    <link rel="stylesheet" href="../../public/assets/css/goods-rate-management.css">
</head>
<body>
<div class="gr-page">

    <header class="gr-header">
        <div>
            <p class="gr-eyebrow">AGROPROCURE / INVENTORY CONTROL</p>
            <h1>Goods Rate Management</h1>
            <p class="gr-subtitle">
                Maintain purchase, selling and market-reference rates with a
                traceable history of changes.
            </p>
        </div>
        <button type="button" class="gr-button gr-button-light"
                onclick="window.print()">Print Report</button>
    </header>

    <main class="gr-content">

        <?php if ($success !== ''): ?>
            <div class="gr-alert gr-success"><?= grh($success) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="gr-alert gr-error"><?= grh($error) ?></div>
        <?php endif; ?>

        <section class="gr-panel">
            <div class="gr-panel-heading">
                <div>
                    <h2>Record a New Rate</h2>
                    <p>New entries are added to history; previous entries are not overwritten.</p>
                </div>
            </div>

            <?php if (!$goods): ?>
                <div class="gr-empty">
                    No active goods were found. Add or activate goods before recording rates.
                </div>
            <?php else: ?>
                <form method="post" class="gr-form">
                    <input type="hidden" name="csrf_token" value="<?= grh($csrfToken) ?>">

                    <div class="gr-field gr-field-wide">
                        <label for="goods_id">Good</label>
                        <select id="goods_id" name="goods_id" required>
                            <option value="">Select a good</option>
                            <?php foreach ($goods as $good): ?>
                                <option value="<?= (int)$good['id'] ?>">
                                    <?= grh($good['goods_code']) ?> —
                                    <?= grh($good['goods_name']) ?>
                                    (<?= grh($good['unit']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="gr-field">
                        <label for="price_type">Rate Type</label>
                        <select id="price_type" name="price_type" required>
                            <option value="purchase">Purchase Rate</option>
                            <option value="selling">Selling Rate</option>
                            <option value="market_reference">Market Reference Rate</option>
                        </select>
                    </div>

                    <div class="gr-field">
                        <label for="rate">Rate (₹ per unit)</label>
                        <input id="rate" name="rate" type="number"
                               min="0.01" max="999999999999.99"
                               step="0.01" required placeholder="e.g. 45.00">
                    </div>

                    <div class="gr-field">
                        <label for="effective_at">Effective Date &amp; Time</label>
                        <input id="effective_at" name="effective_at"
                               type="datetime-local" required>
                    </div>

                    <div class="gr-field gr-field-wide">
                        <label for="source_note">Source / Reason (optional)</label>
                        <input id="source_note" name="source_note" type="text"
                               maxlength="255"
                               placeholder="e.g. Supplier quotation, approved selling rate">
                    </div>

                    <div class="gr-form-actions">
                        <button type="submit" class="gr-button gr-button-primary">
                            Save Rate
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="gr-panel">
            <div class="gr-panel-heading">
                <div>
                    <h2>Current Rates</h2>
                    <p>The latest recorded rate for each good and rate type.</p>
                </div>
                <span class="gr-count"><?= count($currentRates) ?> goods with rate records</span>
            </div>

            <div class="gr-table-wrap">
                <table class="gr-table">
                    <thead>
                        <tr>
                            <th>Good</th>
                            <th>Unit</th>
                            <th>Purchase Rate</th>
                            <th>Selling Rate</th>
                            <th>Market Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $currentRows = [];
                    foreach ($goods as $good) {
                        if ($search !== '') {
                            $haystack = strtolower($good['goods_name'] . ' ' . $good['goods_code']);
                            if (strpos($haystack, strtolower($search)) === false) {
                                continue;
                            }
                        }
                        $currentRows[] = $good;
                    }
                    ?>
                    <?php if (!$currentRows): ?>
                        <tr><td colspan="5" class="gr-empty-cell">No matching goods found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($currentRows as $good): ?>
                            <?php
                            $goodRates = $currentRates[$good['id']] ?? [];
                            ?>
                            <tr>
                                <td>
                                    <strong><?= grh($good['goods_name']) ?></strong>
                                    <small><?= grh($good['goods_code']) ?></small>
                                </td>
                                <td><?= grh($good['unit']) ?></td>
                                <?php foreach (['purchase', 'selling', 'market_reference'] as $type): ?>
                                    <td>
                                        <?php if (isset($goodRates[$type])): ?>
                                            <strong>₹<?= grMoney($goodRates[$type]['rate']) ?></strong>
                                            <small><?= grh($goodRates[$type]['effective_at']) ?></small>
                                        <?php else: ?>
                                            <span class="gr-muted">Not recorded</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="gr-panel">
            <div class="gr-panel-heading">
                <div>
                    <h2>Rate Change History</h2>
                    <p>Up to 300 recent entries. Changes compare with the preceding rate of the same type.</p>
                </div>
            </div>

            <form method="get" class="gr-filter">
                <div class="gr-field">
                    <label for="search">Search Goods</label>
                    <input id="search" type="search" name="search"
                           value="<?= grh($search) ?>" placeholder="Name or goods code">
                </div>
                <div class="gr-field">
                    <label for="type">Rate Type</label>
                    <select id="type" name="type">
                        <option value="all" <?= $selectedType === 'all' ? 'selected' : '' ?>>All rate types</option>
                        <option value="purchase" <?= $selectedType === 'purchase' ? 'selected' : '' ?>>Purchase</option>
                        <option value="selling" <?= $selectedType === 'selling' ? 'selected' : '' ?>>Selling</option>
                        <option value="market_reference" <?= $selectedType === 'market_reference' ? 'selected' : '' ?>>Market Reference</option>
                    </select>
                </div>
                <div class="gr-filter-actions">
                    <button class="gr-button gr-button-primary" type="submit">Apply Filters</button>
                    <a class="gr-button gr-button-secondary" href="goods-rate-management.php">Reset</a>
                </div>
            </form>

            <div class="gr-table-wrap">
                <table class="gr-table">
                    <thead>
                        <tr>
                            <th>Effective Date</th>
                            <th>Good</th>
                            <th>Rate Type</th>
                            <th>Previous Rate</th>
                            <th>New Rate</th>
                            <th>Change</th>
                            <th>Source / Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$history): ?>
                        <tr>
                            <td colspan="7" class="gr-empty-cell">
                                No rate history found. Record the first rate above to begin tracking changes.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history as $row): ?>
                            <?php
                            $previous = $row['previous_rate'];
                            $change = $previous !== null
                                ? (float)$row['rate'] - (float)$previous
                                : null;
                            $percent = (
                                $previous !== null && (float)$previous != 0.0
                            ) ? ($change / (float)$previous) * 100 : null;
                            ?>
                            <tr>
                                <td><?= grh($row['effective_at']) ?></td>
                                <td>
                                    <strong><?= grh($row['goods_name']) ?></strong>
                                    <small><?= grh($row['goods_code']) ?></small>
                                </td>
                                <td><span class="gr-type"><?= grh(grRateLabel($row['price_type'])) ?></span></td>
                                <td>
                                    <?= $previous !== null ? '₹' . grMoney($previous) : '<span class="gr-muted">First record</span>' ?>
                                </td>
                                <td><strong>₹<?= grMoney($row['rate']) ?></strong></td>
                                <td>
                                    <?php if ($change === null): ?>
                                        <span class="gr-muted">—</span>
                                    <?php else: ?>
                                        <span class="<?= $change > 0 ? 'gr-up' : ($change < 0 ? 'gr-down' : 'gr-neutral') ?>">
                                            <?= $change > 0 ? '+' : '' ?>₹<?= grMoney($change) ?>
                                            <?php if ($percent !== null): ?>
                                                <small>
                                                    (<?= $percent > 0 ? '+' : '' ?><?= number_format($percent, 2) ?>%)
                                                </small>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $row['source_note'] !== null && $row['source_note'] !== ''
                                    ? grh($row['source_note'])
                                    : '<span class="gr-muted">—</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <p class="gr-footnote">
            Accounting note: these rates are reference records. Existing receipts,
            posted sales, stock-lot costs and supplier balances are not recalculated.
        </p>
    </main>
</div>
</body>
</html>
