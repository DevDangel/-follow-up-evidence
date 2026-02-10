<?php
// route: localhost/follow-up-evidence/query.php



// Primera conexión a fianzabogota
$conn1 = new mysqli("localhost", "root", "", "fianzabogota", 3306);
if ($conn1->connect_error) {
    die("Connection failed to fianzabogota: " . $conn1->connect_error);
}


// Segunda conexión a fianzacredito
$conn2 = new mysqli("localhost", "root", "", "fianzacredito", 3306);
if ($conn2->connect_error) {
    die("Connection failed to fianzacredito: " . $conn2->connect_error);
}

// Definir base de datos global
$dbGlobalBogota = 'fianzabogota';

// Función para homologar cliente_id
function homologarClienteId($conexOrigen, $conexDestino, $cliente_id_origen, $dbOrigen, $empresa = 'origen')
{
    global $dbGlobalBogota;

    if (empty($cliente_id_origen)) {
        return null;
    }

    // Buscar el numero_identificacion del cliente en la base origen
    $queryOrigen = "SELECT t.numero_identificacion FROM $dbOrigen.cliente c 
                    INNER JOIN $dbOrigen.tercero t ON c.tercero_id = t.tercero_id 
                    WHERE c.cliente_id = " . intval($cliente_id_origen);
    $resultOrigen = mysqli_query($conexOrigen, $queryOrigen);
    $rowOrigen = $resultOrigen ? mysqli_fetch_assoc($resultOrigen) : null;

    if (!$rowOrigen || empty($rowOrigen['numero_identificacion'])) {
        echo "Advertencia: No se encontró numero_identificacion para cliente_id=$cliente_id_origen en $empresa<br>";
        return null;
    }

    $numero_identificacion = mysqli_real_escape_string($conexDestino, $rowOrigen['numero_identificacion']);

    // Buscar el cliente_id en destino usando el numero_identificacion
    $queryDestino = "SELECT c.cliente_id FROM $dbGlobalBogota.cliente c 
                     INNER JOIN $dbGlobalBogota.tercero t ON c.tercero_id = t.tercero_id 
                     WHERE t.numero_identificacion = '$numero_identificacion'";
    $resultDestino = mysqli_query($conexDestino, $queryDestino);
    $rowDestino = $resultDestino ? mysqli_fetch_assoc($resultDestino) : null;

    if ($rowDestino && $rowDestino['cliente_id']) {
        return $rowDestino['cliente_id'];
    }

    echo "Advertencia: Cliente con numero_identificacion='$numero_identificacion' no existe en destino<br>";
    return null;
}


// Consulta a la tabla usuario en fianzacredito
$sql = "SELECT usuario_id, tercero_id, cliente_id, usuario, email FROM usuario WHERE cliente_id IS NOT NULL";
$result = $conn2->query($sql);

$rows = [];
$usuarios = [];
while($row = $result->fetch_assoc()) {
    $rows[] = $row;
    $usuarios[] = $row['usuario'];
}

echo "<h3>Total de filas encontradas en fianzacredito: " . count($rows) . "</h3>";

if (!empty($rows)) {
    echo "<table border='1'><tr>";
    // Obtener nombres de columnas
    $fields = $result->fetch_fields();
    foreach ($fields as $field) {
        echo "<th>" . $field->name . "</th>";
    }
    echo "</tr>";
    foreach($rows as $row) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value) . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No se encontraron resultados en fianzacredito";
}

// Tabla combinada
echo "<h3>Tabla combinada de usuarios coincidentes</h3>";
echo "<table border='1'><tr>";
echo "<th>usuario_id fc</th><th>tercero_id fc</th><th>cliente_id fc</th><th>cliente_id fb</th><th>usuario fc</th><th>usuario fb</th>";
echo "</tr>";

foreach($rows as $fc_row) {
    // Buscar fila coincidente en fianzabogota
    $stmt_fb = $conn1->prepare("SELECT usuario_id, tercero_id, cliente_id, usuario, email FROM usuario WHERE usuario = ?");
    $stmt_fb->bind_param("s", $fc_row['usuario']);
    $stmt_fb->execute();
    $result_fb = $stmt_fb->get_result();
    $fb_row = $result_fb->fetch_assoc();
    $stmt_fb->close();

    if ($fb_row) {
        $cliente_id_fb = homologarClienteId($conn2, $conn1, $fc_row['cliente_id'], 'fianzacredito');
        echo "<tr>";
        echo "<td>" . htmlspecialchars($fc_row['usuario_id']) . "</td>";
        echo "<td>" . htmlspecialchars($fc_row['tercero_id']) . "</td>";
        echo "<td>" . htmlspecialchars($fc_row['cliente_id']) . "</td>";
        echo "<td>" . htmlspecialchars($cliente_id_fb) . "</td>";
        echo "<td>" . htmlspecialchars($fc_row['usuario']) . "</td>";
        echo "<td>" . htmlspecialchars($fb_row['usuario']) . "</td>";
        echo "</tr>";

        // Generar UPDATE en texto plano
        if ($cliente_id_fb) {
            echo "UPDATE usuario SET cliente_id = $cliente_id_fb WHERE usuario = '" . $conn1->real_escape_string($fb_row['usuario']) . "';<br>";
        }
    }
}
echo "</table>";

$conn1->close();
$conn2->close();