<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/**
 * Limpia texto para exportación CSV:
 * - convierte null a string vacío
 * - elimina saltos de línea que rompen la lectura visual en Excel
 * - recorta espacios extra
 */
function csvValue(mixed $value): string
{
    $text = (string)($value ?? '');
    $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

$q          = trim((string)($_GET['q'] ?? ''));
$language   = trim((string)($_GET['language'] ?? ''));
$owner      = trim((string)($_GET['owner'] ?? ''));
$visibility = trim((string)($_GET['visibility'] ?? ''));
$license    = trim((string)($_GET['license'] ?? ''));
$topic      = trim((string)($_GET['topic'] ?? ''));
$branch     = trim((string)($_GET['branch'] ?? ''));
$starsMin   = (int)($_GET['stars_min'] ?? ($_GET['stars'] ?? 0));
$starsMax   = (int)($_GET['stars_max'] ?? 0);
$forksMin   = (int)($_GET['forks_min'] ?? 0);
$sizeMin    = (int)($_GET['size_min'] ?? 0);
$sizeMax    = (int)($_GET['size_max'] ?? 0);
$dateFrom   = trim((string)($_GET['date_from'] ?? ''));
$dateTo     = trim((string)($_GET['date_to'] ?? ''));
$archived   = $_GET['archived'] ?? '';
$hasIssues  = $_GET['has_issues'] ?? '';
$hasWiki    = $_GET['has_wiki'] ?? '';
$orderBy    = $_GET['order_by'] ?? 'created_at';
$orderDir   = strtolower((string)($_GET['order_dir'] ?? 'asc'));

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

if (!in_array($orderBy, $allowedOrder, true)) {
    $orderBy = 'created_at';
}

if (!in_array($orderDir, ['asc', 'desc'], true)) {
    $orderDir = 'asc';
}

/*
|--------------------------------------------------------------------------
| Importante:
| Seleccionamos solo las columnas útiles para exportación.
| Excluimos query_source y raw_json para que no “ensucien” el CSV.
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        id,
        github_id,
        node_id,
        name,
        full_name,
        owner_login,
        description,
        html_url,
        api_url,
        homepage,
        language,
        stargazers_count,
        watchers_count,
        watchers,
        forks_count,
        forks,
        open_issues_count,
        open_issues,
        created_at,
        updated_at,
        pushed_at,
        topics,
        visibility,
        default_branch,
        size_kb,
        score,
        private_repo,
        archived,
        disabled,
        is_template,
        has_issues,
        has_projects,
        has_downloads,
        has_wiki,
        has_pages,
        has_discussions,
        license_key,
        license_name,
        fecha_extraccion
    FROM repositorios
    WHERE 1=1
";

$params = [];

if ($q !== '') {
    $sql .= " AND (
        name LIKE :q
        OR full_name LIKE :q
        OR owner_login LIKE :q
        OR description LIKE :q
    )";
    $params[':q'] = "%$q%";
}

if ($language !== '') {
    $sql .= " AND language LIKE :language";
    $params[':language'] = "%$language%";
}

if ($owner !== '') {
    $sql .= " AND owner_login LIKE :owner";
    $params[':owner'] = "%$owner%";
}

if ($visibility !== '') {
    $sql .= " AND visibility = :visibility";
    $params[':visibility'] = $visibility;
}

if ($license !== '') {
    $sql .= " AND license_name LIKE :license";
    $params[':license'] = "%$license%";
}

if ($topic !== '') {
    $sql .= " AND topics LIKE :topic";
    $params[':topic'] = "%$topic%";
}

if ($branch !== '') {
    $sql .= " AND default_branch LIKE :branch";
    $params[':branch'] = "%$branch%";
}

if ($starsMin > 0) {
    $sql .= " AND stargazers_count >= :starsMin";
    $params[':starsMin'] = $starsMin;
}

if ($starsMax > 0) {
    $sql .= " AND stargazers_count <= :starsMax";
    $params[':starsMax'] = $starsMax;
}

if ($forksMin > 0) {
    $sql .= " AND forks_count >= :forksMin";
    $params[':forksMin'] = $forksMin;
}

if ($sizeMin > 0) {
    $sql .= " AND size_kb >= :sizeMin";
    $params[':sizeMin'] = $sizeMin;
}

if ($sizeMax > 0) {
    $sql .= " AND size_kb <= :sizeMax";
    $params[':sizeMax'] = $sizeMax;
}

if ($dateFrom !== '') {
    $sql .= " AND DATE(created_at) >= :dateFrom";
    $params[':dateFrom'] = $dateFrom;
}

if ($dateTo !== '') {
    $sql .= " AND DATE(created_at) <= :dateTo";
    $params[':dateTo'] = $dateTo;
}

if ($archived !== '') {
    $sql .= " AND archived = :archived";
    $params[':archived'] = (int)$archived;
}

if ($hasIssues !== '') {
    $sql .= " AND has_issues = :hasIssues";
    $params[':hasIssues'] = (int)$hasIssues;
}

if ($hasWiki !== '') {
    $sql .= " AND has_wiki = :hasWiki";
    $params[':hasWiki'] = (int)$hasWiki;
}

$sql .= " ORDER BY $orderBy $orderDir";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$repos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = 'repositorios_mcp_' . date('Ymd_His') . '.csv';

/*
|--------------------------------------------------------------------------
| Limpiar cualquier salida previa antes de enviar headers
|--------------------------------------------------------------------------
*/
if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

/*
|--------------------------------------------------------------------------
| BOM UTF-8 para que Excel abra bien los acentos
|--------------------------------------------------------------------------
*/
fwrite($output, "\xEF\xBB\xBF");

/*
|--------------------------------------------------------------------------
| Encabezados
|--------------------------------------------------------------------------
*/
fputcsv($output, [
    'N°',
    'ID interno',
    'GitHub ID',
    'Node ID',
    'Nombre',
    'Full Name',
    'Owner',
    'Descripción',
    'HTML URL',
    'API URL',
    'Homepage',
    'Lenguaje',
    'Stars',
    'Watchers Count',
    'Watchers',
    'Forks Count',
    'Forks',
    'Open Issues Count',
    'Open Issues',
    'Fecha creación',
    'Fecha actualización',
    'Fecha último push',
    'Topics',
    'Visibilidad',
    'Default Branch',
    'Tamaño KB',
    'Score',
    'Privado',
    'Archivado',
    'Disabled',
    'Is Template',
    'Has Issues',
    'Has Projects',
    'Has Downloads',
    'Has Wiki',
    'Has Pages',
    'Has Discussions',
    'License Key',
    'License Name',
    'Fecha extracción'
], ';');

/*
|--------------------------------------------------------------------------
| Registros
|--------------------------------------------------------------------------
*/
foreach ($repos as $index => $repo) {
    $topicsText = '';

    if (!empty($repo['topics'])) {
        $decoded = json_decode((string)$repo['topics'], true);

        if (is_array($decoded)) {
            $topicsText = implode(', ', $decoded);
        } else {
            $topicsText = (string)$repo['topics'];
        }
    }

    fputcsv($output, [
        $index + 1,
        $repo['id'] ?? '',
        $repo['github_id'] ?? '',
        csvValue($repo['node_id'] ?? ''),
        csvValue($repo['name'] ?? ''),
        csvValue($repo['full_name'] ?? ''),
        csvValue($repo['owner_login'] ?? ''),
        csvValue($repo['description'] ?? ''),
        csvValue($repo['html_url'] ?? ''),
        csvValue($repo['api_url'] ?? ''),
        csvValue($repo['homepage'] ?? ''),
        csvValue($repo['language'] ?? ''),
        $repo['stargazers_count'] ?? 0,
        $repo['watchers_count'] ?? 0,
        $repo['watchers'] ?? 0,
        $repo['forks_count'] ?? 0,
        $repo['forks'] ?? 0,
        $repo['open_issues_count'] ?? 0,
        $repo['open_issues'] ?? 0,
        csvValue($repo['created_at'] ?? ''),
        csvValue($repo['updated_at'] ?? ''),
        csvValue($repo['pushed_at'] ?? ''),
        csvValue($topicsText),
        csvValue($repo['visibility'] ?? ''),
        csvValue($repo['default_branch'] ?? ''),
        $repo['size_kb'] ?? '',
        csvValue((string)($repo['score'] ?? '')),
        $repo['private_repo'] ?? 0,
        $repo['archived'] ?? 0,
        $repo['disabled'] ?? 0,
        $repo['is_template'] ?? 0,
        $repo['has_issues'] ?? 0,
        $repo['has_projects'] ?? 0,
        $repo['has_downloads'] ?? 0,
        $repo['has_wiki'] ?? 0,
        $repo['has_pages'] ?? 0,
        $repo['has_discussions'] ?? 0,
        csvValue($repo['license_key'] ?? ''),
        csvValue($repo['license_name'] ?? ''),
        csvValue($repo['fecha_extraccion'] ?? '')
    ], ';');
}

fclose($output);
exit;