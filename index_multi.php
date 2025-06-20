<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$log_dir = '/mnt/c/WAF-test/logs';
$arquivos = glob($log_dir . '/tempo_resposta_*.log');
date_default_timezone_set('America/Sao_Paulo');
$horario_atual = date('Y-m-d H:i:s');
function calcular_percentil(array $dados, float $percentil): float {
    if (empty($dados)) return 0;
    sort($dados);
    $index = (int) floor(count($dados) * $percentil);
    return $dados[$index] ?? end($dados);
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Dashboard Multi-FQDN</title>
    <meta http-equiv="refresh" content="30">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0; padding: 0;
            background: #f9f9f9;
        }
        header {
            padding: 10px 20px;
            background: #333;
            color: #fff;
            font-size: 18px;
        }
        .grid {
            display: flex;
            flex-wrap: wrap;
        }
        .painel {
            background: #fff;
            border: 1px solid #ccc;
            margin: 10px;
            padding: 10px;
            width: 48%;
            box-sizing: border-box;
            font-size: 13px;
        }
        canvas {
            width: 100% !important;
            height: 200px !important;
        }
        pre {
            font-family: monospace;
            font-size: 12px;
            background: #f0f0f0;
            padding: 5px;
            overflow-x: auto;
            max-height: 150px;
        }
        h2 {
            margin: 10px 0 2px;
            font-size: 16px;
        }
        h2 small {
            font-weight: normal;
            font-size: 13px;
            color: #555;
        }
        h4 {
            margin-top: 15px;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <header>Dashboard Multi-FQDN — Atualizado em: <?= $horario_atual ?></header>
    <div class="grid">

<?php foreach ($arquivos as $arquivo):
    $nome = basename($arquivo);
    if (!preg_match('/tempo_resposta_(.+?)__(.+?)_(.+?)_(.+?)\.log/', $nome, $m)) continue;

    $dominio = str_replace('_', '.', $m[1]);
    $pessoa  = ucfirst($m[2]);
    $cidade  = ucfirst($m[3]);
    $operadora = ucfirst($m[4]);

    $linhas = file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$linhas) continue;

    $ultimos = array_slice($linhas, -20);
    $tempos_total = $tempos_lookup = $tempos_connect = $tempos_pretransfer = $tempos_starttransfer = [];

    foreach ($linhas as $linha) {
        $p = explode(" ", trim($linha));
        if (count($p) >= 6) {
            $tempos_lookup[] = floatval($p[2]);
            $tempos_connect[] = floatval($p[3]);
            $tempos_pretransfer[] = floatval($p[4]);
            $tempos_starttransfer[] = floatval($p[5]);
            $tempos_total[] = floatval($p[6]);
        }
    }

    $ultimos_tempos = [];
    foreach ($ultimos as $l) {
        $p = explode(" ", trim($l));
        if (count($p) >= 6) {
            $ultimos_tempos[] = floatval($p[5]);
        }
    }
?>

<div class="painel">
    <h2><?= htmlspecialchars($dominio) ?><br>
        <small><?= htmlspecialchars($pessoa) ?>, <?= htmlspecialchars($cidade) ?> (<?= htmlspecialchars($operadora) ?>)</small>
    </h2>

    <canvas id="grafico_<?= $m[1] . '_' . $m[2] ?>"></canvas>
    <script>
        new Chart(document.getElementById("grafico_<?= $m[1] . '_' . $m[2] ?>").getContext("2d"), {
            type: 'line',
            data: {
                labels: [...Array(<?= count($ultimos_tempos) ?>).keys()].map(i => i + 1),
                datasets: [{
                    label: "Tempo Total (s)",
                    data: <?= json_encode($ultimos_tempos) ?>,
                    borderColor: "blue",
                    fill: false,
                    tension: 0.2
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        suggestedMax: <?= number_format(max($ultimos_tempos) + 0.1, 2, '.', '') ?>
                    }
                }
            }
        });
    </script>

    <h4>Últimos 20 registros</h4>
    <pre><?= implode("\n", $ultimos) ?></pre>

    <h4>Estatísticas Detalhadas</h4>
    <table border="1" cellpadding="4" cellspacing="0" style="border-collapse: collapse; font-size: 12px;">
        <thead>
            <tr style="background-color: #eee;">
                <th>Métrica</th>
                <th>P90 Média</th>
                <th>P90 Máx</th>
                <th>P95 Média</th>
                <th>P95 Máx</th>
                <th>P100 Mín</th>
                <th>P100 Máx</th>
                <th>P100 Média</th>
            </tr>
        </thead>
        <tbody>
<?php
$metricas = [
    'Tempo para resolução DNS' => $tempos_lookup,
    'Tempo até o TCP Handshake' => $tempos_connect,
    'Tempo até o envio do primeiro byte' => $tempos_pretransfer,
    'Tempo até o primeiro byte de resposta' => $tempos_starttransfer,
    'Tempo total' => $tempos_total
];

foreach ($metricas as $nome => $dados) {
    if (empty($dados)) continue;
    sort($dados);
    $total = count($dados);
    $p90_arr = array_slice($dados, 0, (int) floor($total * 0.90));
    $p95_arr = array_slice($dados, 0, (int) floor($total * 0.95));

    $p90_media = array_sum($p90_arr) / count($p90_arr);
    $p90_max = max($p90_arr);
    $p95_media = array_sum($p95_arr) / count($p95_arr);
    $p95_max = max($p95_arr);
    $p100_min = min($dados);
    $p100_max = max($dados);
    $p100_media = array_sum($dados) / $total;

    echo "<tr>
        <td>$nome</td>
        <td>" . number_format($p90_media, 4) . "</td>
        <td>" . number_format($p90_max, 4) . "</td>
        <td>" . number_format($p95_media, 4) . "</td>
        <td>" . number_format($p95_max, 4) . "</td>
        <td>" . number_format($p100_min, 4) . "</td>
        <td>" . number_format($p100_max, 4) . "</td>
        <td>" . number_format($p100_media, 4) . "</td>
    </tr>";
}
?>
        </tbody>
    </table>
</div>
<?php endforeach; ?>
</div>
</body>
</html>

