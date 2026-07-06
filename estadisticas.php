<?php
/**
 * MCP Explorer
 *
 * Este módulo obtiene y calcula las estadísticas utilizadas en el análisis
 * de repositorios públicos relacionados con el Model Context Protocol (MCP).
 * Los indicadores calculados se emplean tanto para la visualización de resultados
 * como para el análisis desarrollado en la tesina.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

function safeFloat($value): float
{
    return $value !== null ? (float) $value : 0.0;
}

function safeInt($value): int
{
    return $value !== null ? (int) $value : 0;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Fórmulas propuestas para repositorios jóvenes MCP
|--------------------------------------------------------------------------
*/

$daysSincePushSql = "DATEDIFF(NOW(), COALESCE(pushed_at, created_at))";

$recentActivitySql = "
    (1 / (1 + (($daysSincePushSql) / 30)))
";

$recentActivityScoreSql = "
    (10 / (1 + (($daysSincePushSql) / 30)))
";

$issueHealthRawSql = "
    (1 - LEAST(1, open_issues_count / ((stargazers_count / 20) + 1)))
";

$issueHealthScoreSql = "
    (10 * $issueHealthRawSql)
";

$ageMonthsSql = "
    GREATEST(TIMESTAMPDIFF(MONTH, created_at, NOW()), 1)
";

$starsPerMonthSql = "
    (stargazers_count / $ageMonthsSql)
";

$raviJovenSql = "
    (
        (LOG10(stargazers_count + 1) * 3)
        +
        (LOG10(forks_count + 1) * 2)
        +
        ($recentActivityScoreSql * 4)
        +
        ($issueHealthScoreSql * 1)
    )
";

$adoptionScoreJovenSql = "
    (
        0.40 * LOG10(stargazers_count + 1)
        +
        0.30 * ($recentActivitySql)
        +
        0.20 * LOG10(forks_count + 1)
        +
        0.10 * ($issueHealthRawSql)
    )
";

$adopcionMcpSql = "
    (
        0.6 * (($starsPerMonthSql) / 100)
        +
        0.4 * ($recentActivitySql)
    )
";

/*
|--------------------------------------------------------------------------
| Indicadores generales
|--------------------------------------------------------------------------
*/
$sqlGeneral = "
    SELECT
        COUNT(*) AS total_repos,
        SUM(stargazers_count) AS total_stars,
        AVG(stargazers_count) AS avg_stars,
        MAX(stargazers_count) AS max_stars,
        SUM(forks_count) AS total_forks,
        AVG(forks_count) AS avg_forks,
        AVG(open_issues_count) AS avg_open_issues,
        SUM(CASE WHEN stargazers_count > 0 THEN 1 ELSE 0 END) AS repos_with_stars,
        SUM(CASE WHEN forks_count > 0 THEN 1 ELSE 0 END) AS repos_with_forks,
        SUM(CASE WHEN archived = 1 THEN 1 ELSE 0 END) AS archived_count,
        SUM(CASE WHEN language IS NULL OR language = '' THEN 1 ELSE 0 END) AS no_language_count,
        AVG(CASE WHEN created_at IS NOT NULL THEN $starsPerMonthSql ELSE NULL END) AS avg_stars_per_month,
        AVG(CASE WHEN created_at IS NOT NULL THEN $raviJovenSql ELSE NULL END) AS avg_ravi_joven,
        AVG(CASE WHEN created_at IS NOT NULL THEN $adoptionScoreJovenSql ELSE NULL END) AS avg_adoption_score_joven,
        AVG(CASE WHEN created_at IS NOT NULL THEN $adopcionMcpSql ELSE NULL END) AS avg_adopcion_mcp
    FROM repositorios
";
$general = $pdo->query($sqlGeneral)->fetch(PDO::FETCH_ASSOC);

$totalRepos = safeInt($general['total_repos'] ?? 0);
$totalStars = safeInt($general['total_stars'] ?? 0);
$avgStars = round(safeFloat($general['avg_stars'] ?? 0), 2);
$maxStars = safeInt($general['max_stars'] ?? 0);
$totalForks = safeInt($general['total_forks'] ?? 0);
$avgForks = round(safeFloat($general['avg_forks'] ?? 0), 2);
$avgOpenIssues = round(safeFloat($general['avg_open_issues'] ?? 0), 2);
$reposWithStars = safeInt($general['repos_with_stars'] ?? 0);
$reposWithForks = safeInt($general['repos_with_forks'] ?? 0);
$archivedCount = safeInt($general['archived_count'] ?? 0);
$noLanguageCount = safeInt($general['no_language_count'] ?? 0);
$avgStarsPerMonth = round(safeFloat($general['avg_stars_per_month'] ?? 0), 2);
$avgRaviJoven = round(safeFloat($general['avg_ravi_joven'] ?? 0), 2);
$avgAdoptionScoreJoven = round(safeFloat($general['avg_adoption_score_joven'] ?? 0), 3);
$avgAdopcionMcp = round(safeFloat($general['avg_adopcion_mcp'] ?? 0), 3);

$withStarsPct = $totalRepos > 0 ? round(($reposWithStars / $totalRepos) * 100, 2) : 0;
$withForksPct = $totalRepos > 0 ? round(($reposWithForks / $totalRepos) * 100, 2) : 0;
$archivedPct = $totalRepos > 0 ? round(($archivedCount / $totalRepos) * 100, 2) : 0;
$noLanguagePct = $totalRepos > 0 ? round(($noLanguageCount / $totalRepos) * 100, 2) : 0;

/*
|--------------------------------------------------------------------------
| Distribución por estrellas
|--------------------------------------------------------------------------
*/
$sqlStarsRange = "
    SELECT
        CASE
            WHEN stargazers_count = 0 THEN '0'
            WHEN stargazers_count BETWEEN 1 AND 10 THEN '1-10'
            WHEN stargazers_count BETWEEN 11 AND 50 THEN '11-50'
            WHEN stargazers_count BETWEEN 51 AND 100 THEN '51-100'
            WHEN stargazers_count BETWEEN 101 AND 500 THEN '101-500'
            ELSE '500+'
        END AS rango,
        COUNT(*) AS cantidad
    FROM repositorios
    GROUP BY rango
";
$starsRangeRows = $pdo->query($sqlStarsRange)->fetchAll(PDO::FETCH_ASSOC);
$starsOrder = ['0', '1-10', '11-50', '51-100', '101-500', '500+'];
$starsMap = array_fill_keys($starsOrder, 0);
foreach ($starsRangeRows as $row) {
    $starsMap[$row['rango']] = safeInt($row['cantidad']);
}
$starsLabels = array_keys($starsMap);
$starsValues = array_values($starsMap);

/*
|--------------------------------------------------------------------------
| Lenguajes más utilizados
|--------------------------------------------------------------------------
*/
$sqlLanguages = "
    SELECT
        COALESCE(NULLIF(language, ''), 'Sin especificar') AS language_name,
        COUNT(*) AS total
    FROM repositorios
    GROUP BY language_name
    ORDER BY total DESC
    LIMIT 10
";
$languageRows = $pdo->query($sqlLanguages)->fetchAll(PDO::FETCH_ASSOC);
$langLabels = [];
$langValues = [];
foreach ($languageRows as $row) {
    $langLabels[] = $row['language_name'];
    $langValues[] = safeInt($row['total']);
}

/*
|--------------------------------------------------------------------------
| Evolución temporal
|--------------------------------------------------------------------------
*/
$sqlByMonth = "
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS mes,
        COUNT(*) AS total
    FROM repositorios
    WHERE created_at IS NOT NULL
    GROUP BY mes
    ORDER BY mes ASC
";
$monthRows = $pdo->query($sqlByMonth)->fetchAll(PDO::FETCH_ASSOC);
$monthLabels = [];
$monthValues = [];
foreach ($monthRows as $row) {
    $monthLabels[] = $row['mes'];
    $monthValues[] = safeInt($row['total']);
}

$sqlStarsByMonth = "
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS mes,
        SUM(stargazers_count) AS total_stars
    FROM repositorios
    WHERE created_at IS NOT NULL
    GROUP BY mes
    ORDER BY mes ASC
";
$starsByMonthRows = $pdo->query($sqlStarsByMonth)->fetchAll(PDO::FETCH_ASSOC);
$starsMonthLabels = [];
$starsMonthValues = [];
foreach ($starsByMonthRows as $row) {
    $starsMonthLabels[] = $row['mes'];
    $starsMonthValues[] = safeInt($row['total_stars']);
}

/*
|--------------------------------------------------------------------------
| Top por popularidad
|--------------------------------------------------------------------------
*/
$sqlTopStars = "
    SELECT name, owner_login, stargazers_count, forks_count, open_issues_count, pushed_at, html_url
    FROM repositorios
    ORDER BY stargazers_count DESC, forks_count DESC
    LIMIT 15
";
$topStars = $pdo->query($sqlTopStars)->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| RAVI joven
|--------------------------------------------------------------------------
*/
$sqlRavi = "
    SELECT
        name,
        owner_login,
        stargazers_count,
        forks_count,
        open_issues_count,
        created_at,
        pushed_at,
        html_url,
        $daysSincePushSql AS dias_desde_push,
        $recentActivityScoreSql AS recent_activity_score,
        $issueHealthScoreSql AS issue_health_score,
        $raviJovenSql AS ravi_joven
    FROM repositorios
    WHERE created_at IS NOT NULL
    ORDER BY ravi_joven DESC
    LIMIT 15
";
$raviRows = $pdo->query($sqlRavi)->fetchAll(PDO::FETCH_ASSOC);

$raviAlta = 0;
$raviMedia = 0;
$raviBaja = 0;
$sqlRaviDistribution = "SELECT $raviJovenSql AS ravi_joven FROM repositorios WHERE created_at IS NOT NULL";
$raviAll = $pdo->query($sqlRaviDistribution)->fetchAll(PDO::FETCH_ASSOC);
foreach ($raviAll as $row) {
    $r = safeFloat($row['ravi_joven']);
    if ($r > 28) {
        $raviAlta++;
    } elseif ($r >= 12) {
        $raviMedia++;
    } else {
        $raviBaja++;
    }
}
$raviTotal = $raviAlta + $raviMedia + $raviBaja;
$raviAltaPct = $raviTotal > 0 ? round(($raviAlta / $raviTotal) * 100, 2) : 0;
$raviMediaPct = $raviTotal > 0 ? round(($raviMedia / $raviTotal) * 100, 2) : 0;
$raviBajaPct = $raviTotal > 0 ? round(($raviBaja / $raviTotal) * 100, 2) : 0;

/*
|--------------------------------------------------------------------------
| Adoption Score joven
|--------------------------------------------------------------------------
*/
$adoptionScoreAlta = 0;
$adoptionScoreMedia = 0;
$adoptionScoreBaja = 0;
$sqlAdoptionScoreDistribution = "SELECT $adoptionScoreJovenSql AS adoption_score_joven FROM repositorios WHERE created_at IS NOT NULL";
$adoptionScoreAll = $pdo->query($sqlAdoptionScoreDistribution)->fetchAll(PDO::FETCH_ASSOC);
foreach ($adoptionScoreAll as $row) {
    $score = safeFloat($row['adoption_score_joven']);
    if ($score > 3) {
        $adoptionScoreAlta++;
    } elseif ($score >= 1.5) {
        $adoptionScoreMedia++;
    } else {
        $adoptionScoreBaja++;
    }
}
$adoptionScoreTotal = $adoptionScoreAlta + $adoptionScoreMedia + $adoptionScoreBaja;
$adoptionScoreAltaPct = $adoptionScoreTotal > 0 ? round(($adoptionScoreAlta / $adoptionScoreTotal) * 100, 2) : 0;
$adoptionScoreMediaPct = $adoptionScoreTotal > 0 ? round(($adoptionScoreMedia / $adoptionScoreTotal) * 100, 2) : 0;
$adoptionScoreBajaPct = $adoptionScoreTotal > 0 ? round(($adoptionScoreBaja / $adoptionScoreTotal) * 100, 2) : 0;

/*
|--------------------------------------------------------------------------
| Stars por mes
|--------------------------------------------------------------------------
*/
$sqlStarsPerMonth = "
    SELECT
        name,
        owner_login,
        stargazers_count,
        forks_count,
        created_at,
        pushed_at,
        html_url,
        $ageMonthsSql AS edad_meses,
        $starsPerMonthSql AS stars_per_month
    FROM repositorios
    WHERE created_at IS NOT NULL
    ORDER BY stars_per_month DESC, stargazers_count DESC
    LIMIT 15
";
$starsPerMonthRows = $pdo->query($sqlStarsPerMonth)->fetchAll(PDO::FETCH_ASSOC);

$spmAlta = 0;
$spmMedia = 0;
$spmBaja = 0;
$sqlStarsPerMonthDistribution = "SELECT $starsPerMonthSql AS stars_per_month FROM repositorios WHERE created_at IS NOT NULL";
$spmAll = $pdo->query($sqlStarsPerMonthDistribution)->fetchAll(PDO::FETCH_ASSOC);
foreach ($spmAll as $row) {
    $spm = safeFloat($row['stars_per_month']);
    if ($spm > 100) {
        $spmAlta++;
    } elseif ($spm >= 20) {
        $spmMedia++;
    } else {
        $spmBaja++;
    }
}
$spmTotal = $spmAlta + $spmMedia + $spmBaja;
$spmAltaPct = $spmTotal > 0 ? round(($spmAlta / $spmTotal) * 100, 2) : 0;
$spmMediaPct = $spmTotal > 0 ? round(($spmMedia / $spmTotal) * 100, 2) : 0;
$spmBajaPct = $spmTotal > 0 ? round(($spmBaja / $spmTotal) * 100, 2) : 0;

/*
|--------------------------------------------------------------------------
| Adopción MCP
|--------------------------------------------------------------------------
*/
$sqlAdopcionMcp = "
    SELECT
        name,
        owner_login,
        stargazers_count,
        forks_count,
        open_issues_count,
        created_at,
        pushed_at,
        html_url,
        $daysSincePushSql AS dias_desde_push,
        $ageMonthsSql AS edad_meses,
        $starsPerMonthSql AS stars_per_month,
        $recentActivitySql AS actividad_reciente,
        $adopcionMcpSql AS adopcion_mcp
    FROM repositorios
    WHERE created_at IS NOT NULL
    ORDER BY adopcion_mcp DESC, stars_per_month DESC
    LIMIT 15
";
$adopcionRows = $pdo->query($sqlAdopcionMcp)->fetchAll(PDO::FETCH_ASSOC);

$adopcionAlta = 0;
$adopcionMedia = 0;
$adopcionBaja = 0;
$sqlAdopcionDistribution = "SELECT $adopcionMcpSql AS adopcion_mcp FROM repositorios WHERE created_at IS NOT NULL";
$adopcionAll = $pdo->query($sqlAdopcionDistribution)->fetchAll(PDO::FETCH_ASSOC);
foreach ($adopcionAll as $row) {
    $score = safeFloat($row['adopcion_mcp']);
    if ($score > 0.7) {
        $adopcionAlta++;
    } elseif ($score >= 0.3) {
        $adopcionMedia++;
    } else {
        $adopcionBaja++;
    }
}
$adopcionTotal = $adopcionAlta + $adopcionMedia + $adopcionBaja;
$adopcionAltaPct = $adopcionTotal > 0 ? round(($adopcionAlta / $adopcionTotal) * 100, 2) : 0;
$adopcionMediaPct = $adopcionTotal > 0 ? round(($adopcionMedia / $adopcionTotal) * 100, 2) : 0;
$adopcionBajaPct = $adopcionTotal > 0 ? round(($adopcionBaja / $adopcionTotal) * 100, 2) : 0;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Estadísticas MCP Explorer</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; background: #f3f4f8; color: #1f2937; }
        .container { max-width: 1500px; margin: auto; padding: 28px; }
        h1 { color: #17235f; margin-bottom: 6px; font-size: 34px; }
        h2 { color: #17235f; margin-top: 0; margin-bottom: 10px; font-size: 24px; }
        h3 { color: #17235f; margin-bottom: 8px; font-size: 18px; }
        .subtitle { color: #5b6475; margin-bottom: 22px; font-size: 16px; line-height: 1.5; }
        .nav { margin-bottom: 18px; }
        .btn { display: inline-block; text-decoration: none; background: #232d67; color: white; padding: 10px 14px; border-radius: 8px; font-weight: 700; }
        .card { background: #fff; padding: 24px; border-radius: 16px; box-shadow: 0 4px 18px rgba(0,0,0,.07); margin-bottom: 22px; }
        .intro-card { border-left: 6px solid #232d67; }
        .question-tag { display: inline-block; background: #eef2ff; color: #232d67; font-weight: 700; padding: 5px 10px; border-radius: 999px; font-size: 12px; margin: 3px 4px 3px 0; }
        .grid-kpi { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
        .grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 22px; }
        .kpi { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px; }
        .kpi .label { font-size: 13px; color: #526078; margin-bottom: 8px; }
        .kpi .value { font-size: 28px; font-weight: 800; color: #17235f; }
        .kpi .sub { margin-top: 7px; font-size: 12px; color: #64748b; line-height: 1.35; }
        .explain { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px; margin-top: 14px; color: #475569; line-height: 1.55; font-size: 14px; }
        .formula { font-family: Consolas, monospace; background: #111827; color: #f9fafb; padding: 12px; border-radius: 10px; overflow-x: auto; font-size: 13px; }
        .chart-box { height: 340px; }
        .chart-box.small { height: 280px; }
        .table-wrap { overflow: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #232d67; color: #fff; text-align: left; padding: 10px; white-space: nowrap; }
        td { padding: 9px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        tr:hover td { background: #f9fafb; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 800; }
        .badge-high { background: #dcfce7; color: #166534; }
        .badge-mid { background: #fef3c7; color: #92400e; }
        .badge-low { background: #fee2e2; color: #991b1b; }
        .mini-list { margin: 8px 0 0 18px; padding: 0; }
        .mini-list li { margin: 5px 0; }
        .section-note { color: #475569; line-height: 1.55; margin-bottom: 16px; }
        @media (max-width: 1200px) { .grid-kpi { grid-template-columns: repeat(2, minmax(0, 1fr)); } .grid-2 { grid-template-columns: 1fr; } }
        @media (max-width: 700px) { .container { padding: 16px; } .grid-kpi { grid-template-columns: 1fr; } h1 { font-size: 28px; } }
    </style>
</head>
<body>
<div class="container">

    <h1>Estadísticas del ecosistema MCP</h1>
    <div class="subtitle">
        Panel de análisis cuantitativo para evaluar la adopción del Model Context Protocol como tecnología emergente a partir de repositorios públicos de GitHub.
    </div>

    <div class="nav">
        <a class="btn" href="index.php">Volver al explorador</a>
    </div>

    <div class="card intro-card">
        <h2>Lectura general del panel</h2>
        <p class="section-note">
            Este panel no busca mostrar métricas aisladas, sino responder las preguntas de investigación de la tesina. Cada bloque está asociado a una dimensión del análisis: identificación de repositorios, características técnicas, evolución temporal, popularidad, actividad, mantenimiento y vitalidad.
        </p>
        <span class="question-tag">PI1 Identificación</span>
        <span class="question-tag">PI2 Características y clasificación</span>
        <span class="question-tag">PI3 Actividad y mantenimiento</span>
        <span class="question-tag">PI4 Evolución y tendencias</span>
        <span class="question-tag">PI5 Popularidad, actividad y mantenimiento</span>
        <span class="question-tag">PI6 Popularidad vs. vitalidad RAVI</span>
    </div>

    <div class="card">
        <h2>1. Tamaño observable del ecosistema MCP</h2>
        <p class="section-note">
            Estos indicadores permiten dimensionar el volumen del ecosistema abierto identificado en GitHub. Responden principalmente a la PI1, porque muestran cuántos repositorios asociados al MCP fueron relevados y qué nivel general de atención recibieron.
        </p>
        <div class="grid-kpi">
            <div class="kpi">
                <div class="label">Total de repositorios identificados</div>
                <div class="value"><?= number_format($totalRepos, 0, ',', '.') ?></div>
                <div class="sub">Cantidad de proyectos públicos del dataset final.</div>
            </div>
            <div class="kpi">
                <div class="label">Estrellas totales</div>
                <div class="value"><?= number_format($totalStars, 0, ',', '.') ?></div>
                <div class="sub">Suma de señales de interés o popularidad recibidas por los repositorios.</div>
            </div>
            <div class="kpi">
                <div class="label">Forks totales</div>
                <div class="value"><?= number_format($totalForks, 0, ',', '.') ?></div>
                <div class="sub">Suma de copias o derivaciones realizadas por otros usuarios.</div>
            </div>
            <div class="kpi">
                <div class="label">Repositorios archivados</div>
                <div class="value"><?= number_format($archivedPct, 2, ',', '.') ?>%</div>
                <div class="sub">Porcentaje de proyectos marcados como no activos por sus propietarios.</div>
            </div>
        </div>
        <div class="explain">
            <strong>Interpretación:</strong> Los resultados obtenidos muestran la existencia de un volumen considerable de repositorios públicos asociados al MCP dentro de GitHub, con más de 20 mil proyectos identificados y más de 1,4 millones de estrellas acumuladas. La baja proporción de repositorios archivados (1,67%) indica que la mayoría de los proyectos no se encuentran formalmente discontinuados al momento de la recolección. Asimismo, la cantidad de forks registrada sugiere un nivel relevante de reutilización, replicación o experimentación sobre los repositorios analizados. En conjunto, estos indicadores evidencian una presencia observable del MCP dentro del ecosistema abierto de desarrollo de software.
        </div>
    </div>

    <div class="card">
        <h2>2. Popularidad y reutilización</h2>
        <p class="section-note">
            Este bloque responde a la PI5. Las estrellas permiten observar interés o visibilidad, mientras que los forks aproximan reutilización, experimentación o derivación técnica.
        </p>
        <div class="grid-kpi">
            <div class="kpi">
                <div class="label">Promedio de estrellas</div>
                <div class="value"><?= number_format($avgStars, 2, ',', '.') ?></div>
                <div class="sub">Promedio de popularidad por repositorio.</div>
            </div>
            <div class="kpi">
                <div class="label">Máximo de estrellas</div>
                <div class="value"><?= number_format($maxStars, 0, ',', '.') ?></div>
                <div class="sub">Valor del repositorio más visible del dataset.</div>
            </div>
            <div class="kpi">
                <div class="label">Repositorios con al menos una estrella</div>
                <div class="value"><?= number_format($withStarsPct, 2, ',', '.') ?>%</div>
                <div class="sub">Indica qué proporción recibió algún nivel de atención.</div>
            </div>
            <div class="kpi">
                <div class="label">Repositorios con al menos un fork</div>
                <div class="value"><?= number_format($withForksPct, 2, ',', '.') ?>%</div>
                <div class="sub">Indica qué proporción fue reutilizada o replicada.</div>
            </div>
        </div>
        <div class="grid-2" style="margin-top:20px;">
            <div>
                <h3>Distribución por rango de estrellas</h3>
                <div class="chart-box small"><canvas id="chartStars"></canvas></div>
            </div>
            <div class="explain">
                <strong>Interpretación:</strong>
                <ul class="mini-list">
                    <li>La distribución observada muestra una fuerte concentración de repositorios en rangos bajos de estrellas, especialmente entre 1 y 10 estrellas. A medida que aumenta el rango de popularidad, la cantidad de proyectos disminuye considerablemente, siendo reducida la proporción de repositorios que superan las 100 o 500 estrellas.</li>
                    <li>Estos resultados sugieren que el ecosistema MCP se encuentra conformado mayormente por proyectos pequeños o de alcance limitado, mientras que una fracción reducida concentra gran parte de la visibilidad y atención de la comunidad. En consecuencia, la popularidad dentro del ecosistema no se distribuye de manera homogénea, sino que presenta una concentración significativa en repositorios específicos.</li>

                </ul>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>3. Características técnicas del ecosistema</h2>
        <p class="section-note">
            Este bloque responde a la PI2 y a la PI4. Permite identificar qué lenguajes predominan en los proyectos MCP y, por lo tanto, en qué comunidades técnicas parece concentrarse su adopción.
        </p>
        <div class="grid-2">
            <div>
                <h3>Lenguajes más utilizados</h3>
                <div class="chart-box"><canvas id="chartLanguages"></canvas></div>
            </div>
            <div class="explain">
                <strong>Qué significa:</strong>
                <p>Los resultados muestran un claro predominio de Python y TypeScript dentro de los repositorios asociados al MCP, concentrando conjuntamente la mayor parte de los proyectos relevados. En menor proporción aparecen JavaScript y Go, mientras que otros lenguajes presentan una participación considerablemente más reducida.</p>
                <p>Esta distribución sugiere que el ecosistema MCP se encuentra fuertemente vinculado con entornos de desarrollo orientados a inteligencia artificial, automatización, herramientas para desarrolladores y aplicaciones web, ámbitos donde Python y TypeScript poseen una presencia ampliamente consolidada. Asimismo, la diversidad observada en menor escala indica que el MCP no se limita a un único stack tecnológico, sino que presenta capacidad de integración con distintos lenguajes y entornos de desarrollo.</p>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>4. Evolución temporal y tendencias</h2>
        <p class="section-note">
            Este bloque responde directamente a la PI4. Permite observar cómo evolucionó la creación de repositorios MCP y si el fenómeno muestra continuidad, crecimiento o concentración en períodos específicos.
        </p>
        <div class="grid-2">
            <div>
                <h3>Repositorios creados por mes</h3>
                <div class="chart-box"><canvas id="chartMonths"></canvas></div>
            </div>
            <div>
                <h3>Estrellas acumuladas por mes de creación</h3>
                <div class="chart-box"><canvas id="chartStarsByMonth"></canvas></div>
            </div>
        </div>
        <div class="explain">
            <strong>Interpretación:</strong> 

            Los resultados muestran un incremento en la cantidad de repositorios MCP creados mensualmente a partir de marzo de 2025, momento desde el cual el volumen de proyectos registrados se mantiene en valores considerablemente superiores a los observados durante los primeros meses del período analizado.

En cuanto a las estrellas acumuladas por mes de creación, se observan valores particularmente elevados en marzo y abril de 2025, indicando que los repositorios creados durante esos períodos concentraron una proporción importante de las estrellas registradas en el dataset.

En conjunto, ambos gráficos permiten observar que el crecimiento del ecosistema MCP no solo se manifestó en la cantidad de repositorios creados, sino también en el nivel de visibilidad alcanzado por proyectos desarrollados en determinados períodos temporales.

        </div>
    </div>

    <div class="card">
        <h2>5. Actividad, mantenimiento y vitalidad</h2>
        <p class="section-note">
            Este bloque responde a la PI3 y a la PI6. No mide solo popularidad, sino señales de continuidad técnica: actividad reciente, forks, issues y salud relativa del repositorio.
        </p>
        <div class="grid-kpi">
            <div class="kpi">
                <div class="label">Issues abiertos promedio</div>
                <div class="value"><?= number_format($avgOpenIssues, 2, ',', '.') ?></div>
                <div class="sub">Cantidad promedio de problemas, solicitudes o tareas abiertas.</div>
            </div>
            <div class="kpi">
                <div class="label">RAVI joven promedio</div>
                <div class="value"><?= number_format($avgRaviJoven, 2, ',', '.') ?></div>
                <div class="sub">Índice de vitalidad adaptado a repositorios MCP jóvenes.</div>
            </div>
            <div class="kpi">
                <div class="label">Adoption score joven promedio</div>
                <div class="value"><?= number_format($avgAdoptionScoreJoven, 3, ',', '.') ?></div>
                <div class="sub">Popularidad, actividad, forks y salud con pesos equilibrados.</div>
            </div>
            <div class="kpi">
                <div class="label">Stars por mes promedio</div>
                <div class="value"><?= number_format($avgStarsPerMonth, 2, ',', '.') ?></div>
                <div class="sub">Velocidad media de crecimiento de popularidad.</div>
            </div>
        </div>

        <div class="grid-2" style="margin-top:20px;">
            <div>
                <h3>Clasificación RAVI joven</h3>
                <div class="chart-box small"><canvas id="chartRavi"></canvas></div>
                <p class="section-note">
                    Baja: <?= $raviBaja ?> repositorios (<?= number_format($raviBajaPct, 2, ',', '.') ?>%)<br>
                    Media: <?= $raviMedia ?> repositorios (<?= number_format($raviMediaPct, 2, ',', '.') ?>%)<br>
                    Alta: <?= $raviAlta ?> repositorios (<?= number_format($raviAltaPct, 2, ',', '.') ?>%)
                </p>
            </div>
            <div class="explain">
                <strong>Fórmula RAVI joven:</strong>
                <div class="formula">RAVI_joven = log10(stars+1)*3 + log10(forks+1)*2 + RecentActivityScore*4 + IssueHealthScore*1</div>
                <p><strong>Qué mide:</strong> vitalidad general. Un valor alto indica que el repositorio combina popularidad, reutilización, actualización reciente y una proporción saludable de issues abiertos.</p>
                <ul class="mini-list">
                    <li><strong>Baja:</strong> menos de 12 puntos.</li>
                    <li><strong>Media:</strong> entre 12 y 28 puntos.</li>
                    <li><strong>Alta:</strong> más de 28 puntos.</li>
                </ul>
                <strong>Interpretación:</strong>
                Los resultados muestran una fuerte concentración de repositorios en la categoría media del índice RAVI joven, donde se ubica aproximadamente el 79% de los proyectos analizados. Por otro lado, los repositorios clasificados en la categoría alta representan una proporción reducida del dataset, mientras que cerca del 13% presenta valores bajos dentro del indicador.

Esta distribución indica que una parte importante de los repositorios MCP mantiene niveles intermedios de actividad reciente, forks y salud relativa de issues según los criterios definidos por el índice RAVI joven. Asimismo, los resultados permiten diferenciar repositorios con alta popularidad de aquellos que además presentan mayores niveles de actividad y mantenimiento.
            </div>
        </div>
    </div>

    <div class="card">
        <h2>6. Crecimiento mensual y adopción MCP</h2>
        <p class="section-note">
            Este bloque complementa la medición de adopción en tecnologías jóvenes. En lugar de observar solo estrellas acumuladas, calcula la velocidad de crecimiento en relación con la edad del repositorio.
        </p>
        <div class="grid-2">
            <div>
                <h3>Clasificación por estrellas por mes</h3>
                <div class="chart-box small"><canvas id="chartStarsPerMonth"></canvas></div>
                <p class="section-note">
                    Baja: <?= $spmBaja ?> repositorios (<?= number_format($spmBajaPct, 2, ',', '.') ?>%)<br>
                    Media: <?= $spmMedia ?> repositorios (<?= number_format($spmMediaPct, 2, ',', '.') ?>%)<br>
                    Alta: <?= $spmAlta ?> repositorios (<?= number_format($spmAltaPct, 2, ',', '.') ?>%)
                </p>
            </div>
            <div>
                <h3>Clasificación Adopción MCP</h3>
                <div class="chart-box small"><canvas id="chartAdopcionMcp"></canvas></div>
                <p class="section-note">
                    Baja: <?= $adopcionBaja ?> repositorios (<?= number_format($adopcionBajaPct, 2, ',', '.') ?>%)<br>
                    Media: <?= $adopcionMedia ?> repositorios (<?= number_format($adopcionMediaPct, 2, ',', '.') ?>%)<br>
                    Alta: <?= $adopcionAlta ?> repositorios (<?= number_format($adopcionAltaPct, 2, ',', '.') ?>%)
                </p>
            </div>
        </div>
        <div class="explain">
            <strong>Fórmula de Adopción MCP:</strong>
            <div class="formula">Adopción_MCP = 0.6 * (stars_per_month / 100) + 0.4 * (1 / (1 + días_desde_push/30))</div>
            <p><strong>Qué significa:</strong> este índice combina velocidad de crecimiento y actividad reciente. Si la mayoría aparece en adopción baja, no significa que MCP no se esté adoptando, sino que la mayor parte de los repositorios tiene crecimiento bajo o moderado en comparación con los casos líderes. En ecosistemas emergentes suele haber muchos proyectos pequeños y pocos repositorios con crecimiento acelerado.</p>
            <ul class="mini-list">
                <li><strong>Baja:</strong> menor a 0,3.</li>
                <li><strong>Media:</strong> entre 0,3 y 0,7.</li>
                <li><strong>Alta:</strong> mayor a 0,7.</li>
            </ul>
            <strong>Interpretación</strong>
            Los resultados muestran que la mayor parte de los repositorios del dataset presenta valores bajos tanto en crecimiento mensual de estrellas como en el índice de Adopción MCP. En ambos casos, más del 95% de los proyectos se concentra en la categoría baja, mientras que los repositorios clasificados con adopción alta representan una proporción reducida del total analizado.

Estos resultados indican que el crecimiento acelerado y la alta visibilidad se encuentran concentrados en un conjunto limitado de proyectos, mientras que la mayoría de los repositorios presenta niveles moderados o reducidos de crecimiento relativo. Esta distribución resulta consistente con ecosistemas emergentes, donde pocos proyectos concentran gran parte de la atención y actividad observable.
        </div>
    </div>

    <div class="card">
        <h2>7. Adoption score joven</h2>
        <p class="section-note">
            Este indicador resume popularidad, actividad reciente, reutilización y salud del repositorio en una escala complementaria. Es útil para observar adopción general cuando no se dispone de datos de contribuidores.
        </p>
        <div class="grid-2">
            <div>
                <h3>Clasificación adoption score joven</h3>
                <div class="chart-box small"><canvas id="chartAdoptionScore"></canvas></div>
                <p class="section-note">
                    Baja: <?= $adoptionScoreBaja ?> repositorios (<?= number_format($adoptionScoreBajaPct, 2, ',', '.') ?>%)<br>
                    Media: <?= $adoptionScoreMedia ?> repositorios (<?= number_format($adoptionScoreMediaPct, 2, ',', '.') ?>%)<br>
                    Alta: <?= $adoptionScoreAlta ?> repositorios (<?= number_format($adoptionScoreAltaPct, 2, ',', '.') ?>%)
                </p>
            </div>
            <div class="explain">
                <strong>Fórmula adoption score joven:</strong>
                <div class="formula">0.40*log10(stars+1) + 0.30*actividad_reciente + 0.20*log10(forks+1) + 0.10*salud_issues</div>
                <p><strong>Qué mide:</strong> una adopción equilibrada entre visibilidad, actividad y mantenimiento. A diferencia de stars_per_month, no prioriza tanto la velocidad de crecimiento, sino un balance general del repositorio.</p>
                <ul class="mini-list">
                    <li><strong>Baja:</strong> menor a 1,5.</li>
                    <li><strong>Media:</strong> entre 1,5 y 3,0.</li>
                    <li><strong>Alta:</strong> mayor a 3,0.</li>
                </ul>
                <strong>Interpretación</strong>
                Los resultados muestran una fuerte concentración de repositorios en la categoría baja del adoption score joven, donde se ubica más del 97% de los proyectos analizados. La categoría media representa una proporción considerablemente menor, mientras que no se registraron repositorios clasificados en la categoría alta según los umbrales establecidos para este indicador.

Esto sugiere que, bajo la fórmula aplicada, la mayoría de los repositorios MCP presenta niveles limitados de adopción equilibrada entre popularidad, actividad reciente, reutilización y salud del proyecto. Asimismo, los resultados evidencian una distribución altamente concentrada, donde solo un conjunto reducido de repositorios alcanza valores intermedios dentro del indicador.
            </div>
        </div>
    </div>

    <div class="card">
        <h2>8. Top 15 repositorios por popularidad</h2>
        <p class="section-note">Permite identificar los proyectos más visibles del ecosistema MCP según estrellas. Responde a PI5.</p>
        <div class="table-wrap">
            <table>
                <tr><th>N°</th><th>Repositorio</th><th>Owner</th><th>Stars</th><th>Forks</th><th>Open Issues</th><th>Último push</th><th>URL</th></tr>
                <?php foreach ($topStars as $i => $repo): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= e($repo['name']) ?></td>
                        <td><?= e($repo['owner_login']) ?></td>
                        <td><?= number_format((int)$repo['stargazers_count'], 0, ',', '.') ?></td>
                        <td><?= number_format((int)$repo['forks_count'], 0, ',', '.') ?></td>
                        <td><?= number_format((int)$repo['open_issues_count'], 0, ',', '.') ?></td>
                        <td><?= e($repo['pushed_at']) ?></td>
                        <td><a href="<?= e($repo['html_url']) ?>" target="_blank">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>9. Top 15 repositorios según RAVI joven</h2>
        <p class="section-note">Permite comparar popularidad con vitalidad. Responde especialmente a PI6.</p>
        <div class="table-wrap">
            <table>
                <tr><th>N°</th><th>Repositorio</th><th>Owner</th><th>Stars</th><th>Forks</th><th>Issues</th><th>Días desde push</th><th>RAVI</th><th>Clasificación</th><th>URL</th></tr>
                <?php foreach ($raviRows as $i => $repo): ?>
                    <?php
                    $ravi = round((float)$repo['ravi_joven'], 2);
                    if ($ravi > 28) { $label = 'Alta'; $css = 'badge badge-high'; }
                    elseif ($ravi >= 12) { $label = 'Media'; $css = 'badge badge-mid'; }
                    else { $label = 'Baja'; $css = 'badge badge-low'; }
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= e($repo['name']) ?></td>
                        <td><?= e($repo['owner_login']) ?></td>
                        <td><?= number_format((int)$repo['stargazers_count'], 0, ',', '.') ?></td>
                        <td><?= number_format((int)$repo['forks_count'], 0, ',', '.') ?></td>
                        <td><?= number_format((int)$repo['open_issues_count'], 0, ',', '.') ?></td>
                        <td><?= number_format((int)$repo['dias_desde_push'], 0, ',', '.') ?></td>
                        <td><?= number_format($ravi, 2, ',', '.') ?></td>
                        <td><span class="<?= $css ?>"><?= $label ?></span></td>
                        <td><a href="<?= e($repo['html_url']) ?>" target="_blank">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>10. Top 15 repositorios por velocidad de crecimiento</h2>
        <p class="section-note">Permite observar qué proyectos ganan popularidad más rápido en relación con su edad.</p>
        <div class="table-wrap">
            <table>
                <tr><th>N°</th><th>Repositorio</th><th>Owner</th><th>Stars</th><th>Forks</th><th>Edad meses</th><th>Stars/mes</th><th>Clasificación</th><th>URL</th></tr>
                <?php foreach ($starsPerMonthRows as $i => $repo): ?>
                    <?php
                    $spm = round((float)$repo['stars_per_month'], 2);
                    if ($spm > 100) { $label = 'Alta'; $css = 'badge badge-high'; }
                    elseif ($spm >= 20) { $label = 'Media'; $css = 'badge badge-mid'; }
                    else { $label = 'Baja'; $css = 'badge badge-low'; }
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= e($repo['name']) ?></td>
                        <td><?= e($repo['owner_login']) ?></td>
                        <td><?= number_format((int)$repo['stargazers_count'], 0, ',', '.') ?></td>
                        <td><?= number_format((int)$repo['forks_count'], 0, ',', '.') ?></td>
                        <td><?= number_format((int)$repo['edad_meses'], 0, ',', '.') ?></td>
                        <td><?= number_format($spm, 2, ',', '.') ?></td>
                        <td><span class="<?= $css ?>"><?= $label ?></span></td>
                        <td><a href="<?= e($repo['html_url']) ?>" target="_blank">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>11. Top 15 repositorios según Adopción MCP</h2>
        <p class="section-note">Combina crecimiento mensual y actividad reciente. Permite observar proyectos con adopción acelerada.</p>
        <div class="table-wrap">
            <table>
                <tr><th>N°</th><th>Repositorio</th><th>Owner</th><th>Stars</th><th>Edad meses</th><th>Stars/mes</th><th>Días desde push</th><th>Adopción MCP</th><th>Clasificación</th><th>URL</th></tr>
                <?php foreach ($adopcionRows as $i => $repo): ?>
                    <?php
                    $adopcion = round((float)$repo['adopcion_mcp'], 3);
                    if ($adopcion > 0.7) { $label = 'Alta'; $css = 'badge badge-high'; }
                    elseif ($adopcion >= 0.3) { $label = 'Media'; $css = 'badge badge-mid'; }
                    else { $label = 'Baja'; $css = 'badge badge-low'; }
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= e($repo['name']) ?></td>
                        <td><?= e($repo['owner_login']) ?></td>
                        <td><?= number_format((int)$repo['stargazers_count'], 0, ',', '.') ?></td>
                        <td><?= number_format((int)$repo['edad_meses'], 0, ',', '.') ?></td>
                        <td><?= number_format((float)$repo['stars_per_month'], 2, ',', '.') ?></td>
                        <td><?= number_format((int)$repo['dias_desde_push'], 0, ',', '.') ?></td>
                        <td><?= number_format($adopcion, 3, ',', '.') ?></td>
                        <td><span class="<?= $css ?>"><?= $label ?></span></td>
                        <td><a href="<?= e($repo['html_url']) ?>" target="_blank">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

</div>

<script>
const starsLabels = <?= json_encode($starsLabels, JSON_UNESCAPED_UNICODE) ?>;
const starsValues = <?= json_encode($starsValues, JSON_UNESCAPED_UNICODE) ?>;
const langLabels = <?= json_encode($langLabels, JSON_UNESCAPED_UNICODE) ?>;
const langValues = <?= json_encode($langValues, JSON_UNESCAPED_UNICODE) ?>;
const monthLabels = <?= json_encode($monthLabels, JSON_UNESCAPED_UNICODE) ?>;
const monthValues = <?= json_encode($monthValues, JSON_UNESCAPED_UNICODE) ?>;
const starsMonthLabels = <?= json_encode($starsMonthLabels, JSON_UNESCAPED_UNICODE) ?>;
const starsMonthValues = <?= json_encode($starsMonthValues, JSON_UNESCAPED_UNICODE) ?>;

const raviLabels = ['Baja', 'Media', 'Alta'];
const raviValues = [<?= $raviBaja ?>, <?= $raviMedia ?>, <?= $raviAlta ?>];
const adoptionScoreLabels = ['Baja', 'Media', 'Alta'];
const adoptionScoreValues = [<?= $adoptionScoreBaja ?>, <?= $adoptionScoreMedia ?>, <?= $adoptionScoreAlta ?>];
const starsPerMonthLabels = ['Baja', 'Media', 'Alta'];
const starsPerMonthValues = [<?= $spmBaja ?>, <?= $spmMedia ?>, <?= $spmAlta ?>];
const adopcionMcpLabels = ['Baja', 'Media', 'Alta'];
const adopcionMcpValues = [<?= $adopcionBaja ?>, <?= $adopcionMedia ?>, <?= $adopcionAlta ?>];

function makeChart(id, type, labels, values, label) {
    new Chart(document.getElementById(id), {
        type: type,
        data: {
            labels: labels,
            datasets: [{ label: label, data: values }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } }
        }
    });
}

makeChart('chartStars', 'bar', starsLabels, starsValues, 'Cantidad de repositorios');
makeChart('chartLanguages', 'pie', langLabels, langValues, 'Repositorios');
makeChart('chartMonths', 'line', monthLabels, monthValues, 'Repositorios creados');
makeChart('chartStarsByMonth', 'bar', starsMonthLabels, starsMonthValues, 'Estrellas acumuladas');
makeChart('chartRavi', 'doughnut', raviLabels, raviValues, 'Clasificación RAVI joven');
makeChart('chartStarsPerMonth', 'bar', starsPerMonthLabels, starsPerMonthValues, 'Crecimiento mensual');
makeChart('chartAdopcionMcp', 'doughnut', adopcionMcpLabels, adopcionMcpValues, 'Adopción MCP');
makeChart('chartAdoptionScore', 'doughnut', adoptionScoreLabels, adoptionScoreValues, 'Adoption score joven');
</script>

</body>
</html>
