<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

$desde = $_POST['desde'] ?? '2024-11-01';
$hasta = $_POST['hasta'] ?? '2026-02-28';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
    die('Formato de fecha inválido.');
}

if ($desde > $hasta) {
    die('La fecha desde no puede ser mayor que la fecha hasta.');
}

/**
 * Convierte fecha ISO de GitHub a DATETIME de MySQL
 */
function githubDateToMysql(?string $value): ?string
{
    if (empty($value)) {
        return null;
    }

    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Devuelve una lista de rangos mensuales entre dos fechas
 */
function getMonthlyRanges(string $desde, string $hasta): array
{
    $ranges = [];

    $start = new DateTime($desde);
    $end   = new DateTime($hasta);

    $current = new DateTime($start->format('Y-m-01'));

    while ($current <= $end) {
        $monthStart = clone $current;
        $monthEnd   = clone $current;
        $monthEnd->modify('last day of this month');

        if ($monthStart < $start) {
            $monthStart = clone $start;
        }

        if ($monthEnd > $end) {
            $monthEnd = clone $end;
        }

        $ranges[] = [
            'desde' => $monthStart->format('Y-m-d'),
            'hasta' => $monthEnd->format('Y-m-d'),
        ];

        $current->modify('first day of next month');
    }

    return $ranges;
}

/**
 * Hace una request a GitHub
 */
function githubRequest(string $url, string $githubToken = ''): array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: MCP-Explorer',
    ];

    if (!empty($githubToken)) {
        $headers[] = 'Authorization: Bearer ' . $githubToken;
    }

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);

    curl_close($ch);

    if ($response === false || $error) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'error' => $error ?: 'Error desconocido en cURL',
            'data' => null,
        ];
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'error' => $data['message'] ?? 'GitHub devolvió un error',
            'data' => $data,
        ];
    }

    return [
        'ok' => true,
        'http_code' => $httpCode,
        'error' => null,
        'data' => $data,
    ];
}

/**
 * Inserta un repositorio
 */
function insertRepo(PDO $pdo, array $repo, string $querySource): int
{
    $sql = "INSERT IGNORE INTO repositorios (
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
                forks_count,
                forks,
                open_issues_count,
                open_issues,
                watchers,
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
                query_source,
                raw_json,
                fecha_extraccion
            ) VALUES (
                :github_id,
                :node_id,
                :name,
                :full_name,
                :owner_login,
                :description,
                :html_url,
                :api_url,
                :homepage,
                :language,
                :stargazers_count,
                :watchers_count,
                :forks_count,
                :forks,
                :open_issues_count,
                :open_issues,
                :watchers,
                :created_at,
                :updated_at,
                :pushed_at,
                :topics,
                :visibility,
                :default_branch,
                :size_kb,
                :score,
                :private_repo,
                :archived,
                :disabled,
                :is_template,
                :has_issues,
                :has_projects,
                :has_downloads,
                :has_wiki,
                :has_pages,
                :has_discussions,
                :license_key,
                :license_name,
                :query_source,
                :raw_json,
                NOW()
            )";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':github_id'         => $repo['id'] ?? null,
        ':node_id'           => $repo['node_id'] ?? null,
        ':name'              => $repo['name'] ?? null,
        ':full_name'         => $repo['full_name'] ?? null,
        ':owner_login'       => $repo['owner']['login'] ?? null,
        ':description'       => $repo['description'] ?? null,
        ':html_url'          => $repo['html_url'] ?? null,
        ':api_url'           => $repo['url'] ?? null,
        ':homepage'          => $repo['homepage'] ?? null,
        ':language'          => $repo['language'] ?? null,
        ':stargazers_count'  => $repo['stargazers_count'] ?? 0,
        ':watchers_count'    => $repo['watchers_count'] ?? 0,
        ':forks_count'       => $repo['forks_count'] ?? 0,
        ':forks'             => $repo['forks'] ?? 0,
        ':open_issues_count' => $repo['open_issues_count'] ?? 0,
        ':open_issues'       => $repo['open_issues'] ?? 0,
        ':watchers'          => $repo['watchers'] ?? 0,
        ':created_at'        => githubDateToMysql($repo['created_at'] ?? null),
        ':updated_at'        => githubDateToMysql($repo['updated_at'] ?? null),
        ':pushed_at'         => githubDateToMysql($repo['pushed_at'] ?? null),
        ':topics'            => !empty($repo['topics']) ? json_encode($repo['topics'], JSON_UNESCAPED_UNICODE) : null,
        ':visibility'        => $repo['visibility'] ?? null,
        ':default_branch'    => $repo['default_branch'] ?? null,
        ':size_kb'           => $repo['size'] ?? null,
        ':score'             => $repo['score'] ?? null,
        ':private_repo'      => !empty($repo['private']) ? 1 : 0,
        ':archived'          => !empty($repo['archived']) ? 1 : 0,
        ':disabled'          => !empty($repo['disabled']) ? 1 : 0,
        ':is_template'       => !empty($repo['is_template']) ? 1 : 0,
        ':has_issues'        => !empty($repo['has_issues']) ? 1 : 0,
        ':has_projects'      => !empty($repo['has_projects']) ? 1 : 0,
        ':has_downloads'     => !empty($repo['has_downloads']) ? 1 : 0,
        ':has_wiki'          => !empty($repo['has_wiki']) ? 1 : 0,
        ':has_pages'         => !empty($repo['has_pages']) ? 1 : 0,
        ':has_discussions'   => !empty($repo['has_discussions']) ? 1 : 0,
        ':license_key'       => $repo['license']['key'] ?? null,
        ':license_name'      => $repo['license']['name'] ?? null,
        ':query_source'      => $querySource,
        ':raw_json'          => json_encode($repo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    ]);

    return $stmt->rowCount(); // 1 insertado, 0 duplicado
}

/*
|---------------------------------------------------------
| Búsquedas MCP
|---------------------------------------------------------
*/
$searchTemplates = [
    'topic:mcp is:public created:%s..%s',
    'topic:model-context-protocol is:public created:%s..%s',
    '"model context protocol" is:public created:%s..%s',
    'mcp in:name,description,readme is:public created:%s..%s',
];

$ranges = getMonthlyRanges($desde, $hasta);

$totalRequests   = 0;
$totalReposRaw   = 0;
$totalInsertados = 0;
$totalDuplicados = 0;
$errores         = [];
$detalle         = [];

foreach ($ranges as $range) {
    $mesDesde = $range['desde'];
    $mesHasta = $range['hasta'];

    foreach ($searchTemplates as $template) {
        $query = sprintf($template, $mesDesde, $mesHasta);

        $monthQueryStats = [
            'query' => $query,
            'requests' => 0,
            'raw' => 0,
            'insertados' => 0,
            'duplicados' => 0,
        ];

        for ($page = 1; $page <= 10; $page++) {
            $url = 'https://api.github.com/search/repositories?q='
                . urlencode($query)
                . '&sort=created&order=asc&per_page=100&page=' . $page;

            $result = githubRequest($url, $GITHUB_TOKEN ?? '');

            $totalRequests++;
            $monthQueryStats['requests']++;

            if (!$result['ok']) {
                $errores[] = 'Error en query [' . $query . '] página ' . $page . ': ' . $result['error'];
                break;
            }

            $items = $result['data']['items'] ?? [];

            if (empty($items)) {
                break;
            }

            $countItems = count($items);
            $totalReposRaw += $countItems;
            $monthQueryStats['raw'] += $countItems;

            foreach ($items as $repo) {
                $inserted = insertRepo($pdo, $repo, $query);

                if ($inserted === 1) {
                    $totalInsertados++;
                    $monthQueryStats['insertados']++;
                } else {
                    $totalDuplicados++;
                    $monthQueryStats['duplicados']++;
                }
            }

            if ($countItems < 100) {
                break;
            }

            usleep(400000);
        }

        $detalle[] = $monthQueryStats;
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resultado de importación MCP</title>
    <style>
        body{
            font-family: Arial, Helvetica, sans-serif;
            margin: 40px;
            background: #f5f6fa;
            color: #222;
        }
        .container{
            max-width: 1200px;
            margin: auto;
        }
        .card{
            background: #fff;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
            margin-bottom: 20px;
        }
        h1,h2{
            color: #232d67;
        }
        table{
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        th, td{
            border-bottom: 1px solid #e5e7eb;
            padding: 10px;
            text-align: left;
            vertical-align: top;
            font-size: 14px;
        }
        th{
            background: #232d67;
            color: #fff;
        }
        .ok{
            color: #0f766e;
            font-weight: bold;
        }
        .warn{
            color: #b45309;
            font-weight: bold;
        }
        .err{
            color: #b91c1c;
        }
        a.btn{
            display: inline-block;
            background: #232d67;
            color: #fff;
            padding: 10px 16px;
            border-radius: 8px;
            text-decoration: none;
        }
        code{
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 6px;
        }
    </style>
</head>
<body>
<div class="container">

    <div class="card">
        <h1>Importación finalizada</h1>

        <p><strong>Período:</strong> <?= htmlspecialchars($desde) ?> a <?= htmlspecialchars($hasta) ?></p>
        <p><strong>Total de requests realizadas:</strong> <?= $totalRequests ?></p>
        <p><strong>Total de resultados crudos recibidos:</strong> <?= $totalReposRaw ?></p>
        <p class="ok"><strong>Repositorios insertados:</strong> <?= $totalInsertados ?></p>
        <p class="warn"><strong>Duplicados ignorados:</strong> <?= $totalDuplicados ?></p>

        <p>
            <a class="btn" href="../index.php">Volver al inicio</a>
        </p>
    </div>

    <div class="card">
        <h2>Detalle por consulta</h2>

        <table>
            <tr>
                <th>Consulta</th>
                <th>Requests</th>
                <th>Resultados crudos</th>
                <th>Insertados</th>
                <th>Duplicados</th>
            </tr>
            <?php foreach ($detalle as $row): ?>
                <tr>
                    <td><code><?= htmlspecialchars($row['query']) ?></code></td>
                    <td><?= (int)$row['requests'] ?></td>
                    <td><?= (int)$row['raw'] ?></td>
                    <td><?= (int)$row['insertados'] ?></td>
                    <td><?= (int)$row['duplicados'] ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php if (!empty($errores)): ?>
        <div class="card">
            <h2 class="err">Errores encontrados</h2>
            <ul>
                <?php foreach ($errores as $error): ?>
                    <li class="err"><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Nota importante</h2>
        <p>
            Si en algún mes hubo muchísimos resultados, puede convenirte dividir ese mes en dos mitades
            para revisar con más control: del 1 al 15 y del 16 al fin de mes.
        </p>
    </div>

</div>
</body>
</html>