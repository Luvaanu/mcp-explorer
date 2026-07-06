<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$allowedOrder = [
    'github_id',
    'name',
    'full_name',
    'owner_login',
    'language',
    'stargazers_count',
    'watchers_count',
    'forks_count',
    'created_at',
    'updated_at',
    'pushed_at',
    'default_branch',
    'size_kb',
    'visibility',
    'license_name',
    'archived',
    'disabled',
    'has_issues',
    'has_wiki',
    'score'
];

$orderBy = $_GET['order_by'] ?? 'created_at';
$orderDir = strtolower($_GET['order_dir'] ?? 'desc');

if (!in_array($orderBy, $allowedOrder, true)) {
    $orderBy = 'created_at';
}
if (!in_array($orderDir, ['asc', 'desc'], true)) {
    $orderDir = 'desc';
}

$q = trim((string) ($_GET['q'] ?? ''));
$language = trim((string) ($_GET['language'] ?? ''));
$owner = trim((string) ($_GET['owner'] ?? ''));
$visibility = trim((string) ($_GET['visibility'] ?? ''));
$license = trim((string) ($_GET['license'] ?? ''));
$topic = trim((string) ($_GET['topic'] ?? ''));
$branch = trim((string) ($_GET['branch'] ?? ''));
$starsMin = (int) ($_GET['stars_min'] ?? 0);
$starsMax = (int) ($_GET['stars_max'] ?? 0);
$forksMin = (int) ($_GET['forks_min'] ?? 0);
$sizeMin = (int) ($_GET['size_min'] ?? 0);
$sizeMax = (int) ($_GET['size_max'] ?? 0);
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$archived = $_GET['archived'] ?? '';
$hasIssues = $_GET['has_issues'] ?? '';
$hasWiki = $_GET['has_wiki'] ?? '';

$perPage = (int) ($_GET['per_page'] ?? 50);
if (!in_array($perPage, [50, 100, 200], true)) {
    $perPage = 50;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = " FROM repositorios WHERE 1=1";
$params = [];

if ($q !== '') {
    $where .= " AND (
        name LIKE :q
        OR full_name LIKE :q
        OR owner_login LIKE :q
        OR description LIKE :q
        OR raw_json LIKE :q
        OR query_source LIKE :q
    )";
    $params[':q'] = "%$q%";
}

if ($language !== '') {
    $where .= " AND language LIKE :language";
    $params[':language'] = "%$language%";
}

if ($owner !== '') {
    $where .= " AND owner_login LIKE :owner";
    $params[':owner'] = "%$owner%";
}

if ($visibility !== '') {
    $where .= " AND visibility = :visibility";
    $params[':visibility'] = $visibility;
}

if ($license !== '') {
    $where .= " AND license_name LIKE :license";
    $params[':license'] = "%$license%";
}

if ($topic !== '') {
    $where .= " AND topics LIKE :topic";
    $params[':topic'] = "%$topic%";
}

if ($branch !== '') {
    $where .= " AND default_branch LIKE :branch";
    $params[':branch'] = "%$branch%";
}

if ($starsMin > 0) {
    $where .= " AND stargazers_count >= :starsMin";
    $params[':starsMin'] = $starsMin;
}

if ($starsMax > 0) {
    $where .= " AND stargazers_count <= :starsMax";
    $params[':starsMax'] = $starsMax;
}

if ($forksMin > 0) {
    $where .= " AND forks_count >= :forksMin";
    $params[':forksMin'] = $forksMin;
}

if ($sizeMin > 0) {
    $where .= " AND size_kb >= :sizeMin";
    $params[':sizeMin'] = $sizeMin;
}

if ($sizeMax > 0) {
    $where .= " AND size_kb <= :sizeMax";
    $params[':sizeMax'] = $sizeMax;
}

if ($dateFrom !== '') {
    $where .= " AND DATE(created_at) >= :dateFrom";
    $params[':dateFrom'] = $dateFrom;
}

if ($dateTo !== '') {
    $where .= " AND DATE(created_at) <= :dateTo";
    $params[':dateTo'] = $dateTo;
}

if ($archived !== '') {
    $where .= " AND archived = :archived";
    $params[':archived'] = (int) $archived;
}

if ($hasIssues !== '') {
    $where .= " AND has_issues = :hasIssues";
    $params[':hasIssues'] = (int) $hasIssues;
}

if ($hasWiki !== '') {
    $where .= " AND has_wiki = :hasWiki";
    $params[':hasWiki'] = (int) $hasWiki;
}

$sql = "SELECT * " . $where . " ORDER BY $orderBy $orderDir LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$repos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$countSql = "SELECT COUNT(*) " . $where;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalFiltered = (int) $countStmt->fetchColumn();

$totalRepos = (int) $pdo->query("SELECT COUNT(*) FROM repositorios")->fetchColumn();
$totalPages = max(1, (int) ceil($totalFiltered / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$showFrom = $totalFiltered > 0 ? $offset + 1 : 0;
$showTo = min($offset + $perPage, $totalFiltered);

function buildUrl(array $overrides = []): string
{
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return 'index.php?' . http_build_query($params);
}

function sortLink(string $column, string $label): string
{
    $currentBy = $_GET['order_by'] ?? 'created_at';
    $currentDir = strtolower($_GET['order_dir'] ?? 'desc');
    $nextDir = ($currentBy === $column && $currentDir === 'asc') ? 'desc' : 'asc';

    $params = $_GET;
    $params['order_by'] = $column;
    $params['order_dir'] = $nextDir;
    $params['page'] = 1;

    $isActive = $currentBy === $column;
    $arrow = '';

    if ($isActive) {
        $arrow = $currentDir === 'asc' ? ' ▲' : ' ▼';
    }

    $class = $isActive ? 'th-link active' : 'th-link';

    return '<a class="' . $class . '" href="' . e('index.php?' . http_build_query($params)) . '">' . e($label . $arrow) . '</a>';
}

function pageLink(int $targetPage, string $label, bool $active = false, bool $disabled = false): string
{
    if ($disabled) {
        return '<span class="page-btn disabled">' . e($label) . '</span>';
    }

    $class = $active ? 'page-btn active' : 'page-btn';
    return '<a class="' . $class . '" href="' . e(buildUrl(['page' => $targetPage])) . '">' . e($label) . '</a>';
}

function boolBadge($value): string
{
    return ((int) $value === 1)
        ? '<span class="bool yes">Sí</span>'
        : '<span class="bool no">No</span>';
}

/*
|--------------------------------------------------------------------------
| Repositorios CJK (idiomas no latinos) con paginación propia
|--------------------------------------------------------------------------
*/
$cjkPerPage = (int) ($_GET['cjk_per_page'] ?? 50);
if (!in_array($cjkPerPage, [25, 50, 100, 200], true)) {
    $cjkPerPage = 50;
}

$cjkPage = max(1, (int) ($_GET['cjk_page'] ?? 1));
$cjkOffset = ($cjkPage - 1) * $cjkPerPage;

$sqlCjkCount = "SELECT COUNT(*) FROM repositorios_cjk";
$totalCjk = (int) $pdo->query($sqlCjkCount)->fetchColumn();
$totalCjkPages = max(1, (int) ceil($totalCjk / $cjkPerPage));

if ($cjkPage > $totalCjkPages) {
    $cjkPage = $totalCjkPages;
    $cjkOffset = ($cjkPage - 1) * $cjkPerPage;
}

$sqlCjk = "
    SELECT id, name, owner_login, description, topics, html_url, created_at
    FROM repositorios_cjk
    ORDER BY created_at DESC, id DESC
    LIMIT :limit OFFSET :offset
";

$stmtCjk = $pdo->prepare($sqlCjk);
$stmtCjk->bindValue(':limit', $cjkPerPage, PDO::PARAM_INT);
$stmtCjk->bindValue(':offset', $cjkOffset, PDO::PARAM_INT);
$stmtCjk->execute();

$cjkRepos = $stmtCjk->fetchAll(PDO::FETCH_ASSOC);

$cjkShowFrom = $totalCjk > 0 ? $cjkOffset + 1 : 0;
$cjkShowTo = min($cjkOffset + $cjkPerPage, $totalCjk);

function buildCjkUrl(array $overrides = []): string
{
    $params = $_GET;

    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }

    return 'index.php?' . http_build_query($params) . '#cjk';
}

function cjkPageLink(int $targetPage, string $label, bool $active = false, bool $disabled = false): string
{
    if ($disabled) {
        return '<span class="page-btn disabled">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    $class = $active ? 'page-btn active' : 'page-btn';

    return '<a class="' . $class . '" href="' .
        htmlspecialchars(buildCjkUrl(['cjk_page' => $targetPage]), ENT_QUOTES, 'UTF-8') .
        '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>MCP Repository Explorer</title>
    <style>
        :root {
            --bg: #f4f7fb;
            --card: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --line: #e5e7eb;
            --primary: #232d67;
            --primary-soft: #eef2ff;
            --primary-border: #c7d2fe;
            --accent: #52b8b5;
            --success-bg: #ecfdf5;
            --success-text: #047857;
            --danger-bg: #fef2f2;
            --danger-text: #b91c1c;
            --shadow: 0 10px 30px rgba(15, 23, 42, .08);
            --radius: 18px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 28px;
            font-family: Inter, Arial, Helvetica, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(82, 184, 181, .12), transparent 24%),
                radial-gradient(circle at top right, rgba(35, 45, 103, .10), transparent 20%),
                var(--bg);
            color: var(--text);
        }

        .container {
            max-width: 1700px;
            margin: 0 auto;
        }

        .hero {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 22px;
            padding: 24px 28px;
            background: linear-gradient(135deg, #232d67 0%, #32408f 100%);
            color: white;
            border-radius: 24px;
            box-shadow: var(--shadow);
        }

        .hero h1 {
            margin: 0;
            font-size: 30px;
            line-height: 1.1;
        }

        .hero p {
            margin: 8px 0 0;
            color: rgba(255, 255, 255, .82);
            max-width: 760px;
        }

        .hero-badges {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .hero-badge {
            background: rgba(255, 255, 255, .14);
            border: 1px solid rgba(255, 255, 255, .18);
            color: white;
            border-radius: 999px;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }

        .card {
            background: var(--card);
            border: 1px solid rgba(226, 232, 240, .9);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 22px;
            margin-bottom: 18px;
        }

        .card h3 {
            margin: 0 0 16px;
            font-size: 18px;
            color: var(--primary);
        }

.grid {
    display: grid;
    grid-template-columns: repeat(8, minmax(0, 1fr));
    gap: 10px;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

label {
    font-size: 11px;
    font-weight: 600;
    color: #4b5563;
}

input,
select {
    padding: 8px 10px;
    font-size: 12px;
    border-radius: 10px;
}

        input,
        select {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            background: #fff;
            font-size: 14px;
            color: #111827;
            outline: none;
            transition: .18s ease;
        }

        input:focus,
        select:focus {
            border-color: #8ea2ff;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, .12);
        }

        .actions,
        .card-actions,
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }

        .actions {
            margin-top: 18px;
        }

        .card-actions {
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .toolbar {
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .btn,
        button {
            appearance: none;
            border: none;
            border-radius: 12px;
            padding: 11px 16px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: .18s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary,
        button {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover,
        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(35, 45, 103, .20);
        }

        .btn-secondary {
            background: #f8fafc;
            color: var(--primary);
            border: 1px solid #dbe4ff;
        }

        .btn-secondary:hover {
            background: #eef2ff;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .stat {
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 16px;
        }

        .stat .label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--muted);
            font-weight: 700;
            margin-bottom: 6px;
        }

        .stat .value {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary);
        }

        .stat .sub {
            margin-top: 4px;
            font-size: 12px;
            color: var(--muted);
        }

.table-wrap {
    overflow: auto;
    max-height: calc(100vh - 180px);
    border: 1px solid #e5e7eb;
    border-radius: 16px;
}

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 13px;
            background: white;
        }

        thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: linear-gradient(180deg, #232d67 0%, #28357d 100%);
            color: white;
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, .12);
            white-space: nowrap;
        }

        tbody td {
            padding: 11px 10px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
        }

        tbody tr:nth-child(even) td {
            background: #fbfcfe;
        }

        tbody tr:hover td {
            background: #f4f7ff;
        }

        .th-link {
            color: rgba(255, 255, 255, .9);
            text-decoration: none;
            font-weight: 700;
        }

        .th-link:hover,
        .th-link.active {
            color: #fff;
            text-decoration: underline;
        }

        .badge {
            display: inline-block;
            background: var(--primary-soft);
            color: #3730a3;
            border: 1px solid var(--primary-border);
            border-radius: 999px;
            padding: 4px 8px;
            font-size: 11px;
            margin: 2px 4px 2px 0;
            white-space: nowrap;
        }

        .bool {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 700;
            min-width: 42px;
        }

        .bool.yes {
            background: var(--success-bg);
            color: var(--success-text);
            border: 1px solid #a7f3d0;
        }

        .bool.no {
            background: var(--danger-bg);
            color: var(--danger-text);
            border: 1px solid #fecaca;
        }

        .repo-link {
            color: var(--primary);
            font-weight: 700;
            text-decoration: none;
        }

        .repo-link:hover {
            text-decoration: underline;
        }

        .desc {
            max-width: 340px;
            color: #4b5563;
            line-height: 1.45;
        }

        .small {
            font-size: 12px;
            color: var(--muted);
            margin-top: 4px;
        }

        .muted {
            color: var(--muted);
        }

        .top-row {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .top-row .left,
        .top-row .right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .select-inline {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 8px 10px;
            font-size: 13px;
        }

        .select-inline select {
            border: none;
            padding: 4px 6px;
            background: transparent;
            box-shadow: none;
        }

        .pagination {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 18px;
        }

        .page-btn {
            min-width: 40px;
            height: 40px;
            padding: 0 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            color: var(--primary);
            background: white;
            border: 1px solid #dbe3f3;
            transition: .18s ease;
        }

        .page-btn:hover {
            background: #eef2ff;
            border-color: #c7d2fe;
        }

        .page-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 8px 18px rgba(35, 45, 103, .18);
        }

        .page-btn.disabled {
            opacity: .45;
            cursor: default;
            pointer-events: none;
            background: #f9fafb;
        }

        .empty {
            padding: 30px;
            text-align: center;
            color: var(--muted);
        }

        @media (max-width: 1280px) {
            .grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 860px) {
            body {
                padding: 18px;
            }

            .hero {
                flex-direction: column;
                align-items: flex-start;
            }

            .hero-badges {
                justify-content: flex-start;
            }

            .grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 560px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .top-row,
            .toolbar,
            .card-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .btn,
            button {
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <div class="container">

        <div class="hero">
            <div>
                <h1>MCP Repository Explorer</h1>
                <p>
                    Explorador de repositorios MCP con filtros avanzados, ordenamiento, paginación y exportación.
                </p>
            </div>

            <div class="hero-badges">
                <span class="hero-badge">Base total: <?= number_format($totalRepos, 0, ',', '.') ?></span>
                <span class="hero-badge">Filtrados: <?= number_format($totalFiltered, 0, ',', '.') ?></span>
                <span class="hero-badge">Página <?= $page ?> / <?= $totalPages ?></span>
            </div>
        </div>

        <div class="card">
            <h3>Importar repositorios desde GitHub</h3>
            <form action="api/github_import.php" method="post">
                <div class="grid">
                    <div class="field">
                        <label>Fecha desde</label>
                        <input type="date" name="desde" value="2024-11-01" required>
                    </div>

                    <div class="field">
                        <label>Fecha hasta</label>
                        <input type="date" name="hasta" value="2026-02-28" required>
                    </div>

                    <div class="field">
                        <label>Tipo de búsqueda</label>
                        <select name="search_mode">
                            <option value="all">Todas las variantes MCP</option>
                            <option value="topic_mcp">Solo topic:mcp</option>
                            <option value="topic_model_context_protocol">Solo topic:model-context-protocol</option>
                            <option value="text_model_context_protocol">Solo "model context protocol"</option>
                            <option value="text_mcp">Solo mcp en nombre/descripción/readme</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>&nbsp;</label>
                        <button type="submit">Importar repositorios MCP</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="card">
            <h3>Filtros avanzados</h3>

            <form method="get">
                <div class="grid">
                    <div class="field">
                        <label>Texto libre</label>
                        <input type="text" name="q" value="<?= e($q) ?>">
                    </div>

                    <div class="field">
                        <label>Lenguaje</label>
                        <input type="text" name="language" value="<?= e($language) ?>">
                    </div>

                    <div class="field">
                        <label>Owner</label>
                        <input type="text" name="owner" value="<?= e($owner) ?>">
                    </div>

                    <div class="field">
                        <label>Visibilidad</label>
                        <input type="text" name="visibility" value="<?= e($visibility) ?>">
                    </div>

                    <div class="field">
                        <label>Licencia</label>
                        <input type="text" name="license" value="<?= e($license) ?>">
                    </div>

                    <div class="field">
                        <label>Topic</label>
                        <input type="text" name="topic" value="<?= e($topic) ?>">
                    </div>

                    <div class="field">
                        <label>Branch</label>
                        <input type="text" name="branch" value="<?= e($branch) ?>">
                    </div>

                    <div class="field">
                        <label>Stars mínimas</label>
                        <input type="number" name="stars_min" value="<?= $starsMin > 0 ? $starsMin : '' ?>">
                    </div>

                    <div class="field">
                        <label>Stars máximas</label>
                        <input type="number" name="stars_max" value="<?= $starsMax > 0 ? $starsMax : '' ?>">
                    </div>

                    <div class="field">
                        <label>Forks mínimas</label>
                        <input type="number" name="forks_min" value="<?= $forksMin > 0 ? $forksMin : '' ?>">
                    </div>

                    <div class="field">
                        <label>Tamaño mín. (KB)</label>
                        <input type="number" name="size_min" value="<?= $sizeMin > 0 ? $sizeMin : '' ?>">
                    </div>

                    <div class="field">
                        <label>Tamaño máx. (KB)</label>
                        <input type="number" name="size_max" value="<?= $sizeMax > 0 ? $sizeMax : '' ?>">
                    </div>

                    <div class="field">
                        <label>Fecha desde</label>
                        <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
                    </div>

                    <div class="field">
                        <label>Fecha hasta</label>
                        <input type="date" name="date_to" value="<?= e($dateTo) ?>">
                    </div>

                    <div class="field">
                        <label>Archivado</label>
                        <select name="archived">
                            <option value="">Todos</option>
                            <option value="1" <?= $archived === '1' ? 'selected' : '' ?>>Sí</option>
                            <option value="0" <?= $archived === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Tiene issues</label>
                        <select name="has_issues">
                            <option value="">Todos</option>
                            <option value="1" <?= $hasIssues === '1' ? 'selected' : '' ?>>Sí</option>
                            <option value="0" <?= $hasIssues === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Tiene wiki</label>
                        <select name="has_wiki">
                            <option value="">Todos</option>
                            <option value="1" <?= $hasWiki === '1' ? 'selected' : '' ?>>Sí</option>
                            <option value="0" <?= $hasWiki === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>

                    <div class="field">
                        <label>Resultados por página</label>
                        <select name="per_page">
                            <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
                            <option value="200" <?= $perPage === 200 ? 'selected' : '' ?>>200</option>
                        </select>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit">Buscar</button>
                    <a class="btn btn-secondary" href="index.php">Limpiar filtros</a>
                </div>
            </form>
        </div>

        <div class="stats">
            <div class="stat">
                <div class="label">Total almacenados</div>
                <div class="value"><?= number_format($totalRepos, 0, ',', '.') ?></div>
                <div class="sub">Cantidad total en la base</div>
            </div>

            <div class="stat">
                <div class="label">Coincidencias</div>
                <div class="value"><?= number_format($totalFiltered, 0, ',', '.') ?></div>
                <div class="sub">Resultados del filtro actual</div>
            </div>

            <div class="stat">
                <div class="label">Mostrando</div>
                <div class="value"><?= number_format($showFrom, 0, ',', '.') ?> -
                    <?= number_format($showTo, 0, ',', '.') ?></div>
                <div class="sub">Rango visible en esta página</div>
            </div>

            <div class="stat">
                <div class="label">Orden actual</div>
                <div class="value" style="font-size:18px"><?= e($orderBy) ?></div>
                <div class="sub"><?= strtoupper($orderDir) ?></div>
            </div>
        </div>
        <br>
        <div class="card">
            <div class="card-actions">
                <div class="left muted">
                    Página <strong><?= $page ?></strong> de <strong><?= $totalPages ?></strong>
                </div>

                <div class="right">
                    <a class="btn btn-secondary" href="exports/export_excel.php?<?= http_build_query($_GET) ?>">
                        Exportar resultados
                    </a>

                    <a class="btn btn-primary" href="estadisticas.php">
                        Ver estadísticas
                    </a>
                </div>
            </div>

            <div class="top-row">
                <div class="left muted">
                    Mostrando <strong><?= number_format($showFrom, 0, ',', '.') ?></strong> a
                    <strong><?= number_format($showTo, 0, ',', '.') ?></strong>
                    de <strong><?= number_format($totalFiltered, 0, ',', '.') ?></strong> resultados
                </div>

                <div class="right">
                    <form method="get" class="select-inline">
                        <?php foreach ($_GET as $k => $v): ?>
                            <?php if ($k !== 'per_page' && $k !== 'page'): ?>
                                <input type="hidden" name="<?= e((string) $k) ?>"
                                    value="<?= e(is_array($v) ? implode(',', $v) : (string) $v) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <span>Ver</span>
                        <select name="per_page" onchange="this.form.submit()">
                            <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
                            <option value="200" <?= $perPage === 200 ? 'selected' : '' ?>>200</option>
                        </select>
                        <span>por página</span>
                    </form>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>N°</th>
                            <th><?= sortLink('github_id', 'GitHub ID') ?></th>
                            <th><?= sortLink('name', 'Nombre') ?></th>
                            <th><?= sortLink('owner_login', 'Owner') ?></th>
                            <th><?= sortLink('language', 'Lenguaje') ?></th>
                            <th><?= sortLink('stargazers_count', 'Stars') ?></th>
                            <th><?= sortLink('watchers_count', 'Watchers') ?></th>
                            <th><?= sortLink('forks_count', 'Forks') ?></th>
                            <th><?= sortLink('size_kb', 'Tamaño KB') ?></th>
                            <th><?= sortLink('default_branch', 'Branch') ?></th>
                            <th><?= sortLink('license_name', 'Licencia') ?></th>
                            <th><?= sortLink('visibility', 'Visibilidad') ?></th>
                            <th><?= sortLink('archived', 'Archivado') ?></th>
                            <th><?= sortLink('has_issues', 'Issues') ?></th>
                            <th><?= sortLink('has_wiki', 'Wiki') ?></th>
                            <th><?= sortLink('score', 'Score') ?></th>
                            <th><?= sortLink('created_at', 'Creado') ?></th>
                            <th><?= sortLink('updated_at', 'Actualizado') ?></th>
                            <th><?= sortLink('pushed_at', 'Push') ?></th>
                            <th>Topics</th>
                            <th>Descripción</th>
                            <th>Repositorio</th>
                            <th>JSON</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!$repos): ?>
                            <tr>
                                <td colspan="23" class="empty">No hay resultados para los filtros aplicados.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($repos as $i => $repo): ?>
                            <tr>
                                <td><?= $offset + $i + 1 ?></td>
                                <td><?= (int) $repo['github_id'] ?></td>
                                <td><strong><?= e($repo['name']) ?></strong></td>
                                <td><?= e($repo['owner_login']) ?></td>
                                <td><?= e($repo['language']) ?></td>
                                <td><?= number_format((int) $repo['stargazers_count'], 0, ',', '.') ?></td>
                                <td><?= number_format((int) $repo['watchers_count'], 0, ',', '.') ?></td>
                                <td><?= number_format((int) $repo['forks_count'], 0, ',', '.') ?></td>
                                <td><?= number_format((int) $repo['size_kb'], 0, ',', '.') ?></td>
                                <td><?= e($repo['default_branch']) ?></td>
                                <td><?= e($repo['license_name']) ?></td>
                                <td><?= e($repo['visibility']) ?></td>
                                <td><?= boolBadge($repo['archived']) ?></td>
                                <td><?= boolBadge($repo['has_issues']) ?></td>
                                <td><?= boolBadge($repo['has_wiki']) ?></td>
                                <td><?= e((string) $repo['score']) ?></td>
                                <td><?= e($repo['created_at']) ?></td>
                                <td><?= e($repo['updated_at']) ?></td>
                                <td><?= e($repo['pushed_at']) ?></td>
                                <td>
                                    <?php
                                    $topics = json_decode((string) $repo['topics'], true);
                                    if (is_array($topics) && $topics) {
                                        foreach ($topics as $tp) {
                                            echo '<span class="badge">' . e((string) $tp) . '</span>';
                                        }
                                    } else {
                                        echo '<span class="muted">—</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div class="desc">
                                        <?php if (!empty($repo['description'])): ?>
                                            <?= e($repo['description']) ?>
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <a class="repo-link" href="<?= e($repo['html_url']) ?>" target="_blank"
                                        rel="noopener">Abrir</a>
                                    <div class="small"><?= e($repo['full_name']) ?></div>
                                </td>
                                <td>
                                    <a class="repo-link" href="repo_json.php?id=<?= (int) $repo['id'] ?>" target="_blank"
                                        rel="noopener">
                                        Ver JSON
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $startPage = max(1, $page - 3);
            $endPage = min($totalPages, $page + 3);
            ?>

            <div class="pagination">
                <?= pageLink(1, '«', false, $page <= 1) ?>
                <?= pageLink(max(1, $page - 1), '‹', false, $page <= 1) ?>

                <?php if ($startPage > 1): ?>
                    <?= pageLink(1, '1', $page === 1) ?>
                    <?php if ($startPage > 2): ?>
                        <span class="page-btn disabled">...</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                    <?= pageLink($p, (string) $p, $p === $page) ?>
                <?php endfor; ?>

                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <span class="page-btn disabled">...</span>
                    <?php endif; ?>
                    <?= pageLink($totalPages, (string) $totalPages, $page === $totalPages) ?>
                <?php endif; ?>

                <?= pageLink(min($totalPages, $page + 1), '›', false, $page >= $totalPages) ?>
                <?= pageLink($totalPages, '»', false, $page >= $totalPages) ?>
            </div>
        </div>
    </div>

</body>

</html>