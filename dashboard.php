<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$log_dir = '/mnt/c/WAF-test/logs';
$arquivos = glob("$log_dir/tempo_resposta_*.log");

$dados = [];
$comparativo = [];
$view = $_GET['view'] ?? 'stats';
$metrica = $_GET['metrica'] ?? 'time_total';
$estatistica = $_GET['estatistica'] ?? 'Todos';
$colunas_estatisticas = ['min', 'avg', 'max', 'p95', 'p95_avg'];

function calcular_estatisticas($tempos) {
    sort($tempos);
    $total = count($tempos);
    if ($total === 0) return null;

    $p95_index = (int) floor($total * 0.95);
    $tempos_p95 = array_slice($tempos, 0, $p95_index);

    return [
        'current' => end($tempos),
        'min' => min($tempos),
        'avg' => array_sum($tempos) / $total,
        'max' => max($tempos),
        'p95' => $tempos[$p95_index],
        'p95_avg' => array_sum($tempos_p95) / count($tempos_p95),
        'count' => $total
    ];
}

function extrair_tempo($linha) {
    $p = preg_split('/\s+/', trim($linha));
    return count($p) >= 7 ? [
        'timestamp' => "$p[0] $p[1]",
        'dns' => (float)$p[2],
        'tcp' => (float)$p[3],
        'pretransfer' => (float)$p[4],
        'starttransfer' => (float)$p[5],
        'total' => (float)$p[6],
    ] : null;
}

// Lê e organiza dados
foreach ($arquivos as $arquivo) {
    $basename = basename($arquivo);
    if (preg_match('/tempo_resposta_(.*?)__(.*?)_(.*?)_(.*?)\.log$/', $basename, $m)) {
        $host = str_replace('_', '.', $m[1]);
        $pessoa = $m[2];
        $cidade = $m[3];
        $operadora = $m[4];
        $chave = "$host|$pessoa|$cidade|$operadora";
        $linhas = file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $tempos = ['dns'=>[],'tcp'=>[],'pretransfer'=>[],'starttransfer'=>[],'total'=>[]];
        $ultimos = [];

        foreach ($linhas as $linha) {
            $t = extrair_tempo($linha);
            if (!$t) continue;
            $tempos['dns'][] = $t['dns'];
            $tempos['tcp'][] = $t['tcp'];
            $tempos['pretransfer'][] = $t['pretransfer'];
            $tempos['starttransfer'][] = $t['starttransfer'];
            $tempos['total'][] = $t['total'];
            $ultimos[] = [$t['timestamp'], $t['total']];
        }

        $dados[$chave] = [
            'fqdn' => $host,
            'origem' => "$pessoa ($cidade/$operadora)",
            'estat' => [
                'dns' => calcular_estatisticas($tempos['dns']),
                'tcp' => calcular_estatisticas($tempos['tcp']),
                'pretransfer' => calcular_estatisticas($tempos['pretransfer']),
                'starttransfer' => calcular_estatisticas($tempos['starttransfer']),
                'total' => calcular_estatisticas($tempos['total']) + ['serie' => $tempos['total']],
            ],
            'ultimos' => array_slice($ultimos, -5),
            'lastupdate' => end($ultimos)[0] ?? ''
        ];
    }
}
$metrica_label = [
    'dns' => 'Tempo para resolução DNS',
    'tcp' => 'Tempo até TCP Handshake',
    'pretransfer' => 'Tempo até envio 1º byte',
    'starttransfer' => 'Tempo até 1º byte resposta',
    'total' => 'Tempo Total'
];
$estatistica = strtolower($estatistica);

$file_path = '/mnt/data/dashboard.php';
// Linha removida: não salvar cópia automática via PHP localmente  // salva uma cópia para download
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <meta http-equiv="refresh" content="10">
    <style>
        body { font-family: sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; font-size: 13px; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: center; }
        th.green { background-color: #c7f3c7; }
        select { padding: 4px; margin-right: 10px; }
        h2 { margin-top: 40px; }
        pre { white-space: pre-wrap; font-family: monospace; }
    </style>
</head>
<body>
    <h1>Dashboard</h1>
    <form method="get">
        <label>View:
            <select name="view" onchange="this.form.submit()">
                <option value="stats" <?= $view=='stats'?'selected':'' ?>>Estatísticas Gerais</option>
                <option value="logs" <?= $view=='logs'?'selected':'' ?>>Last Logs</option>
                <option value="grafico" <?= $view=='grafico'?'selected':'' ?>>Gráfico Consolidado</option>
            </select>
        </label>
        <?php if ($view != 'logs'): ?>
        <label>Métrica:
            <select name="metrica" onchange="this.form.submit()">
                <?php foreach ($metrica_label as $k=>$v): ?>
                <option value="<?= $k ?>" <?= $metrica==$k?'selected':'' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <?php if ($view == 'stats'): ?>
        <label>Estatística:
            <select name="estatistica" onchange="this.form.submit()">
                <option value="Todos" <?= $estatistica=='todos'?'selected':'' ?>>Todos</option>
                <?php foreach ($colunas_estatisticas as $c): ?>
                <option value="<?= $c ?>" <?= $estatistica==$c?'selected':'' ?>><?= ucfirst($c) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
    </form>
    <?php if ($view == 'stats'): ?>
    <h2>Estatísticas Gerais - <?= $metrica_label[$metrica] ?></h2>
    <table>
        <tr>
            <th>FQDN</th>
            <th>Origem</th>
            <?php
                $mostrar_colunas = ($estatistica == 'todos') ? $colunas_estatisticas : [$estatistica];
                foreach ($colunas_estatisticas as $c) {
                    if ($estatistica == 'todos' || $estatistica == $c)
                        echo "<th class='".($estatistica==$c?'green':'')."'>" . strtoupper($c) . "</th>";
                }
                if ($estatistica != 'todos') {
                    echo "<th>Δ</th><th>% Δ</th>";
                }
            ?>
            <th>Number of Requests</th>
            <th>Last Update</th>
        </tr>
        <?php
        // Organiza dados com base na estatística selecionada
        uasort($dados, function($a, $b) use ($metrica, $estatistica) {
            if ($estatistica == 'todos') return 0;
            return $a['estat'][$metrica][$estatistica] <=> $b['estat'][$metrica][$estatistica];
        });

        $baseline = null;
        foreach ($dados as $info) {
            $estat = $info['estat'][$metrica];
            if (!$estat) continue;
            $val = $estat[$estatistica] ?? 0;
            if ($baseline === null && $estatistica != 'todos') $baseline = $val;

            echo "<tr><td>{$info['fqdn']}</td><td>{$info['origem']}</td>";
            foreach ($colunas_estatisticas as $c) {
                if ($estatistica == 'todos' || $estatistica == $c)
                    echo "<td>" . number_format($estat[$c], 3) . "</td>";
            }
            if ($estatistica != 'todos') {
                $delta = $val - $baseline;
                $delta_pct = $baseline > 0 ? ($delta / $baseline) * 100 : 0;
                echo "<td>" . number_format($delta, 3) . "</td>";
                echo "<td>" . number_format($delta_pct, 1) . "%</td>";
            }
            echo "<td>{$estat['count']}</td><td>{$info['lastupdate']}</td></tr>";
        }
        ?>
    </table>
    <?php elseif ($view == 'logs'): ?>
    <h2>Últimos 5 tempos - Time Total</h2>
    <?php foreach ($dados as $fqdn => $info): ?>
        <h3><?= $info['fqdn'] ?> — <?= $info['origem'] ?></h3>
        <table>
            <tr><th>Data/Hora</th><th>Tempo Total (s)</th></tr>
            <?php foreach ($info['ultimos'] as $linha): ?>
                <tr><td><?= $linha[0] ?></td><td><?= number_format($linha[1], 3) ?></td></tr>
            <?php endforeach; ?>
        </table>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
