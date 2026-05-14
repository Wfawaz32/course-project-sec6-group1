<?php
/**
 * Assignment Management API
 *
 * RESTful API for CRUD operations on course assignments and their
 * discussion comments. Uses PDO to interact with the MySQL database
 * defined in schema.sql.
 *
 * Database Tables (ground truth: schema.sql):
 *
 * Table: assignments
 *   id          INT UNSIGNED  PRIMARY KEY AUTO_INCREMENT
 *   title       VARCHAR(200)  NOT NULL
 *   description TEXT
 *   due_date    DATE          NOT NULL
 *   files       TEXT          — JSON-encoded array of file URL strings
 *   created_at  TIMESTAMP
 *   updated_at  TIMESTAMP     — updated automatically by MySQL ON UPDATE
 *
 * Table: comments_assignment
 *   id            INT UNSIGNED  PRIMARY KEY AUTO_INCREMENT
 *   assignment_id INT UNSIGNED  NOT NULL — FK → assignments.id (ON DELETE CASCADE)
 *   author        VARCHAR(100)  NOT NULL
 *   text          TEXT          NOT NULL
 *   created_at    TIMESTAMP
 *
 * HTTP Methods Supported:
 *   GET    — Retrieve assignment(s) or comments
 *   POST   — Create a new assignment or comment
 *   PUT    — Update an existing assignment
 *   DELETE — Delete an assignment (cascade removes its comments) or a comment
 *
 * URL scheme (all requests go to index.php):
 *
 *   Assignments:
 *     GET    ./api/index.php                  — list all assignments
 *     GET    ./api/index.php?id={id}           — get one assignment by integer id
 *     POST   ./api/index.php                  — create a new assignment
 *     PUT    ./api/index.php                  — update an assignment (id in JSON body)
 *     DELETE ./api/index.php?id={id}           — delete an assignment
 *
 *   Comments (action parameter selects the comments sub-resource):
 *     GET    ./api/index.php?action=comments&assignment_id={id}
 *                                             — list comments for an assignment
 *     POST   ./api/index.php?action=comment   — create a comment
 *     DELETE ./api/index.php?action=delete_comment&comment_id={id}
 *                                             — delete a single comment
 *
 * Query parameters for GET all assignments:
 *   search — filter rows where title LIKE or description LIKE the term
 *   sort   — column to sort by; allowed: title, due_date, created_at
 *            (default: due_date)
 *   order  — sort direction; allowed: asc, desc (default: asc)
 *
 * Response format: JSON
 *   Success: { "success": true,  "data": ... }
 *   Error:   { "success": false, "message": "..." }
 */

// ============================================================================
// HEADERS AND INITIALIZATION
// ============================================================================

// Set headers for JSON response and CORS.
// Set Content-Type to application/json.
// Allow cross-origin requests (CORS) if needed.
// Allow HTTP methods: GET, POST, PUT, DELETE, OPTIONS.
// Allow headers: Content-Type, Authorization.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request.
// If the request method is OPTIONS, return HTTP 200 and exit.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include the shared database connection file.
require_once __DIR__ . '/../../common/db.php';

// Get the PDO database connection.
$db = getDBConnection();

// Read the HTTP request method.
$method = $_SERVER['REQUEST_METHOD'];

// Read and decode the request body for POST and PUT requests.
$rawData = file_get_contents('php://input');
$data    = json_decode($rawData, true) ?? [];

// Read query parameters.
$action       = $_GET['action']        ?? null;  // 'comments', 'comment', 'delete_comment'
$id           = $_GET['id']            ?? null;  // integer assignment id
$assignmentId = $_GET['assignment_id'] ?? null;  // integer assignment id for comments queries
$commentId    = $_GET['comment_id']    ?? null;  // integer comment id


// ============================================================================
// ASSIGNMENT FUNCTIONS
// ============================================================================

/**
 * Get all assignments (with optional search and sort).
 * Method: GET (no ?id or ?action parameter).
 *
 * Query parameters handled inside:
 *   search — filter by title LIKE or description LIKE
 *   sort   — allowed: title, due_date, created_at   (default: due_date)
 *   order  — allowed: asc, desc                     (default: asc)
 *
 * Each assignment row in the response has the files column decoded from
 * its JSON string to a PHP array before encoding the final JSON output.
 */
function getAllAssignments(PDO $db): void
{
    // Build the base SELECT query.
    $query = "SELECT id, title, description, due_date, files, created_at, updated_at FROM assignments";
    $params = [];

    // If $_GET['search'] is provided and non-empty, append WHERE conditions.
    $search = $_GET['search'] ?? '';
    if (!empty($search)) {
        $query .= " WHERE title LIKE :search OR description LIKE :search";
        $params[':search'] = '%' . $search . '%';
    }

    // Validate $_GET['sort'] against the whitelist [title, due_date, created_at].
    // Default to 'due_date' if missing or invalid.
    $validSorts = ['title', 'due_date', 'created_at'];
    $sort = $_GET['sort'] ?? 'due_date';
    if (!in_array($sort, $validSorts)) {
        $sort = 'due_date';
    }

    // Validate $_GET['order'] against [asc, desc].
    // Default to 'asc' if missing or invalid.
    $validOrders = ['asc', 'desc'];
    $order = strtolower($_GET['order'] ?? 'asc');
    if (!in_array($order, $validOrders)) {
        $order = 'asc';
    }

    // Append ORDER BY {sort} {order} to the query.
    $query .= " ORDER BY {$sort} {$order}";

    // Prepare, bind (if searching), and execute the statement.
    $stmt = $db->prepare($query);
    $stmt->execute($params);

    // Fetch all rows as an associative array.
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each row, decode the files column:
    foreach ($assignments as &$row) {
        $row['files'] = json_decode($row['files'], true) ?? [];
    }

    // Call sendResponse
    sendResponse(['success' => true, 'data' => $assignments]);
}


/**
 * Get a single assignment by its integer primary key.
 * Method: GET with ?id={id}.
 *
 * Response (found):
 *   { "success": true, "data": { id, title, description, due_date,
 *                                 files, created_at, updated_at } }
 * Response (not found): HTTP 404.
 */
function getAssignmentById(PDO $db, $id): void
{
    // Validate that $id is provided and numeric.
    if (!$id || !is_numeric($id)) {
        sendResponse(['success' => false, 'message' => 'Invalid or missing assignment ID.'], 400);
    }

    // SELECT id, title, description, due_date, files, created_at, updated_at FROM assignments WHERE id = ?
    $stmt = $db->prepare("SELECT id, title, description, due_date, files, created_at, updated_at FROM assignments WHERE id = ?");
    $stmt->execute([$id]);

    // Fetch one row.
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    // If found, sendResponse success with the assignment.
    if ($assignment) {
        // Decode the files JSON
        $assignment['files'] = json_decode($assignment['files'], true) ?? [];
        sendResponse(['success' => true, 'data' => $assignment]);
    } else {
        // If not found, sendResponse error with HTTP 404.
        sendResponse(['success' => false, 'message' => 'Assignment not found.'], 404);
    }
}


/**
 * Create a new assignment.
 * Method: POST (no ?action parameter).
 *
 * Required JSON body fields:
 *   title       — string (required)
 *   description — string (required)
 *   due_date    — string "YYYY-MM-DD" (required)
 *   files       — array of URL strings (optional, defaults to [])
 *
 * Response (success): HTTP 201 — { success, message, id }
 * Response (missing fields or invalid due_date): HTTP 400.
 *
 * Note: id, created_at, and updated_at are handled automatically by MySQL.
 */
function createAssignment(PDO $db, array $data): void
{
    // Validate that title, description, and due_date are present
    // Trim title, description, and due_date.
    $title       = isset($data['title'])       ? trim((string)$data['title'])       : '';
    $description = isset($data['description']) ? trim((string)$data['description']) : '';
    $due_date    = isset($data['due_date'])    ? trim((string)$data['due_date'])    : '';

    // If missing, sendResponse HTTP 400.
    if ($title === '' || $description === '' || $due_date === '') {
        sendResponse(['success' => false, 'message' => 'Title, description, and due_date are required.'], 400);
    }

    // Validate due_date format using DateTime::createFromFormat('Y-m-d', $due_date).
    if (!validateDate($due_date)) {
        sendResponse(['success' => false, 'message' => 'Invalid due_date format. Must be YYYY-MM-DD.'], 400);
    }

    // Handle files: if provided and is an array, json_encode it.
    // Otherwise use json_encode([]).
    $files = (isset($data['files']) && is_array($data['files'])) ? $data['files'] : [];
    $filesJson = json_encode($files);

    // INSERT INTO assignments (title, description, due_date, files) VALUES (?, ?, ?, ?)
    $stmt = $db->prepare("INSERT INTO assignments (title, description, due_date, files) VALUES (?, ?, ?, ?)");
    $stmt->execute([$title, $description, $due_date, $filesJson]);

    // If rowCount() > 0, sendResponse HTTP 201 with the new integer id from $db->lastInsertId().
    if ($stmt->rowCount() > 0) {
        $newId = (int)$db->lastInsertId();
        sendResponse(['success' => true, 'message' => 'Assignment created successfully.', 'id' => $newId], 201);
    } else {
        sendResponse(['success' => false, 'message' => 'Failed to create assignment.'], 500);
    }
}


/**
 * Update an existing assignment.
 * Method: PUT.
 *
 * Required JSON body:
 *   id — integer primary key of the assignment to update (required).
 * Optional JSON body fields (at least one must be present):
 *   title, description, due_date, files.
 *
 * Response (success): HTTP 200.
 * Response (not found): HTTP 404.
 * Response (invalid due_date): HTTP 400.
 *
 * Note: updated_at is refreshed automatically by MySQL ON UPDATE CURRENT_TIMESTAMP.
 */
function updateAssignment(PDO $db, array $data): void
{
    // Validate that $data['id'] is present.
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        sendResponse(['success' => false, 'message' => 'Invalid or missing assignment ID.'], 400);
    }

    $id = (int)$data['id'];

    // Check that an assignment with this id exists.
    $stmt = $db->prepare("SELECT id FROM assignments WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        sendResponse(['success' => false, 'message' => 'Assignment not found.'], 404);
    }

    $clauses = [];
    $params = [];

    // Dynamically build the SET clause for whichever of
    // title, description, due_date, files are present in $data.
    if (array_key_exists('title', $data)) {
        $clauses[] = "title = ?";
        $params[] = trim((string)$data['title']);
    }

    if (array_key_exists('description', $data)) {
        $clauses[] = "description = ?";
        $params[] = trim((string)$data['description']);
    }

    if (array_key_exists('due_date', $data)) {
        $due_date = trim((string)$data['due_date']);
        if (!validateDate($due_date)) {
            sendResponse(['success' => false, 'message' => 'Invalid due_date format. Must be YYYY-MM-DD.'], 400);
        }
        $clauses[] = "due_date = ?";
        $params[] = $due_date;
    }

    if (array_key_exists('files', $data)) {
        $files = is_array($data['files']) ? $data['files'] : [];
        $clauses[] = "files = ?";
        $params[] = json_encode($files);
    }

    // If no updatable fields are present, sendResponse HTTP 400.
    if (empty($clauses)) {
        sendResponse(['success' => false, 'message' => 'No updatable fields provided.'], 400);
    }

    // Build: UPDATE assignments SET {clauses} WHERE id = ?
    $query = "UPDATE assignments SET " . implode(", ", $clauses) . " WHERE id = ?";
    $params[] = $id;

    // Prepare, bind all SET values, then bind id, and execute.
    $stmt = $db->prepare($query);
    
    // sendResponse HTTP 200 on success, HTTP 500 on failure.
    if ($stmt->execute($params)) {
        sendResponse(['success' => true, 'message' => 'Assignment updated successfully.']);
    } else {
        sendResponse(['success' => false, 'message' => 'Failed to update assignment.'], 500);
    }
}


/**
 * Delete an assignment by integer id.
 * Method: DELETE with ?id={id}.
 *
 * The ON DELETE CASCADE constraint on comments_assignment.assignment_id
 * automatically removes all comments for this assignment — no manual
 * deletion of comments is needed.
 *
 * Response (success): HTTP 200.
 * Response (not found): HTTP 404.
 */
function deleteAssignment(PDO $db, $id): void
{
    // Validate that $id is provided and numeric.
    if (!$id || !is_numeric($id)) {
        sendResponse(['success' => false, 'message' => 'Invalid or missing assignment ID.'], 400);
    }

    // Check that an assignment with this id exists.
    $stmt = $db->prepare("SELECT id FROM assignments WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        sendResponse(['success' => false, 'message' => 'Assignment not found.'], 404);
    }

    // DELETE FROM assignments WHERE id = ?
    $stmt = $db->prepare("DELETE FROM assignments WHERE id = ?");
    $stmt->execute([$id]);

    // If rowCount() > 0, sendResponse HTTP 200.
    if ($stmt->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Assignment deleted successfully.']);
    } else {
        sendResponse(['success' => false, 'message' => 'Failed to delete assignment.'], 500);
    }
}


// ============================================================================
// COMMENTS FUNCTIONS
// ============================================================================

/**
 * Get all comments for a specific assignment.
 * Method: GET with ?action=comments&assignment_id={id}.
 *
 * Reads from the comments_assignment table.
 * Returns an empty data array if no comments exist — not an error.
 *
 * Each comment object: { id, assignment_id, author, text, created_at }
 */
function getCommentsByAssignment(PDO $db, $assignmentId): void
{
    // Validate that $assignmentId is provided and numeric.
    if (!$assignmentId || !is_numeric($assignmentId)) {
        sendResponse(['success' => false, 'message' => 'Invalid or missing assignment ID.'], 400);
    }

    // SELECT id, assignment_id, author, text, created_at FROM comments_assignment
    $stmt = $db->prepare("SELECT id, assignment_id, author, text, created_at FROM comments_assignment WHERE assignment_id = ? ORDER BY created_at ASC");
    $stmt->execute([$assignmentId]);

    // Fetch all rows. Return sendResponse with the array (empty array is valid).
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    sendResponse(['success' => true, 'data' => $comments]);
}


/**
 * Create a new comment.
 * Method: POST with ?action=comment.
 *
 * Required JSON body:
 *   assignment_id — integer FK into assignments.id (required)
 *   author        — string (required)
 *   text          — string (required, must be non-empty after trim)
 *
 * Response (success): HTTP 201 — { success, message, id, data: comment }
 * Response (assignment not found): HTTP 404.
 * Response (missing fields): HTTP 400.
 */
function createComment(PDO $db, array $data): void
{
    // Validate that assignment_id, author, and text are all present
    $assignment_id = isset($data['assignment_id']) ? $data['assignment_id'] : null;
    $author        = isset($data['author']) ? trim((string)$data['author']) : '';
    $text          = isset($data['text']) ? trim((string)$data['text']) : '';

    if ($assignment_id === null || $author === '' || $text === '') {
        sendResponse(['success' => false, 'message' => 'assignment_id, author, and text are required.'], 400);
    }

    // Validate that assignment_id is numeric.
    if (!is_numeric($assignment_id)) {
        sendResponse(['success' => false, 'message' => 'assignment_id must be numeric.'], 400);
    }

    // Check that an assignment with this id exists in the assignments table.
    $stmt = $db->prepare("SELECT id FROM assignments WHERE id = ?");
    $stmt->execute([$assignment_id]);
    if (!$stmt->fetch()) {
        sendResponse(['success' => false, 'message' => 'Assignment not found.'], 404);
    }

    // INSERT INTO comments_assignment (assignment_id, author, text) VALUES (?, ?, ?)
    $stmt = $db->prepare("INSERT INTO comments_assignment (assignment_id, author, text) VALUES (?, ?, ?)");
    $stmt->execute([$assignment_id, $author, $text]);

    // If rowCount() > 0, sendResponse HTTP 201 with the new id and the full new comment object.
    if ($stmt->rowCount() > 0) {
        $newId = (int)$db->lastInsertId();
        
        $stmt = $db->prepare("SELECT id, assignment_id, author, text, created_at FROM comments_assignment WHERE id = ?");
        $stmt->execute([$newId]);
        $newComment = $stmt->fetch(PDO::FETCH_ASSOC);

        sendResponse(['success' => true, 'message' => 'Comment created successfully.', 'id' => $newId, 'data' => $newComment], 201);
    } else {
        sendResponse(['success' => false, 'message' => 'Failed to create comment.'], 500);
    }
}


/**
 * Delete a single comment.
 * Method: DELETE with ?action=delete_comment&comment_id={id}.
 *
 * Response (success): HTTP 200.
 * Response (not found): HTTP 404.
 */
function deleteComment(PDO $db, $commentId): void
{
    // Validate that $commentId is provided and numeric.
    if (!$commentId || !is_numeric($commentId)) {
        sendResponse(['success' => false, 'message' => 'Invalid or missing comment ID.'], 400);
    }

    // Check that the comment exists in comments_assignment.
    $stmt = $db->prepare("SELECT id FROM comments_assignment WHERE id = ?");
    $stmt->execute([$commentId]);
    if (!$stmt->fetch()) {
        sendResponse(['success' => false, 'message' => 'Comment not found.'], 404);
    }

    // DELETE FROM comments_assignment WHERE id = ?
    $stmt = $db->prepare("DELETE FROM comments_assignment WHERE id = ?");
    $stmt->execute([$commentId]);

    // If rowCount() > 0, sendResponse HTTP 200.
    if ($stmt->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Comment deleted successfully.']);
    } else {
        sendResponse(['success' => false, 'message' => 'Failed to delete comment.'], 500);
    }
}


// ============================================================================
// MAIN REQUEST ROUTER
// ============================================================================

try {

    if ($method === 'GET') {

        // ?action=comments&assignment_id={id} → list comments for an assignment
        if ($action === 'comments') {
            getCommentsByAssignment($db, $assignmentId);
        }
        // ?id={id} → single assignment
        elseif ($id !== null) {
            getAssignmentById($db, $id);
        }
        // no parameters → all assignments (supports ?search, ?sort, ?order)
        else {
            getAllAssignments($db);
        }

    } elseif ($method === 'POST') {

        // ?action=comment → create a comment in comments_assignment
        if ($action === 'comment') {
            createComment($db, $data);
        }
        // no action → create a new assignment
        else {
            createAssignment($db, $data);
        }

    } elseif ($method === 'PUT') {

        // Update an assignment; id comes from the JSON body
        updateAssignment($db, $data);

    } elseif ($method === 'DELETE') {

        // ?action=delete_comment&comment_id={id} → delete one comment
        if ($action === 'delete_comment') {
            deleteComment($db, $commentId);
        }
        // ?id={id} → delete an assignment (and its comments via CASCADE)
        else {
            deleteAssignment($db, $id);
        }

    } else {
        // sendResponse HTTP 405 Method Not Allowed.
        sendResponse(['success' => false, 'message' => 'Method Not Allowed.'], 405);
    }

} catch (PDOException $e) {
    // Log the error with error_log().
    // Return a generic HTTP 500 — do NOT expose $e->getMessage() to clients.
    error_log("Database Error: " . $e->getMessage());
    sendResponse(['success' => false, 'message' => 'A database error occurred.'], 500);

} catch (Exception $e) {
    // Log the error with error_log().
    // Return HTTP 500 using sendResponse().
    error_log("General Error: " . $e->getMessage());
    sendResponse(['success' => false, 'message' => 'An unexpected error occurred.'], 500);
}


// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Send a JSON response and stop execution.
 *
 * @param array $data        Must include a 'success' key.
 * @param int   $statusCode  HTTP status code (default 200).
 */
function sendResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}


/**
 * Validate a date string against the "YYYY-MM-DD" format.
 *
 * @param  string $date
 * @return bool  True if valid, false otherwise.
 */
function validateDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}


/**
 * Sanitize a string input.
 *
 * @param  string $data
 * @return string  Trimmed, tag-stripped, HTML-encoded string.
 */
function sanitizeInput(string $data): string
{
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}