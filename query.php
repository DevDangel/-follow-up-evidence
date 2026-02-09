<?php

// Configuración de conexión
$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'siandsi_gmt2';
$cliente_id = 9; // Cambia por el cliente que quieras analizar

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// 1. Traer todas las facturas con su total
$sql_facturas = "
SELECT
    f.factura_id,
    f.consecutivo_factura,
    f.fecha,
    SUM(d.deb_item_factura + d.cre_item_factura) AS total_factura
FROM factura f
INNER JOIN detalle_factura_puc d ON d.factura_id = f.factura_id
WHERE f.cliente_id = $cliente_id
  AND d.contra_factura = 1
GROUP BY f.factura_id, f.consecutivo_factura, f.fecha
ORDER BY f.factura_id ASC
";
$result_facturas = $conn->query($sql_facturas);

// 2. Traer todos los abonos aplicados a cada factura
$sql_abonos = "
SELECT
    r.factura_id,
    SUM(r.rel_valor_abono + r.rel_valor_descu) AS total_abono
FROM relacion_abono r
INNER JOIN abono_factura af ON r.abono_factura_id = af.abono_factura_id
WHERE UPPER(af.estado_abono_factura) = 'C'
  AND af.cliente_id = $cliente_id
GROUP BY r.factura_id
";
$result_abonos = $conn->query($sql_abonos);

// Indexar abonos por factura_id
$abonos_por_factura = [];
if ($result_abonos && $result_abonos->num_rows > 0) {
    while($row = $result_abonos->fetch_assoc()) {
        $abonos_por_factura[$row['factura_id']] = $row['total_abono'];
    }
}

// Botones de navegación
echo '<div style="position: fixed; top: 10px; right: 10px; z-index: 1000; background: white; padding: 10px; border: 1px solid #ccc; border-radius: 5px;">';
echo '<button onclick="document.getElementById(\'facturas\').scrollIntoView({behavior: \'smooth\'});">Facturas</button> ';
echo '<button onclick="document.getElementById(\'remesas\').scrollIntoView({behavior: \'smooth\'});">Remesas</button> ';
echo '<button onclick="document.getElementById(\'abonos\').scrollIntoView({behavior: \'smooth\'});">Abonos</button> ';
echo '<button onclick="document.getElementById(\'debug\').scrollIntoView({behavior: \'smooth\'});">Debug CXC</button> ';
echo '<button onclick="document.getElementById(\'final\').scrollIntoView({behavior: \'smooth\'});">Seguimiento Final</button>';
echo '</div>';

echo "<table id='facturas' border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Factura ID</th><th>Factura</th><th>Fecha</th><th>Total</th><th>Abonos</th><th>Saldo Pendiente</th></tr>";
$total_general = 0;
$contador_facturas = 0;
// Guardar todas las filas en un array para ordenar
$facturas_rows = [];
if ($result_facturas && $result_facturas->num_rows > 0) {
    while($row = $result_facturas->fetch_assoc()) {
        $facturas_rows[] = $row;
    }
    // Ordenar por factura_id ascendente
    usort($facturas_rows, function($a, $b) {
        return $a['factura_id'] <=> $b['factura_id'];
    });
    foreach ($facturas_rows as $row) {
        $total = $row['total_factura'];
        $abonos = isset($abonos_por_factura[$row['factura_id']]) ? $abonos_por_factura[$row['factura_id']] : 0;
        $saldo = $total - $abonos;
            
       if ($saldo > 0) {
            $contador_facturas++;
            echo "<tr>"
                . "<td>" . htmlspecialchars($row['factura_id']) . "</td>"
                . "<td>" . htmlspecialchars($row['consecutivo_factura']) . "</td>"
                . "<td>" . htmlspecialchars($row['fecha']) . "</td>"
                . "<td>" . $total . "</td>"
                . "<td>" . $abonos . "</td>"
                . "<td>" . $saldo . "</td>"
                . "</tr>";
            $total_general += $saldo;
        }
    }
} else {
    echo "<tr><td colspan='6'>No hay facturas para este cliente.</td></tr>";
}
echo "</table>";

echo "<br><table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Total Saldos Pendientes</th><th>Facturas Contadas</th></tr>";
echo "<tr><td style='font-weight:bold;'>" . $total_general . "</td>"
    . "<td style='font-weight:bold;'>" . $contador_facturas . "</td></tr>";
echo "</table>";

// Tabla de remesas pendientes
$sql_remesas = "
SELECT
    r.remesa_id,
    r.fecha_remesa,
    r.valor_facturar
FROM remesa r
WHERE r.cliente_id = $cliente_id AND UPPER(r.estado) = 'PD'
ORDER BY r.fecha_remesa DESC
";
$result_remesas = $conn->query($sql_remesas);

echo "<br><table id='remesas' border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Remesa</th><th>Fecha</th><th>Valor a Facturar</th></tr>";
$total_remesas = 0;
if ($result_remesas && $result_remesas->num_rows > 0) {
    while($row = $result_remesas->fetch_assoc()) {
        echo "<tr>"
            . "<td>" . htmlspecialchars($row['remesa_id']) . "</td>"
            . "<td>" . htmlspecialchars($row['fecha_remesa']) . "</td>"
            . "<td>" . number_format($row['valor_facturar'], 0, ',', '.') . "</td>"
            . "</tr>";
        $total_remesas += $row['valor_facturar'];
    }
} else {
    echo "<tr><td colspan='3'>No hay remesas pendientes para este cliente.</td></tr>";
}
echo "</table>";

echo "<br><table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Total Remesas Pendientes</th></tr>";
echo "<tr><td style='font-weight:bold;'>" . $total_remesas . "</td></tr>";
echo "</table>";

// Tabla de suma de saldos pendientes + remesas
echo "<br><table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Suma Saldos Pendientes + Remesas Pendientes</th></tr>";
echo "<tr><td style='font-weight:bold;'>" . ($total_general + $total_remesas) . "</td></tr>";
echo "</table>";

// Nueva tabla de abonos individuales (no agrupados)
$sql_abonos_individuales = "
SELECT
    r.relacion_abono_id,
    r.factura_id,
    r.rel_valor_abono,
    r.rel_valor_descu,
    (r.rel_valor_abono + r.rel_valor_descu) AS total
FROM relacion_abono r
INNER JOIN abono_factura af ON r.abono_factura_id = af.abono_factura_id
WHERE UPPER(af.estado_abono_factura) = 'C'
  AND af.cliente_id = $cliente_id
ORDER BY r.factura_id ASC, r.relacion_abono_id ASC LIMIT 5
";
$result_abonos_individuales = $conn->query($sql_abonos_individuales);

echo "<br><table id='abonos' border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Relacion Abono ID</th><th>Factura ID</th><th>Valor Abono</th><th>Valor Descuento</th><th>Total</th></tr>";
if ($result_abonos_individuales && $result_abonos_individuales->num_rows > 0) {
    while($row = $result_abonos_individuales->fetch_assoc()) {
        echo "<tr>"
            . "<td>" . htmlspecialchars($row['relacion_abono_id']) . "</td>"
            . "<td>" . htmlspecialchars($row['factura_id']) . "</td>"
            . "<td>" . $row['rel_valor_abono'] . "</td>"
            . "<td>" . $row['rel_valor_descu'] . "</td>"
            . "<td>" . $row['total'] . "</td>"
            . "</tr>";
    }
} else {
    echo "<tr><td colspan='5'>No hay abonos individuales para este cliente.</td></tr>";
}
echo "</table>";

// Calcular y mostrar el total de la columna 'total' de abonos individuales
$sql_total_abonos = "
SELECT SUM(r.rel_valor_abono + r.rel_valor_descu) AS total_abonos
FROM relacion_abono r
INNER JOIN abono_factura af ON r.abono_factura_id = af.abono_factura_id
WHERE UPPER(af.estado_abono_factura) = 'C'
  AND af.cliente_id = $cliente_id
";
$res_total_abonos = $conn->query($sql_total_abonos);
$total_abonos_individuales = 0;
if ($res_total_abonos && ($row = $res_total_abonos->fetch_assoc())) {
    $total_abonos_individuales = $row['total_abonos'] ?? 0;
}
echo "<br><table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Total Abonos Individuales</th></tr>";
echo "<tr><td style='font-weight:bold;'>" . $total_abonos_individuales . "</td></tr>";
echo "</table>";

// DEBUG: Desglose de la query total_cxc

// 1. Facturas (detalle_factura_puc + factura)
$sql_facturas_cxc = "SELECT SUM(d.deb_item_factura + d.cre_item_factura) AS total_facturas FROM detalle_factura_puc d INNER JOIN factura f ON d.factura_id = f.factura_id WHERE f.cliente_id = $cliente_id AND d.contra_factura = 1";
$res_facturas_cxc = $conn->query($sql_facturas_cxc);
$total_facturas_cxc = $res_facturas_cxc->fetch_assoc()['total_facturas'] ?? 0;

// 2. Remesas sin facturar
$sql_remesas_cxc = "SELECT SUM(r.valor_facturar) AS total_remesas FROM remesa r WHERE r.cliente_id = $cliente_id AND UPPER(r.estado) = 'PD'";
$res_remesas_cxc = $conn->query($sql_remesas_cxc);
$total_remesas_cxc = $res_remesas_cxc->fetch_assoc()['total_remesas'] ?? 0;

// 3. Ordenes liquidadas sin facturar
$sql_ordenes_cxc = "SELECT SUM(tot.total_orden) AS total_ordenes FROM orden_servicio o JOIN (SELECT orden_servicio_id, SUM(valoruni_item_orden_servicio * cant_item_orden_servicio) AS total_orden FROM item_orden_servicio GROUP BY orden_servicio_id) tot ON tot.orden_servicio_id = o.orden_servicio_id JOIN codpuc_bien_servicio_factura c ON c.tipo_bien_servicio_factura_id = o.tipo_bien_servicio_factura_id WHERE o.cliente_id = $cliente_id AND UPPER(o.estado_orden_servicio) = 'L' AND UPPER(c.despuc_bien_servicio_factura) = 'A COBRAR' AND c.activo = 1";
$res_ordenes_cxc = $conn->query($sql_ordenes_cxc);
$total_ordenes_cxc = $res_ordenes_cxc->fetch_assoc()['total_ordenes'] ?? 0;

// 4. Despachos sin facturar
$sql_despachos_cxc = "SELECT SUM(s.valor_facturar) AS total_despachos FROM seguimiento s WHERE s.cliente_id = $cliente_id AND UPPER(s.estado) = 'P'";
$res_despachos_cxc = $conn->query($sql_despachos_cxc);
$total_despachos_cxc = $res_despachos_cxc->fetch_assoc()['total_despachos'] ?? 0;

// 5. Total CXC (suma de todos)
$total_cxc_debug = ($total_facturas_cxc + $total_remesas_cxc + $total_ordenes_cxc + $total_despachos_cxc);

// 6. Pagos Recibidos y Notas Crédito (igual que en ClientesModelClass.php)
$sql_abonos_nc = "SELECT SUM(r.rel_valor_abono + r.rel_valor_descu) AS total_abonos_nc FROM relacion_abono r INNER JOIN abono_factura af ON r.abono_factura_id = af.abono_factura_id WHERE UPPER(af.estado_abono_factura) = 'C' AND af.cliente_id = $cliente_id";
$res_abonos_nc = $conn->query($sql_abonos_nc);
$total_abonos_nc = $res_abonos_nc->fetch_assoc()['total_abonos_nc'] ?? 0;

// 7. Total CXC menos notas crédito
$total_cxc_menos_nc = $total_cxc_debug - $total_abonos_nc;

echo '<br><h3>Debug CXC - Desglose</h3>';
echo '<table id="debug" border="1" cellpadding="4" style="margin-bottom:20px;">';
echo '<tr><th>Componente</th><th>Valor</th></tr>';
echo '<tr><td>Facturas</td><td>' . number_format($total_facturas_cxc, 0, ',', '.') . '</td></tr>';
echo '<tr><td>Remesas sin facturar</td><td>' . number_format($total_remesas_cxc, 0, ',', '.') . '</td></tr>';
echo '<tr><td>Ordenes liquidadas sin facturar</td><td>' . number_format($total_ordenes_cxc, 0, ',', '.') . '</td></tr>';
echo '<tr><td>Despachos sin facturar</td><td>' . number_format($total_despachos_cxc, 0, ',', '.') . '</td></tr>';
echo '<tr style="font-weight:bold;"><td>Total CXC</td><td>' . number_format($total_cxc_debug, 0, ',', '.') . '</td></tr>';
echo '<tr><td>Pagos Recibidos + Notas Crédito</td><td>' . number_format($total_abonos_nc, 0, ',', '.') . '</td></tr>';
echo '<tr style="font-weight:bold;background:#eef;"><td>Total CXC menos Pagos y Notas Crédito</td><td>' . number_format($total_cxc_menos_nc, 0, ',', '.') . '</td></tr>';
echo '</table>';

// SEGUIMIENTO FINAL - TOTALES PARA AUDITORÍA
echo '<br><h3>Seguimiento Final - Totales</h3>';
echo '<table border="1" cellpadding="4" style="margin-bottom:20px;">';
echo '<tr><th>Concepto</th><th>Valor</th></tr>';

// Total Facturas Netas (saldos > 0)
$sql_total_facturas_netas = "SELECT SUM(GREATEST(0, (d.deb_item_factura + d.cre_item_factura) - COALESCE(abonos.total_abonos, 0))) AS total_neto FROM detalle_factura_puc d INNER JOIN factura f ON d.factura_id = f.factura_id LEFT JOIN (SELECT relacion_abono.factura_id, SUM(relacion_abono.rel_valor_abono + relacion_abono.rel_valor_descu) AS total_abonos FROM relacion_abono INNER JOIN abono_factura ON relacion_abono.abono_factura_id = abono_factura.abono_factura_id WHERE UPPER(abono_factura.estado_abono_factura) = 'C' GROUP BY relacion_abono.factura_id) abonos ON abonos.factura_id = d.factura_id WHERE f.cliente_id = $cliente_id AND d.contra_factura = 1";
$res_total_facturas_netas = $conn->query($sql_total_facturas_netas);
$total_facturas_netas = $res_total_facturas_netas->fetch_assoc()['total_neto'] ?? 0;
echo '<tr><td>Total Facturas Netas (saldos > 0)</td><td>' . number_format($total_facturas_netas, 0, ',', '.') . '</td></tr>';

// Total Abonos Aplicados
$sql_total_abonos_aplicados = "SELECT SUM(r.rel_valor_abono + r.rel_valor_descu) AS total_abonos FROM relacion_abono r INNER JOIN abono_factura af ON r.abono_factura_id = af.abono_factura_id WHERE UPPER(af.estado_abono_factura) = 'C' AND af.cliente_id = $cliente_id";
$res_total_abonos_aplicados = $conn->query($sql_total_abonos_aplicados);
$total_abonos_aplicados = $res_total_abonos_aplicados->fetch_assoc()['total_abonos'] ?? 0;
echo '<tr><td>Total Abonos Aplicados</td><td>' . number_format($total_abonos_aplicados, 0, ',', '.') . '</td></tr>';

// Total Facturas Brutas
$sql_total_facturas_brutas = "SELECT SUM(d.deb_item_factura + d.cre_item_factura) AS total_bruto FROM detalle_factura_puc d INNER JOIN factura f ON d.factura_id = f.factura_id WHERE f.cliente_id = $cliente_id AND d.contra_factura = 1";
$res_total_facturas_brutas = $conn->query($sql_total_facturas_brutas);
$total_facturas_brutas = $res_total_facturas_brutas->fetch_assoc()['total_bruto'] ?? 0;
echo '<tr><td>Total Facturas Brutas</td><td>' . number_format($total_facturas_brutas, 0, ',', '.') . '</td></tr>';

// Total Facturas No Mostradas (saldo <= 0)
$sql_total_no_mostradas = "SELECT SUM(fact.total_factura) AS total_no_mostradas FROM (SELECT f.factura_id, SUM(d.deb_item_factura + d.cre_item_factura) AS total_factura FROM factura f INNER JOIN detalle_factura_puc d ON d.factura_id = f.factura_id WHERE f.cliente_id = $cliente_id AND d.contra_factura = 1 GROUP BY f.factura_id) fact LEFT JOIN (SELECT r.factura_id, SUM(r.rel_valor_abono + r.rel_valor_descu) AS total_abono FROM relacion_abono r INNER JOIN abono_factura af ON r.abono_factura_id = af.abono_factura_id WHERE UPPER(af.estado_abono_factura) = 'C' AND af.cliente_id = $cliente_id GROUP BY r.factura_id) abonos ON fact.factura_id = abonos.factura_id WHERE (fact.total_factura - COALESCE(abonos.total_abono, 0)) <= 0";
$res_total_no_mostradas = $conn->query($sql_total_no_mostradas);
$total_no_mostradas = $res_total_no_mostradas->fetch_assoc()['total_no_mostradas'] ?? 0;
echo '<tr><td>Total Facturas No Mostradas (saldo <= 0)</td><td>' . number_format($total_no_mostradas, 0, ',', '.') . '</td></tr>';

// Suma Total (Neto + Abonos Aplicados)
$suma_total = $total_facturas_netas + $total_abonos_aplicados;
echo '<tr style="font-weight:bold;background:#eef;"><td>Suma Total (Neto + Abonos)</td><td>' . number_format($suma_total, 0, ',', '.') . '</td></tr>';

echo '</table>';

// DEBUG PARA FACTURAS FALTANTES
echo '<br><h3>Debug para Facturas Faltantes</h3>';
$facturas_faltantes = [1857, 9311, 9778, 13823];

foreach ($facturas_faltantes as $fact_id) {
    echo "<h4>Factura ID: $fact_id</h4>";
    
    // Detalles de la factura
    $sql_detalles = "SELECT SUM(deb_item_factura + cre_item_factura) AS total_detalle FROM detalle_factura_puc WHERE factura_id = $fact_id AND contra_factura = 1";
    $res_detalles = $conn->query($sql_detalles);
    $total_detalle = $res_detalles->fetch_assoc()['total_detalle'] ?? 0;
    echo "Total Detalle: $total_detalle<br>";
    
    // Abonos aplicados
    $sql_abonos = "SELECT SUM(r.rel_valor_abono + r.rel_valor_descu) AS total_abono FROM relacion_abono r INNER JOIN abono_factura af ON r.abono_factura_id = af.abono_factura_id WHERE r.factura_id = $fact_id AND UPPER(af.estado_abono_factura) = 'C'";
    $res_abonos = $conn->query($sql_abonos);
    $total_abono = $res_abonos->fetch_assoc()['total_abono'] ?? 0;
    echo "Total Abono: $total_abono<br>";
    
    $saldo = $total_detalle - $total_abono;
    echo "Saldo Calculado: $saldo<br>";
    echo "GREATEST(0, Saldo): " . max(0, $saldo) . "<br>";
    echo "Aparece en Nueva? " . ($saldo > 0 ? 'SÍ' : 'NO') . "<br><br>";
}
echo '<br><h3>Comparación: Query Antigua vs Nueva (Todas las Facturas)</h3>';
echo '<p style="color:red;">Nota: Esta comparación muestra TODAS las facturas del cliente, incluyendo las con saldo <= 0, para identificar diferencias en cálculos.</p>';

// Query antigua (con LEFT JOIN directos)
$query_antigua = "SELECT f.factura_id, f.consecutivo_factura, SUM(d.deb_item_factura + d.cre_item_factura) - COALESCE(SUM(r.rel_valor_abono + r.rel_valor_descu), 0) AS saldo_raw_antigua, GREATEST(0, SUM(d.deb_item_factura + d.cre_item_factura) - COALESCE(SUM(r.rel_valor_abono + r.rel_valor_descu), 0)) AS saldo_antigua
FROM detalle_factura_puc d 
INNER JOIN factura f ON d.factura_id = f.factura_id 
LEFT JOIN relacion_abono r ON r.factura_id = f.factura_id 
LEFT JOIN abono_factura a ON r.abono_factura_id = a.abono_factura_id AND UPPER(a.estado_abono_factura) = 'C' 
WHERE f.cliente_id = $cliente_id AND d.contra_factura = 1 
GROUP BY f.factura_id, f.consecutivo_factura, f.fecha, f.estado";

$result_antigua = $conn->query($query_antigua);
$antigua = [];
if ($result_antigua) {
    while ($row = $result_antigua->fetch_assoc()) {
        $antigua[$row['factura_id']] = $row;
    }
}

// Query nueva (con subconsultas)
$query_nueva = "SELECT f.factura_id, f.consecutivo_factura, d.total_detalle - COALESCE(a.total_abono, 0) AS saldo_raw_nueva, GREATEST(0, d.total_detalle - COALESCE(a.total_abono, 0)) AS saldo_nueva
FROM factura f
INNER JOIN (SELECT factura_id, SUM(deb_item_factura + cre_item_factura) AS total_detalle FROM detalle_factura_puc WHERE contra_factura = 1 GROUP BY factura_id) d ON f.factura_id = d.factura_id
LEFT JOIN (SELECT r.factura_id, SUM(r.rel_valor_abono + r.rel_valor_descu) AS total_abono FROM relacion_abono r INNER JOIN abono_factura af ON r.abono_factura_id = af.abono_factura_id WHERE UPPER(af.estado_abono_factura) = 'C' GROUP BY r.factura_id) a ON f.factura_id = a.factura_id
WHERE f.cliente_id = $cliente_id";

$result_nueva = $conn->query($query_nueva);
$nueva = [];
if ($result_nueva) {
    while ($row = $result_nueva->fetch_assoc()) {
        $nueva[$row['factura_id']] = $row;
    }
}

// Mostrar resultados
echo '<table border="1" cellpadding="4" style="margin-bottom:20px;">';
echo '<tr><th>Tipo</th><th>Factura ID</th><th>Consecutivo</th><th>Saldo Raw Antigua</th><th>Saldo Antigua</th><th>Saldo Raw Nueva</th><th>Saldo Nueva</th></tr>';

// Facturas en antigua pero no en nueva
foreach ($antigua as $id => $row) {
    if (!isset($nueva[$id])) {
        echo '<tr style="background:#ffcccc;"><td>En Antigua, NO en Nueva</td><td>' . $id . '</td><td>' . $row['consecutivo_factura'] . '</td><td>' . $row['saldo_raw_antigua'] . '</td><td>' . $row['saldo_antigua'] . '</td><td>-</td><td>-</td></tr>';
    }
}

// Facturas en nueva pero no en antigua
foreach ($nueva as $id => $row) {
    if (!isset($antigua[$id])) {
        echo '<tr style="background:#ccffcc;"><td>En Nueva, NO en Antigua</td><td>' . $id . '</td><td>' . $row['consecutivo_factura'] . '</td><td>-</td><td>-</td><td>' . $row['saldo_raw_nueva'] . '</td><td>' . $row['saldo_nueva'] . '</td></tr>';
    }
}

// Diferencias en saldos
foreach ($antigua as $id => $row) {
    if (isset($nueva[$id]) && ($row['saldo_raw_antigua'] != $nueva[$id]['saldo_raw_nueva'] || $row['saldo_antigua'] != $nueva[$id]['saldo_nueva'])) {
        echo '<tr style="background:#ffffcc;"><td>Diferencia en Saldo</td><td>' . $id . '</td><td>' . $row['consecutivo_factura'] . '</td><td>' . $row['saldo_raw_antigua'] . '</td><td>' . $row['saldo_antigua'] . '</td><td>' . $nueva[$id]['saldo_raw_nueva'] . '</td><td>' . $nueva[$id]['saldo_nueva'] . '</td></tr>';
    }
}

echo '</table>';

// Nueva tabla: Facturas del Cupo Utilizado
echo '<br><h3>Facturas del Cupo Utilizado</h3>';
$query_facturas = "
(SELECT 
    'Factura' AS servicio, 
    f.consecutivo_factura AS numero, 
    f.fecha, 
    CASE WHEN f.estado = 'C' THEN 'Contabilizada' ELSE 'Anulada' END AS estado,
    GREATEST(0, d.total_detalle - COALESCE(a.total_abono, 0)) AS saldo
FROM factura f
INNER JOIN (
    SELECT factura_id, SUM(deb_item_factura + cre_item_factura) AS total_detalle
    FROM detalle_factura_puc
    WHERE contra_factura = 1
    GROUP BY factura_id
) d ON f.factura_id = d.factura_id
LEFT JOIN (
    SELECT r.factura_id, SUM(r.rel_valor_abono + r.rel_valor_descu) AS total_abono
    FROM relacion_abono r
    INNER JOIN abono_factura af ON r.abono_factura_id = af.abono_factura_id
    WHERE UPPER(af.estado_abono_factura) = 'C'
    GROUP BY r.factura_id
) a ON f.factura_id = a.factura_id
WHERE f.cliente_id = $cliente_id
AND (d.total_detalle - COALESCE(a.total_abono, 0)) > 0)
";

$result_facturas = $conn->query($query_facturas);
$total_saldo_facturas = 0;

if ($result_facturas && $result_facturas->num_rows > 0) {
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<tr><th>Servicio</th><th>Número</th><th>Fecha</th><th>Estado</th><th>Saldo</th></tr>';
    while ($row = $result_facturas->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['servicio']) . '</td>';
        echo '<td>' . htmlspecialchars($row['numero']) . '</td>';
        echo '<td>' . htmlspecialchars($row['fecha']) . '</td>';
        echo '<td>' . htmlspecialchars($row['estado']) . '</td>';
        echo '<td>' . number_format($row['saldo'], 2, ',', '.') . '</td>';
        echo '</tr>';
        $total_saldo_facturas += $row['saldo'];
    }
    echo '</table>';
} else {
    echo '<p>No hay facturas en el cupo utilizado para este cliente.</p>';
}

// Tabla con el total de saldos
echo '<br><table border="1" cellpadding="5" cellspacing="0">';
echo '<tr><th>Total Saldo Facturas</th></tr>';
echo '<tr><td style="font-weight:bold;">' . number_format($total_saldo_facturas, 2, ',', '.') . '</td></tr>';
echo '</table>';

// Query para saldos positivos (> 0)
$query_positivos = "
(SELECT SUM(GREATEST(0, d.total_detalle - COALESCE(a.total_abono, 0))) AS total_positivos
FROM factura f
INNER JOIN (
    SELECT factura_id, SUM(deb_item_factura + cre_item_factura) AS total_detalle
    FROM detalle_factura_puc
    WHERE contra_factura = 1
    GROUP BY factura_id
) d ON f.factura_id = d.factura_id
LEFT JOIN (
    SELECT r.factura_id, SUM(r.rel_valor_abono + r.rel_valor_descu) AS total_abono
    FROM relacion_abono r
    INNER JOIN abono_factura af ON r.abono_factura_id = af.abono_factura_id
    WHERE UPPER(af.estado_abono_factura) = 'C'
    GROUP BY r.factura_id
) a ON f.factura_id = a.factura_id
WHERE f.cliente_id = $cliente_id
AND (d.total_detalle - COALESCE(a.total_abono, 0)) > 0)
";

$result_pos = $conn->query($query_positivos);
$total_positivos = $result_pos->fetch_assoc()['total_positivos'] ?? 0;

// Query para saldos 0 y negativos (<= 0)
$query_cero_negativos = "
SELECT SUM(d.total_detalle - COALESCE(a.total_abono, 0)) AS total_cero_negativos
FROM factura f
INNER JOIN (
    SELECT factura_id, SUM(deb_item_factura + cre_item_factura) AS total_detalle
    FROM detalle_factura_puc
    WHERE contra_factura = 1
    GROUP BY factura_id
) d ON f.factura_id = d.factura_id
LEFT JOIN (
    SELECT r.factura_id, SUM(r.rel_valor_abono + r.rel_valor_descu) AS total_abono
    FROM relacion_abono r
    INNER JOIN abono_factura af ON r.abono_factura_id = af.abono_factura_id
    WHERE UPPER(af.estado_abono_factura) = 'C'
    GROUP BY r.factura_id
) a ON f.factura_id = a.factura_id
WHERE f.cliente_id = $cliente_id
AND (d.total_detalle - COALESCE(a.total_abono, 0)) <= 0
";

$result_cero_neg = $conn->query($query_cero_negativos);
$total_cero_negativos = $result_cero_neg->fetch_assoc()['total_cero_negativos'] ?? 0;

// Mostrar tabla comparativa
echo '<br><h3>Comparación de Saldos</h3>';
echo '<table border="1" cellpadding="5" cellspacing="0">';
echo '<tr><th>Tipo</th><th>Total</th></tr>';
echo '<tr><td>Saldos Positivos (> 0)</td><td>' . number_format($total_positivos, 2, ',', '.') . '</td></tr>';
echo '<tr><td>Saldos 0 y Negativos (<= 0)</td><td>' . number_format($total_cero_negativos, 2, ',', '.') . '</td></tr>';
echo '<tr style="font-weight:bold;"><td>Total General (Query)</td><td>' . number_format($total_positivos + $total_cero_negativos, 2, ',', '.') . '</td></tr>';
echo '<tr style="background:#ffcccc;"><td>Total Reporte</td><td>516.895.231</td></tr>';
echo '<tr style="background:#ffffcc;"><td>Diferencia</td><td>' . number_format(516895231 - ($total_positivos + $total_cero_negativos), 2, ',', '.') . '</td></tr>';
echo '</table>';
$conn->close();
// ruta: localhost/query.php
?>
