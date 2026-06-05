<?php
// Initialize session tracking
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting to catch issues instantly
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");

try {
    //$db = new PDO('mysql:host=localhost;dbname=u205332161_wps_claims', 'u205332161_wps', 'J4I~C9;d');
    $db = new PDO('mysql:host=localhost;dbname=wps_claims', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        $data = json_decode(file_get_contents('php://input'), true);
        $email = trim($data['email'] ?? '');
        $password = trim($data['password'] ?? '');

        if (!$email || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Please enter both email and password.']);
            exit;
        }

        $stmt = $db->prepare("SELECT id, password_hash, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            
            // Backup validation: Save user ID to session storage file manually if cookies lapse
            echo json_encode([
                'status' => 'success', 
                'role' => $user['role'],
                'user_id' => $user['id']
            ]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid corporate email or password.']);
        }
        break;

    case 'logout':
        session_destroy();
        echo json_encode(['status' => 'success']);
        break;

    case 'me':
        $sessionUserId = $_SESSION['user_id'] ?? null;
        if (!$sessionUserId) {
            echo json_encode(['status' => 'unauthenticated']);
            exit;
        }
        $stmt = $db->prepare("SELECT id, email, first_name, last_name, account_no, role FROM users WHERE id = ?");
        $stmt->execute([$sessionUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'user' => $user]);
        break;

    case 'rates':
        $ch = curl_init('https://open.er-api.com/v6/latest/AED');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            echo json_encode(['rates' => [
                'USD' => $data['rates']['USD'], 'EUR' => $data['rates']['EUR'],
                'TRY' => $data['rates']['TRY'], 'CNY' => $data['rates']['CNY'], 'AED' => 1
            ]]);
        } else {
            echo json_encode(['rates' => ['USD' => 0.27, 'EUR' => 0.25, 'TRY' => 8.75, 'CNY' => 1.97, 'AED' => 1]]);
        }
        break;

    case 'get_latest_draft':
        $sessionUserId = $_SESSION['user_id'] ?? null;
        if (!$sessionUserId) { die(json_encode(['error' => 'Unauthenticated'])); }

        $stmt = $db->prepare("SELECT id, event_name, total_aed FROM claims WHERE user_id = ? AND status = 'draft' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$sessionUserId]);
        $claim = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($claim) {
            $itemStmt = $db->prepare("SELECT category, cost_type_name, cost_type_nr, pillar_name, expense_date, description, country, receipt_no, original_amount, original_currency, exchange_rate, aed_amount FROM claim_items WHERE claim_id = ?");
            $itemStmt->execute([$claim['id']]);
            $claim['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'claim' => $claim]);
        } else {
            echo json_encode(['status' => 'none']);
        }
        break;

    case 'discard_draft':
        $claimId = $_GET['id'] ?? null;
        if ($claimId) {
            $db->prepare("DELETE FROM claims WHERE id = ? AND status = 'draft'")->execute([$claimId]);
        }
        echo json_encode(['status' => 'success']);
        break;

    case 'autosave':
        $data = json_decode(file_get_contents('php://input'), true);
        $claimId = $data['id'] ?? null;
        $sessionUserId = $_SESSION['user_id'] ?? null;
        if (!$sessionUserId) { die(json_encode(['error' => 'Unauthenticated'])); }

        $db->beginTransaction();
        try {
            if ($claimId) {
                $checkStmt = $db->prepare("SELECT status FROM claims WHERE id = ?");
                $checkStmt->execute([$claimId]);
                if ($checkStmt->fetchColumn() !== 'draft') {
                    echo json_encode(['status' => 'ignored', 'message' => 'Claim locked.']);
                    exit;
                }
                $stmt = $db->prepare("UPDATE claims SET event_name = ?, total_aed = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$data['event_name'] ?? '', $data['total_aed'], $claimId]);
            } else {
                $stmt = $db->prepare("INSERT INTO claims (user_id, event_name, status, total_aed) VALUES (?, ?, 'draft', ?)");
                $stmt->execute([$sessionUserId, $data['event_name'] ?? '', $data['total_aed']]);
                $claimId = $db->lastInsertId();
            }

            $db->prepare("DELETE FROM claim_items WHERE claim_id = ?")->execute([$claimId]);
            $insertItem = $db->prepare("INSERT INTO claim_items (claim_id, category, cost_type_name, cost_type_nr, pillar_name, expense_date, description, country, receipt_no, original_amount, original_currency, exchange_rate, aed_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($data['items'] as $item) {
                $insertItem->execute([
                    $claimId, $item['category'] ?? '', $item['cost_type_name'] ?? '', $item['cost_type_nr'] ?? '', $item['pillar_name'] ?? '',
                    $item['expense_date'] ?? date('Y-m-d'), $item['description'] ?? '', $item['country'] ?? 'UAE', $item['receipt_no'] ?? '',
                    $item['original_amount'] ?? 0, $item['original_currency'] ?? 'AED', $item['exchange_rate'] ?? 1.0, $item['aed_amount'] ?? 0
                ]);
            }
            $db->commit();
            echo json_encode(['status' => 'success', 'id' => $claimId]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'submit':
        $claimId = $_POST['claim_id'] ?? null;
        if (!$claimId) die(json_encode(['error' => 'No active draft token found.']));

        $stmt = $db->prepare("UPDATE claims SET status = 'pending' WHERE id = ? AND status = 'draft'");
        $stmt->execute([$claimId]);

        $uploadedFiles = [];
        if (!empty($_FILES['receipts']['name'][0])) {
            $uploadDir = __DIR__ . '/uploads/';
            if(!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $allowedMimeTypes = ['image/jpeg', 'image/png', 'application/pdf'];
            
            foreach($_FILES['receipts']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['receipts']['error'][$key] !== UPLOAD_ERR_OK) continue;
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $tmp_name);
                finfo_close($finfo);

                if (!in_array($mimeType, $allowedMimeTypes)) continue;

                $ext = ($mimeType === 'application/pdf') ? 'pdf' : 'jpg';
                $filename = uniqid('rcpt_', true) . '.' . $ext;
                
                if (move_uploaded_file($tmp_name, $uploadDir . $filename)) {
                    $uploadedFiles[] = $filename;
                }
            }
        }

        if (!empty($uploadedFiles)) {
            $filesString = implode(',', $uploadedFiles);
            $updateReceipts = $db->prepare("UPDATE claim_items SET receipt_path = ? WHERE claim_id = ?");
            $updateReceipts->execute([$filesString, $claimId]);
        }

        echo json_encode(['status' => 'success', 'message' => 'Claim submitted successfully.']);
        break;
    case 'my_claims':
        $sessionUserId = $_SESSION['user_id'] ?? null;
        if (!$sessionUserId) { die(json_encode(['error' => 'Unauthenticated'])); }
        $stmt = $db->prepare("SELECT id, event_name, total_aed, status, finance_comments, updated_at FROM claims WHERE user_id = ? AND status != 'draft' ORDER BY updated_at DESC");
        $stmt->execute([$sessionUserId]);
        $claims = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'claims' => $claims]);
        break;

    case 'finance_claims':
        if (($_SESSION['user_role'] ?? '') !== 'finance') { die(json_encode(['error' => 'Unauthorized'])); }
        $stmt = $db->query("SELECT c.id, c.event_name, c.total_aed, c.status, c.finance_comments, c.updated_at, u.first_name, u.last_name FROM claims c JOIN users u ON c.user_id = u.id WHERE c.status IN ('pending', 'under review', 'approved', 'declined') ORDER BY c.updated_at DESC");
        $claims = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'claims' => $claims]);
        break;

    case 'review_claim':
        if (($_SESSION['user_role'] ?? '') !== 'finance') { die(json_encode(['error' => 'Unauthorized'])); }
        $data = json_decode(file_get_contents('php://input'), true);
        $claimId = $data['claim_id'] ?? null;
        $newStatus = $data['status'] ?? null;
        $comments = $data['comments'] ?? '';

        if (!$claimId || !in_array($newStatus, ['pending', 'under review', 'approved', 'declined'])) {
            http_response_code(400); die(json_encode(['error' => 'Invalid parameters.']));
        }

        try {
            $stmt = $db->prepare("UPDATE claims SET status = ?, finance_comments = ?, reviewed_at = NOW() WHERE id = ? AND status != 'draft'");
            $stmt->execute([$newStatus, $comments, $claimId]);
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'export_csv':
        if (($_SESSION['user_role'] ?? '') !== 'finance') { die("Unauthorized"); }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=wps_claims_export_' . date('Ymd_His') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Claim Ref', 'Claimant Name', 'Account No', 'Event Name', 'Status', 'Finance Comments', 'Expense Date', 'Main Category', 'Cost Type Name', 'GL Code (Nr)', 'Pillar', 'Description', 'Country', 'Receipt No', 'Original Amount', 'Currency', 'Exchange Rate', 'AED Amount', 'Receipt Files']);

        $stmt = $db->query("SELECT c.id, CONCAT(u.last_name, ', ', u.first_name), u.account_no, c.event_name, c.status, c.finance_comments, i.expense_date, i.category, i.cost_type_name, i.cost_type_nr, i.pillar_name, i.description, i.country, i.receipt_no, i.original_amount, i.original_currency, i.exchange_rate, i.aed_amount, i.receipt_path FROM claims c JOIN users u ON c.user_id = u.id JOIN claim_items i ON c.id = i.claim_id WHERE c.status IN ('pending', 'under review', 'approved', 'declined') ORDER BY c.id ASC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { fputcsv($output, $row); }
        fclose($output);
        exit;
}