<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../common/db.php';

$db = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];
$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true) ?? [];
$action = $_GET['action'] ?? null;
$id = $_GET['id'] ?? null;
$resourceId = $_GET['resource_id'] ?? null;
$commentId = $_GET['comment_id'] ?? null;

function getAllResources(PDO $db): void
{
    $sql = 'SELECT id, title, description, link, created_at FROM resources';
    $search = trim($_GET['search'] ?? '');

    if ($search !== '') {
        $sql .= ' WHERE title LIKE :search OR description LIKE :search';
    }

    $allowedSort = ['title', 'created_at'];
    $sort = $_GET['sort'] ?? 'created_at';
    if (!in_array($sort, $allowedSort, true)) {
        $sort = 'created_at';
    }

    $order = strtolower($_GET['order'] ?? 'desc');
    if (!in_array($order, ['asc', 'desc'], true)) {
        $order = 'desc';
    }

    $sql .= " ORDER BY {$sort} {$order}";
    $stmt = $db->prepare($sql);

    if ($search !== '') {
        $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
    }

    $stmt->execute();
    sendResponse(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function getResourceById(PDO $db, $resourceId): void
{
    if ($resourceId === null || !is_numeric($resourceId)) {
        sendResponse(['success' => false, 'message' => 'Invalid resource id.'], 400);
    }

    $stmt = $db->prepare('SELECT id, title, description, link, created_at FROM resources WHERE id = ?');
    $stmt->execute([(int) $resourceId]);
    $resource = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resource) {
        sendResponse(['success' => false, 'message' => 'Resource not found.'], 404);
    }

    sendResponse(['success' => true, 'data' => $resource]);
}

function createResource(PDO $db, array $data): void
{
    $validation = validateRequiredFields($data, ['title', 'link']);
    if (!$validation['valid']) {
        sendResponse(['success' => false, 'message' => 'Missing required fields: ' . implode(', ', $validation['missing'])], 400);
    }

    $title = sanitizeInput($data['title']);
    $description = sanitizeInput($data['description'] ?? '');
    $link = trim((string) $data['link']);

    if (!validateUrl($link)) {
        sendResponse(['success' => false, 'message' => 'Invalid URL.'], 400);
    }

    $stmt = $db->prepare('INSERT INTO resources (title, description, link) VALUES (?, ?, ?)');
    $stmt->execute([$title, $description, $link]);

    if ($stmt->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Resource created successfully.', 'id' => (int) $db->lastInsertId()], 201);
    }

    sendResponse(['success' => false, 'message' => 'Failed to create resource.'], 500);
}

function updateResource(PDO $db, array $data): void
{
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        sendResponse(['success' => false, 'message' => 'Invalid resource id.'], 400);
    }

    $id = (int) $data['id'];
    $check = $db->prepare('SELECT id FROM resources WHERE id = ?');
    $check->execute([$id]);
    if (!$check->fetch(PDO::FETCH_ASSOC)) {
        sendResponse(['success' => false, 'message' => 'Resource not found.'], 404);
    }

    $fields = [];
    $values = [];

    if (array_key_exists('title', $data)) {
        $title = sanitizeInput($data['title']);
        if ($title === '') {
            sendResponse(['success' => false, 'message' => 'Title cannot be empty.'], 400);
        }
        $fields[] = 'title = ?';
        $values[] = $title;
    }

    if (array_key_exists('description', $data)) {
        $fields[] = 'description = ?';
        $values[] = sanitizeInput($data['description']);
    }

    if (array_key_exists('link', $data)) {
        $link = trim((string) $data['link']);
        if (!validateUrl($link)) {
            sendResponse(['success' => false, 'message' => 'Invalid URL.'], 400);
        }
        $fields[] = 'link = ?';
        $values[] = $link;
    }

    if (empty($fields)) {
        sendResponse(['success' => false, 'message' => 'No fields to update.'], 400);
    }

    $values[] = $id;
    $stmt = $db->prepare('UPDATE resources SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($values);

    sendResponse(['success' => true, 'message' => 'Resource updated successfully.']);
}

function deleteResource(PDO $db, $resourceId): void
{
    if ($resourceId === null || !is_numeric($resourceId)) {
        sendResponse(['success' => false, 'message' => 'Invalid resource id.'], 400);
    }

    $check = $db->prepare('SELECT id FROM resources WHERE id = ?');
    $check->execute([(int) $resourceId]);
    if (!$check->fetch(PDO::FETCH_ASSOC)) {
        sendResponse(['success' => false, 'message' => 'Resource not found.'], 404);
    }

    $stmt = $db->prepare('DELETE FROM resources WHERE id = ?');
    $stmt->execute([(int) $resourceId]);

    if ($stmt->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Resource deleted successfully.']);
    }

    sendResponse(['success' => false, 'message' => 'Failed to delete resource.'], 500);
}

function getCommentsByResourceId(PDO $db, $resourceId): void
{
    if ($resourceId === null || !is_numeric($resourceId)) {
        sendResponse(['success' => false, 'message' => 'Invalid resource id.'], 400);
    }

    $stmt = $db->prepare('SELECT id, resource_id, author, text, created_at FROM comments_resource WHERE resource_id = ? ORDER BY created_at ASC');
    $stmt->execute([(int) $resourceId]);
    sendResponse(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function createComment(PDO $db, array $data): void
{
    $validation = validateRequiredFields($data, ['resource_id', 'author', 'text']);
    if (!$validation['valid']) {
        sendResponse(['success' => false, 'message' => 'Missing required fields: ' . implode(', ', $validation['missing'])], 400);
    }

    if (!is_numeric($data['resource_id'])) {
        sendResponse(['success' => false, 'message' => 'Invalid resource id.'], 400);
    }

    $resourceId = (int) $data['resource_id'];
    $check = $db->prepare('SELECT id FROM resources WHERE id = ?');
    $check->execute([$resourceId]);
    if (!$check->fetch(PDO::FETCH_ASSOC)) {
        sendResponse(['success' => false, 'message' => 'Resource not found.'], 404);
    }

    $author = sanitizeInput($data['author']);
    $text = sanitizeInput($data['text']);

    $stmt = $db->prepare('INSERT INTO comments_resource (resource_id, author, text) VALUES (?, ?, ?)');
    $stmt->execute([$resourceId, $author, $text]);

    if ($stmt->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Comment created successfully.', 'id' => (int) $db->lastInsertId()], 201);
    }

    sendResponse(['success' => false, 'message' => 'Failed to create comment.'], 500);
}

function deleteComment(PDO $db, $commentId): void
{
    if ($commentId === null || !is_numeric($commentId)) {
        sendResponse(['success' => false, 'message' => 'Invalid comment id.'], 400);
    }

    $check = $db->prepare('SELECT id FROM comments_resource WHERE id = ?');
    $check->execute([(int) $commentId]);
    if (!$check->fetch(PDO::FETCH_ASSOC)) {
        sendResponse(['success' => false, 'message' => 'Comment not found.'], 404);
    }

    $stmt = $db->prepare('DELETE FROM comments_resource WHERE id = ?');
    $stmt->execute([(int) $commentId]);

    if ($stmt->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Comment deleted successfully.']);
    }

    sendResponse(['success' => false, 'message' => 'Failed to delete comment.'], 500);
}

try {
    if ($method === 'GET') {
        if ($action === 'comments') {
            getCommentsByResourceId($db, $resourceId);
        } elseif ($id !== null) {
            getResourceById($db, $id);
        } else {
            getAllResources($db);
        }
    } elseif ($method === 'POST') {
        if ($action === 'comment') {
            createComment($db, $data);
        } else {
            createResource($db, $data);
        }
    } elseif ($method === 'PUT') {
        updateResource($db, $data);
    } elseif ($method === 'DELETE') {
        if ($action === 'delete_comment') {
            deleteComment($db, $commentId);
        } else {
            deleteResource($db, $id);
        }
    } else {
        sendResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    sendResponse(['success' => false, 'message' => 'Internal server error.'], 500);
} catch (Exception $e) {
    error_log($e->getMessage());
    sendResponse(['success' => false, 'message' => 'Internal server error.'], 500);
}

function sendResponse($data, $statusCode = 200): void
{
    http_response_code($statusCode);
    if (!is_array($data)) {
        $data = ['success' => false, 'message' => (string) $data];
    }
    echo json_encode($data);
    exit;
}

function validateUrl($url): bool
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function sanitizeInput($data): string
{
    return htmlspecialchars(strip_tags(trim((string) $data)), ENT_QUOTES, 'UTF-8');
}

function validateRequiredFields($data, $requiredFields): array
{
    $missing = [];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
            $missing[] = $field;
        }
    }
    return ['valid' => count($missing) === 0, 'missing' => $missing];
}
?>
