<?php

// =============================================
// Configurazione database
// =============================================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'catalogo_libri');

// =============================================
// Header: JSON + CORS (utile per test locali)
// =============================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// =============================================
// Connessione PDO
// =============================================
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    rispondi(500, ['errore' => 'Connessione al database fallita: ' . $e->getMessage()]);
}

// =============================================
// Routing in base al metodo HTTP
// =============================================
$metodo = $_SERVER['REQUEST_METHOD'];

switch ($metodo) {

    case 'GET':
        getLibri($pdo);
        break;

    case 'POST':
        aggiungiLibro($pdo);
        break;

    case 'DELETE':
        eliminaLibro($pdo);
        break;

    case 'PUT':
        aggiornaLibro($pdo);
        break;

    default:
        rispondi(405, ['errore' => 'Metodo HTTP non supportato.']);
}


// =============================================
// GET /libri.php — Elenca tutti i libri
// =============================================
function getLibri(PDO $pdo) {
    $stmt  = $pdo->query("SELECT * FROM libri ORDER BY id ASC");
    $libri = $stmt->fetchAll();

    foreach ($libri as &$libro) {
        $libro['id']   = (int) $libro['id'];
        $libro['anno'] = (int) $libro['anno'];
    }

    rispondi(200, $libri);
}


// =============================================
// POST /libri.php — Aggiunge un nuovo libro
// =============================================
function aggiungiLibro(PDO $pdo) {
    $dati = leggiJSON();

    foreach (['id', 'titolo', 'autore', 'anno'] as $campo) {
        if (!isset($dati[$campo]) || $dati[$campo] === '') {
            rispondi(400, ['errore' => "Campo obbligatorio mancante o vuoto: '$campo'."]);
        }
    }

    $id     = filter_var($dati['id'],   FILTER_VALIDATE_INT);
    $anno   = filter_var($dati['anno'], FILTER_VALIDATE_INT);
    $titolo = trim($dati['titolo']);
    $autore = trim($dati['autore']);

    if ($id === false || $id <= 0) rispondi(400, ['errore' => "'id' deve essere un intero positivo."]);
    if ($anno === false)           rispondi(400, ['errore' => "'anno' deve essere un numero intero."]);
    if ($titolo === '')            rispondi(400, ['errore' => "'titolo' non può essere vuoto."]);
    if ($autore === '')            rispondi(400, ['errore' => "'autore' non può essere vuoto."]);

    // Controllo duplicato ID
    $stmt = $pdo->prepare("SELECT id FROM libri WHERE id = :id");
    $stmt->execute([':id' => $id]);
    if ($stmt->fetch()) {
        rispondi(409, ['errore' => "Esiste già un libro con id $id."]);
    }

    // Inserimento
    $stmt = $pdo->prepare(
        "INSERT INTO libri (id, titolo, autore, anno) VALUES (:id, :titolo, :autore, :anno)"
    );
    $stmt->execute([
        ':id'     => $id,
        ':titolo' => $titolo,
        ':autore' => $autore,
        ':anno'   => $anno,
    ]);

    rispondi(201, ['id' => $id, 'titolo' => $titolo, 'autore' => $autore, 'anno' => $anno]);
}


// =============================================
// DELETE /libri.php?id={n} — Elimina un libro
// =============================================
function eliminaLibro(PDO $pdo) {
    $id = getIdDaQuery();

    $stmt = $pdo->prepare("SELECT id FROM libri WHERE id = :id");
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetch()) {
        rispondi(404, ['errore' => "Nessun libro trovato con id $id."]);
    }

    $stmt = $pdo->prepare("DELETE FROM libri WHERE id = :id");
    $stmt->execute([':id' => $id]);

    rispondi(200, ['messaggio' => "Libro con id $id eliminato con successo."]);
}


// =============================================
// PUT /libri.php?id={n} — Aggiorna un libro
// =============================================
function aggiornaLibro(PDO $pdo) {
    $id   = getIdDaQuery();
    $dati = leggiJSON();

    $aggiornamenti = [];
    $parametri     = [];

    foreach (['titolo', 'autore', 'anno'] as $campo) {
        if (isset($dati[$campo]) && $dati[$campo] !== '') {
            if ($campo === 'anno') {
                $valore = filter_var($dati[$campo], FILTER_VALIDATE_INT);
                if ($valore === false) rispondi(400, ['errore' => "'anno' deve essere un numero intero."]);
            } else {
                $valore = trim($dati[$campo]);
                if ($valore === '') continue;
            }
            $aggiornamenti[]      = "$campo = :$campo";
            $parametri[":$campo"] = $valore;
        }
    }

    if (empty($aggiornamenti)) {
        rispondi(400, ['errore' => 'Nessun campo valido fornito. Campi accettati: titolo, autore, anno.']);
    }

    $stmt = $pdo->prepare("SELECT id FROM libri WHERE id = :id");
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetch()) {
        rispondi(404, ['errore' => "Nessun libro trovato con id $id."]);
    }

    $parametri[':id'] = $id;
    $sql = "UPDATE libri SET " . implode(', ', $aggiornamenti) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parametri);

    rispondi(200, ['messaggio' => "Libro con id $id aggiornato con successo."]);
}


// =============================================
// Funzioni di supporto
// =============================================

function leggiJSON(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        rispondi(400, ['errore' => 'Body della richiesta vuoto o non JSON.']);
    }
    $dati = json_decode($raw, true);//con true restituisce un array associativo
    if (json_last_error() !== JSON_ERROR_NONE) {
        rispondi(400, ['errore' => 'JSON non valido: ' . json_last_error_msg()]);
    }
    return $dati;
}

function getIdDaQuery(): int {
    if (!isset($_GET['id'])) {
        rispondi(400, ['errore' => "Parametro 'id' mancante nella query string."]);
    }
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($id === false || $id <= 0) {
        rispondi(400, ['errore' => "Il parametro 'id' deve essere un intero positivo."]);
    }
    return $id;
}

function rispondi(int $codice, mixed $corpo): never {
    http_response_code($codice);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);//per evitare che i termini accentati venghino trasformati in codici unicode
    exit;
}


// esempi
// Per il GET
//curl.exe http://localhost/libri/libri.php

//Per il POST (va creato un file libro.json)
//curl.exe -X POST http://localhost/libri/libri.php -H "Content-Type: application/json" -d @libro.json

//Per il DELETE
//curl.exe -X DELETE http://localhost/libri/libri.php?id=101

//Per PUT (va creato un dile aggiorna.json)
//curl.exe -X PUT http://localhost/libri/libri.php?id=101 -H "Content-Type: application/json" -d @aggiorna.json
