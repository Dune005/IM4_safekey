<?php
// hardware_event.php - API zum Empfangen von Ereignissen vom Arduino
// WebPush-Klassen importieren - müssen auf oberster Ebene stehen
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// Fehlerausgabe aktivieren für Debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Fehler in eine Datei protokollieren
ini_set('log_errors', 1);
ini_set('error_log', '../system/hardware_errors.log');

header('Content-Type: application/json');

require_once '../system/config.php';
require_once '../system/hardware_auth.php'; // Enthält den API-Schlüssel für die Hardware-Authentifizierung
require_once '../system/push_notifications_config.php'; // Konfiguration für Push-Benachrichtigungen
require_once '../vendor/autoload.php'; // Composer Autoload für WebPush

// Überprüfen, ob die Anfrage einen gültigen API-Schlüssel enthält
$headers = getallheaders();
$apiKey = isset($headers['X-Api-Key']) ? $headers['X-Api-Key'] : '';

if ($apiKey !== HARDWARE_API_KEY) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Ungültiger API-Schlüssel"]);
    exit;
}

// Daten aus der Anfrage lesen
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(["status" => "error", "message" => "Keine gültigen Daten empfangen"]);
    exit;
}

// Überprüfen, ob alle erforderlichen Felder vorhanden sind
$requiredFields = ['event_type', 'seriennummer'];
foreach ($requiredFields as $field) {
    if (!isset($data[$field])) {
        echo json_encode(["status" => "error", "message" => "Feld '$field' fehlt"]);
        exit;
    }
}

$eventType = $data['event_type'];
$seriennummer = $data['seriennummer'];

// Funktion zum Senden von Push-Benachrichtigungen an alle Benutzer mit einer bestimmten Seriennummer
function sendPushNotificationsForSeriennummer($pdo, $seriennummer, $payload) {
    try {
        // Alle Benutzer mit dieser Seriennummer finden
        $stmt = $pdo->prepare("
            SELECT ps.*
            FROM push_subscriptions ps
            JOIN benutzer b ON ps.user_id = b.user_id
            WHERE b.seriennummer = :seriennummer
        ");
        $stmt->execute([':seriennummer' => $seriennummer]);
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($subscriptions)) {
            return ['status' => 'info', 'message' => 'Keine Abonnements für Benutzer mit dieser Seriennummer gefunden'];
        }

        $auth = [
            'VAPID' => [
                'subject' => VAPID_SUBJECT,
                'publicKey' => VAPID_PUBLIC_KEY,
                'privateKey' => VAPID_PRIVATE_KEY,
            ],
        ];

        $webPush = new WebPush($auth);
        $successCount = 0;
        $failCount = 0;

        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys' => [
                    'p256dh' => $sub['p256dh'],
                    'auth' => $sub['auth'],
                ],
            ]);

            $webPush->queueNotification($subscription, json_encode($payload));
        }

        $results = [];
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                $successCount++;
                $results[] = ['endpoint' => $endpoint, 'status' => 'success'];
            } else {
                $failCount++;
                $reason = $report->getReason();
                $results[] = ['endpoint' => $endpoint, 'status' => 'failed', 'reason' => $reason];

                // Entferne fehlgeschlagene Abonnements
                if (in_array($reason, ['410 Gone', '404 Not Found'])) {
                    $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = :endpoint");
                    $stmt->execute([':endpoint' => $endpoint]);
                }
            }
        }

        return [
            'status' => 'success',
            'message' => "Benachrichtigungen gesendet: $successCount erfolgreich, $failCount fehlgeschlagen",
            'results' => $results
        ];
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => 'Fehler beim Senden der Benachrichtigungen: ' . $e->getMessage()];
    }
}

try {
    // Je nach Ereignistyp unterschiedliche Aktionen ausführen
    switch ($eventType) {
        case 'key_removed':
            // Schlüssel wurde physisch entfernt, aber noch nicht per RFID/NFC bestätigt
            // Wir speichern dieses Ereignis in einer temporären Tabelle
            handleKeyRemoved($pdo, $data);
            break;

        case 'key_returned':
            // Schlüssel wurde zurückgegeben
            handleKeyReturned($pdo, $data);
            break;

        case 'rfid_scan':
            // RFID/NFC-Scan wurde durchgeführt
            handleRfidScan($pdo, $data);
            break;

        default:
            echo json_encode(["status" => "error", "message" => "Unbekannter Ereignistyp: $eventType"]);
            exit;
    }

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Fehler bei der Verarbeitung des Ereignisses: " . $e->getMessage()
    ]);
}

// Funktion zum Verarbeiten des Ereignisses "Schlüssel entfernt"
function handleKeyRemoved($pdo, $data) {
    global $PUSH_NOTIFICATIONS_ENABLED, $PUSH_NOTIFICATIONS_MESSAGES, $PUSH_NOTIFICATIONS_URL;

    $seriennummer = $data['seriennummer'];

    // Speichern des Ereignisses in der temporären Tabelle
    $stmt = $pdo->prepare("
        INSERT INTO pending_key_actions
            (seriennummer, action_type, timestamp, status)
        VALUES
            (:seriennummer, 'remove', NOW(), 'pending')
    ");

    $stmt->execute([':seriennummer' => $seriennummer]);
    $actionId = $pdo->lastInsertId();

    // Push-Benachrichtigung senden, wenn aktiviert
    if ($PUSH_NOTIFICATIONS_ENABLED['key_removed']) {
        $payload = [
            'title' => $PUSH_NOTIFICATIONS_MESSAGES['key_removed']['title'],
            'body' => $PUSH_NOTIFICATIONS_MESSAGES['key_removed']['body'],
            'data' => [
                'url' => $PUSH_NOTIFICATIONS_URL,
                'event_type' => 'key_removed',
                'seriennummer' => $seriennummer,
                'action_id' => $actionId
            ]
        ];

        // Sende Benachrichtigung an alle Benutzer mit dieser Seriennummer
        sendPushNotificationsForSeriennummer($pdo, $seriennummer, $payload);
    }

    echo json_encode([
        "status" => "success",
        "message" => "Schlüsselentnahme registriert. Warte auf RFID/NFC-Bestätigung.",
        "action_id" => $actionId
    ]);
}

// Funktion zum Verarbeiten des Ereignisses "Schlüssel zurückgegeben"
function handleKeyReturned($pdo, $data) {
    global $PUSH_NOTIFICATIONS_ENABLED, $PUSH_NOTIFICATIONS_MESSAGES, $PUSH_NOTIFICATIONS_URL;

    $seriennummer = $data['seriennummer'];
    $timestamp = date('Y-m-d H:i:s');

    // Suchen des letzten offenen Eintrags für diese Seriennummer
    $stmt = $pdo->prepare("
        SELECT
            kl.box_id,
            kl.timestamp_take,
            kl.benutzername
        FROM
            key_logs kl
        JOIN
            benutzer b ON kl.benutzername = b.benutzername
        WHERE
            b.seriennummer = :seriennummer
            AND kl.timestamp_return IS NULL
        ORDER BY
            kl.timestamp_take DESC
        LIMIT 1
    ");

    $stmt->execute([':seriennummer' => $seriennummer]);
    $lastLog = $stmt->fetch(PDO::FETCH_ASSOC);

    // Prüfen, ob es eine ausstehende Schlüsselentnahme gibt, die nicht verifiziert wurde
    $pendingStmt = $pdo->prepare("
        SELECT id, timestamp
        FROM pending_key_actions
        WHERE seriennummer = :seriennummer
        AND action_type = 'remove'
        AND status = 'pending'
        ORDER BY timestamp DESC
        LIMIT 1
    ");

    $pendingStmt->execute([':seriennummer' => $seriennummer]);
    $pendingAction = $pendingStmt->fetch(PDO::FETCH_ASSOC);

    // Wenn es eine ausstehende Aktion gibt, diese als abgeschlossen markieren mit 'unknown_user'
    if ($pendingAction) {
        $updatePendingStmt = $pdo->prepare("
            UPDATE pending_key_actions
            SET status = 'completed',
                completed_by = 'unknown_user',
                completed_at = :completed_at
            WHERE id = :id
        ");

        $updatePendingStmt->execute([
            ':completed_at' => $timestamp,
            ':id' => $pendingAction['id']
        ]);
    }

    // Wenn es einen offenen key_logs Eintrag gibt, diesen aktualisieren
    if ($lastLog) {
        // Aktualisieren des Eintrags mit der Rückgabezeit
        $stmt = $pdo->prepare("
            UPDATE key_logs
            SET timestamp_return = NOW()
            WHERE box_id = :box_id
            AND benutzername = :benutzername
            AND timestamp_take = :timestamp_take
            AND timestamp_return IS NULL
        ");

        $stmt->execute([
            ':box_id' => $lastLog['box_id'],
            ':benutzername' => $lastLog['benutzername'],
            ':timestamp_take' => $lastLog['timestamp_take']
        ]);

        // Push-Benachrichtigung senden, wenn aktiviert
        if ($PUSH_NOTIFICATIONS_ENABLED['key_returned']) {
            $payload = [
                'title' => $PUSH_NOTIFICATIONS_MESSAGES['key_returned']['title'],
                'body' => $PUSH_NOTIFICATIONS_MESSAGES['key_returned']['body'],
                'data' => [
                    'url' => $PUSH_NOTIFICATIONS_URL,
                    'event_type' => 'key_returned',
                    'seriennummer' => $seriennummer,
                    'benutzername' => $lastLog['benutzername']
                ]
            ];

            // Sende Benachrichtigung an alle Benutzer mit dieser Seriennummer
            sendPushNotificationsForSeriennummer($pdo, $seriennummer, $payload);
        }

        echo json_encode([
            "status" => "success",
            "message" => "Schlüssel erfolgreich zurückgegeben"
        ]);
    } else if ($pendingAction) {
        // Wenn es keinen offenen key_logs Eintrag gibt, aber eine ausstehende Aktion,
        // haben wir die ausstehende Aktion erfolgreich abgeschlossen

        // Push-Benachrichtigung senden, wenn aktiviert
        if ($PUSH_NOTIFICATIONS_ENABLED['key_returned']) {
            $payload = [
                'title' => $PUSH_NOTIFICATIONS_MESSAGES['key_returned']['title'],
                'body' => $PUSH_NOTIFICATIONS_MESSAGES['key_returned']['body'] . ' (Nicht verifizierte Entnahme)',
                'data' => [
                    'url' => $PUSH_NOTIFICATIONS_URL,
                    'event_type' => 'key_returned_unverified',
                    'seriennummer' => $seriennummer
                ]
            ];

            // Sende Benachrichtigung an alle Benutzer mit dieser Seriennummer
            sendPushNotificationsForSeriennummer($pdo, $seriennummer, $payload);
        }

        echo json_encode([
            "status" => "success",
            "message" => "Nicht verifizierte Schlüsselrückgabe erfolgreich registriert"
        ]);
    } else {
        // Kein offener Eintrag und keine ausstehende Aktion
        echo json_encode([
            "status" => "error",
            "message" => "Kein offener Eintrag für diese Seriennummer gefunden"
        ]);
    }
}

// Funktion zum Verarbeiten des Ereignisses "RFID/NFC-Scan"
function handleRfidScan($pdo, $data) {
    global $PUSH_NOTIFICATIONS_ENABLED, $PUSH_NOTIFICATIONS_MESSAGES, $PUSH_NOTIFICATIONS_URL;

    if (!isset($data['rfid_uid'])) {
        echo json_encode(["status" => "error", "message" => "RFID/NFC UID fehlt"]);
        return;
    }

    $seriennummer = $data['seriennummer'];
    $rfidUid = $data['rfid_uid'];

    // Suchen des Benutzers anhand der RFID/NFC UID
    $stmt = $pdo->prepare("
        SELECT
            user_id,
            benutzername,
            vorname,
            nachname
        FROM
            benutzer
        WHERE
            rfid_uid = :rfid_uid
            AND seriennummer = :seriennummer
    ");

    $stmt->execute([
        ':rfid_uid' => $rfidUid,
        ':seriennummer' => $seriennummer
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode([
            "status" => "error",
            "message" => "Kein Benutzer mit dieser RFID/NFC UID gefunden"
        ]);
        return;
    }

    // Suchen des letzten ausstehenden Ereignisses für diese Seriennummer
    $stmt = $pdo->prepare("
        SELECT
            id,
            action_type,
            timestamp
        FROM
            pending_key_actions
        WHERE
            seriennummer = :seriennummer
            AND status = 'pending'
        ORDER BY
            timestamp DESC
        LIMIT 1
    ");

    $stmt->execute([':seriennummer' => $seriennummer]);
    $pendingAction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pendingAction) {
        echo json_encode([
            "status" => "error",
            "message" => "Keine ausstehende Aktion für diese Seriennummer gefunden"
        ]);
        return;
    }

    // Aktualisieren des Status der ausstehenden Aktion
    $stmt = $pdo->prepare("
        UPDATE pending_key_actions
        SET status = 'completed',
            completed_by = :benutzername,
            completed_at = NOW()
        WHERE id = :id
    ");

    $stmt->execute([
        ':id' => $pendingAction['id'],
        ':benutzername' => $user['benutzername']
    ]);

    // Wenn es sich um eine Entnahme handelt, einen neuen Eintrag in key_logs erstellen
    if ($pendingAction['action_type'] === 'remove') {
        // Box-ID ermitteln oder generieren
        $stmt = $pdo->prepare("
            SELECT
                kl.box_id
            FROM
                key_logs kl
            JOIN
                benutzer b ON kl.benutzername = b.benutzername
            WHERE
                b.seriennummer = :seriennummer
            ORDER BY
                kl.timestamp_take DESC
            LIMIT 1
        ");

        $stmt->execute([':seriennummer' => $seriennummer]);
        $lastLog = $stmt->fetch(PDO::FETCH_ASSOC);

        // Immer eine neue Box-ID generieren, um Primärschlüsselkonflikte zu vermeiden
        $boxId = mt_rand(1000, 9999);

        // Neuen Eintrag für die Entnahme erstellen
        $stmt = $pdo->prepare("
            INSERT INTO key_logs
                (box_id, timestamp_take, benutzername)
            VALUES
                (:box_id, NOW(), :benutzername)
        ");

        $stmt->execute([
            ':box_id' => $boxId,
            ':benutzername' => $user['benutzername']
        ]);

        // Push-Benachrichtigung für verifizierte Schlüsselentnahme senden, wenn aktiviert
        if ($PUSH_NOTIFICATIONS_ENABLED['key_removed_verified']) {
            $payload = [
                'title' => $PUSH_NOTIFICATIONS_MESSAGES['key_removed_verified']['title'],
                'body' => str_replace(
                    ['[VORNAME]', '[NACHNAME]', '[BENUTZERNAME]'],
                    [$user['vorname'], $user['nachname'], $user['benutzername']],
                    $PUSH_NOTIFICATIONS_MESSAGES['key_removed_verified']['body']
                ),
                'data' => [
                    'url' => $PUSH_NOTIFICATIONS_URL,
                    'event_type' => 'key_removed_verified',
                    'seriennummer' => $seriennummer,
                    'benutzername' => $user['benutzername']
                ]
            ];

            // Sende Benachrichtigung an alle Benutzer mit dieser Seriennummer
            sendPushNotificationsForSeriennummer($pdo, $seriennummer, $payload);
        }

        echo json_encode([
            "status" => "success",
            "message" => "Schlüsselentnahme durch " . $user['vorname'] . " " . $user['nachname'] . " bestätigt",
            "user" => [
                "benutzername" => $user['benutzername'],
                "name" => $user['vorname'] . " " . $user['nachname']
            ]
        ]);
    } else {
        // Push-Benachrichtigung für RFID-Scan senden, wenn aktiviert
        if ($PUSH_NOTIFICATIONS_ENABLED['rfid_scan']) {
            $payload = [
                'title' => $PUSH_NOTIFICATIONS_MESSAGES['rfid_scan']['title'],
                'body' => str_replace(
                    ['[VORNAME]', '[NACHNAME]', '[BENUTZERNAME]'],
                    [$user['vorname'], $user['nachname'], $user['benutzername']],
                    $PUSH_NOTIFICATIONS_MESSAGES['rfid_scan']['body']
                ),
                'data' => [
                    'url' => $PUSH_NOTIFICATIONS_URL,
                    'event_type' => 'rfid_scan',
                    'seriennummer' => $seriennummer,
                    'benutzername' => $user['benutzername']
                ]
            ];

            // Sende Benachrichtigung an alle Benutzer mit dieser Seriennummer
            sendPushNotificationsForSeriennummer($pdo, $seriennummer, $payload);
        }

        echo json_encode([
            "status" => "success",
            "message" => "RFID/NFC-Scan verarbeitet, aber keine passende Aktion gefunden"
        ]);
    }
}
