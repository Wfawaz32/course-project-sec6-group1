<?php
/**
 * Weekly Course Breakdown API
 *
 * RESTful API for CRUD operations on weekly course content and discussion
 * comments. Uses PDO to interact with the MySQL database defined in
 * schema.sql.
 *
 * Database Tables (ground truth: schema.sql):
 *
 * Table: weeks
 *   id          INT UNSIGNED  PRIMARY KEY AUTO_INCREMENT
 *   title       VARCHAR(200)  NOT NULL
 *   start_date  DATE          NOT NULL
 *   description TEXT
 *   links       TEXT          — JSON-encoded array of URL strings
 *   created_at  TIMESTAMP
 *   updated_at  TIMESTAMP
 *
 * Table: comments_week
 *   id          INT UNSIGNED  PRIMARY KEY AUTO_INCREMENT
 *   week_id     INT UNSIGNED  NOT NULL   — FK → weeks.id (ON DELETE CASCADE)
 *   author      VARCHAR(100)  NOT NULL
 *   text        TEXT          NOT NULL
 *   created_at  TIMESTAMP
 *
 * HTTP Methods Supported:
 *   GET    — Retrieve week(s) or comments
 *   POST   — Create a new week or comment
 *   PUT    — Update an existing week
 *   DELETE — Delete a week (cascade removes its comments) or a single comment
 *
 * URL scheme (all requests go to index.php):
 *
 *   Weeks:
 *     GET    ./api/index.php                  — list all weeks
 *     GET    ./api/index.php?id={id}           — get one week by integer id
 *     POST   ./api/index.php                  — create a new week
 *     PUT    ./api/index.php                  — update a week (id in JSON body)
 *     DELETE ./api/index.php?id={id}           — delete a week
 *
 *   Comments (action parameter selects the comments sub-resource):
 *     GET    ./api/index.php?action=comments&week_id={id}
 *                                             — list comments for a week
 *     POST   ./api/index.php?action=comment   — create a comment
 *     DELETE ./api/index.php?action=delete_comment&comment_id={id}
 *                                             — delete a single comment
 *
 * Query parameters for GET all weeks:
 *   search — filter rows where title LIKE or description LIKE the term
 *   sort   — column to sort by; allowed: title, start_date (default: start_date)
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
$data    = json_decode($rawData, true) ??[];

// Read query parameters.
$action    = $_GET['action']     ?? null;  // 'comments', 'comment', 'delete_comment'
$id        = $_GET['id']         ?? null;  // integer week id
$weekId    = $_GET['week_id']    ?? null;  // integer week id for comments queries
$commentId = $_GET['comment_id'] ?? null;  // integer comment id


// ============================================================================
// WEEKS FUNCTIONS
// ============================================================================

/**
 * Get all weeks (with optional search and sort).
 * Method: GET (no ?id or ?action parameter).
 *
 * Query parameters handled inside:
 *   search — filter by title LIKE or description LIKE
 *   sort   — allowed: title, start_date   (default: start_date)
 *   order  — allowed: asc, desc           (default: asc)
 *
 * Each week row in the response has links decoded from its JSON string
 * to a PHP array before encoding the final JSON output.
 */
function getAllWeeks(PDO $db): void
{
    // Build the base SELECT query.
    $sql = "SELECT id, title, start_date, description, links, created_at FROM weeks";
    
    // If $_GET['search'] is provided and non-empty, append:
    // WHERE title LIKE :search OR description LIKE :search
    // Bind '%' . $search . '%' to :search.
    $search = trim($_GET['search'] ?? '');
    $params =[];
    if ($search !== '') {
        $sql .= " WHERE title LIKE :search OR description LIKE :search";
        $params[':search'] = '%' . $search . '%';
    }

    // Validate $_GET['sort'] against the whitelist [title, start_date].
    // Default to 'start_date' if missing or invalid.
    $sort = $_GET['sort'] ?? 'start_date';
    if (!in_array($sort, ['title', 'start_date'])) {
        $sort = 'start_date';
    }

    // Validate $_GET['order'] against [asc, desc].
    // Default to 'asc' if missing or invalid.
    $order = strtolower($_GET['order'] ?? 'asc');
    if (!in_array($order, ['asc', 'desc'])) {
        $order = 'asc';
    }

    // Append ORDER BY {sort} {order} to the query.
    $sql .= " ORDER BY {$sort} " . strtoupper($order);

    // Prepare, bind (if searching), and execute the statement.
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    // Fetch all rows as an associative array.
    $weeks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each row, decode the links column:
    foreach ($weeks as &$row) {
        $row['links'] = json_decode($row['links'], true) ??[];
    }
    unset($row);

    // Call sendResponse(['success' => true, 'data' => $weeks]);
    sendResponse(['success' => true, 'data' => $weeks]);
}


/**
 * Get a single week by its integer primary key.
 * Method: GET with ?id={id}.
 *
 * Response (found):
 *   { "success": true, "data": { id, title, start_date, description,
 *                                 links, created_at } }
 * Response (not found): HTTP 404.
 */
function getWeekById(PDO $db, $id): void
{
    // Validate that $id is provided and numeric.
    // If not, call sendResponse with HTTP 400.
    if ($id === null || !is_numeric($id)) {
        sendResponse(['success' => false, 'message' => 'Valid ID is required'], 400);
    }

    // SELECT id, title, start_date, description, links, created_at
    // FROM weeks WHERE id = ?
    $stmt = $db->prepare("SELECT id, title, start_date, description, links, created_at FROM weeks WHERE id = ?");
    $stmt->execute([$id]);

    // Fetch one row. Decode the links JSON:
    $week = $stmt->fetch(PDO::FETCH_ASSOC);

    // If found, sendResponse success with the week.
    // If not found, sendResponse error with HTTP 404.
    if ($week) {
        $week['links'] = json_decode($week['links'], true) ??[];
        sendResponse(['success' => true, 'data' => $week]);
    } else {
        sendResponse(['success' => false, 'message' => 'Week not found'], 404);
    }
}


/**
 * Create a new week.
 * Method: POST (no ?action parameter).
 *
 * Required JSON body fields:
 *   title       — string (required)
 *   start_date  — string "YYYY-MM-DD" (required)
 *   description — string (optional, defaults to "")
 *   links       — array of URL strings (optional, defaults to[])
 *
 * Response (success): HTTP 201 — { success, message, id }
 * Response (invalid start_date): HTTP 400.
 */
function createWeek(PDO $db, array $data): void
{
    // Trim title, start_date, and description.
    $title       = trim($data['title'] ?? '');
    $startDate   = trim($data['start_date'] ?? '');
    $description = trim($data['description'] ?? '');

    // Validate that title and start_date are present and non-empty.
    // If missing, sendResponse HTTP 400.
    if ($title === '' || $startDate === '') {
        sendResponse(['success' => false, 'message' => 'Title and start_date are required'], 400);
    }

    // Validate start_date format using DateTime::createFromFormat('Y-m-d', $start_date).
    // If invalid, sendResponse HTTP 400.
    if (!validateDate($startDate)) {
        sendResponse(['success' => false, 'message' => 'Invalid start_date format'], 400);
    }

    // Default description to "" if not provided. (Done above via trim + coalescing)

    // Handle links: if provided and is an array, json_encode it.
    // Otherwise use json_encode([]).
    $links     = $data['links'] ??[];
    $linksJson = is_array($links) ? json_encode($links) : json_encode([]);

    // INSERT INTO weeks (title, start_date, description, links) VALUES (?, ?, ?, ?)
    // Note: id, created_at, and updated_at are handled by MySQL automatically.
    $stmt = $db->prepare("INSERT INTO weeks (title, start_date, description, links) VALUES (?, ?, ?, ?)");
    $stmt->execute([$title, $startDate, $description, $linksJson]);

    // If rowCount() > 0, sendResponse HTTP 201 with the new id.
    // Otherwise sendResponse HTTP 500.
    if ($stmt->rowCount() > 0) {
        sendResponse([
            'success' => true,
            'message' => 'Week created successfully',
            'id'      => $db->lastInsertId()
        ], 201);
    } else {
        sendResponse(['success' => false, 'message' => 'Failed to create week'], 500);
    }
}


/**
 * Update an existing week.
 * Method: PUT.
 *
 * Required JSON body:
 *   id — integer primary key of the week to update (required).
 * Optional JSON body fields (at least one must be present):
 *   title, start_date, description, links.
 *
 * Response (success): HTTP 200.
 * Response (not found): HTTP 404.
 * Response (invalid start_date): HTTP 400.
 */
function updateWeek(PDO $db, array $data): void
{
    // Validate that $data['id'] is present.
    // If not, sendResponse HTTP 400.
    $id = $data['id'] ?? null;
    if ($id === null || !is_numeric($id)) {
        sendResponse(['success' => false, 'message' => 'Valid ID is required'], 400);
    }

    // Check that a week with this id exists.
    // If not, sendResponse HTTP 404.
    $stmt = $db->prepare("SELECT id FROM weeks WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        sendResponse(['success' => false, 'message' => 'Week not found'], 404);
    }

    // Dynamically build the SET clause for whichever of
    // title, start_date, description, links are present in $data.
    $fields = [];
    $params =[];

    if (isset($data['title'])) {
        $fields[] = 'title = ?';
        $params[] = trim($data['title']);
    }

    // - If start_date is included, validate its format.
    if (isset($data['start_date'])) {
        $startDate = trim($data['start_date']);
        if (!validateDate($startDate)) {
            sendResponse(['success' => false, 'message' => 'Invalid start_date format'], 400);
        }
        $fields[] = 'start_date = ?';
        $params[] = $startDate;
    }

    if (isset($data['description'])) {
        $fields[] = 'description = ?';
        $params[] = trim($data['description']);
    }

    // - If links is included, json_encode it.
    if (isset($data['links'])) {
        $fields[] = 'links = ?';
        $params[] = is_array($data['links']) ? json_encode($data['links']) : json_encode([]);
    }

    // If no updatable fields are present, sendResponse HTTP 400.
    if (empty($fields)) {
        sendResponse(['success' => false, 'message' => 'No updatable fields provided'], 400);
    }

    // updated_at is updated automatically by MySQL
    // (ON UPDATE CURRENT_TIMESTAMP), so no need to set it manually.

    // Build: UPDATE weeks SET {clauses} WHERE id = ?
    // Prepare, bind all SET values, then bind id, and execute.
    $sql = "UPDATE weeks SET " . implode(', ', $fields) . " WHERE id = ?";
    $params[] = $id;

    $stmt = $db->prepare($sql);
    
    // sendResponse HTTP 200 on success, HTTP 500 on failure.
    if ($stmt->execute($params)) {
        sendResponse(['success' => true, 'message' => 'Week updated successfully'], 200);
    } else {
        sendResponse(['success' => false, 'message' => 'Failed to update week'], 500);
    }
}


/**
 * Delete a week by integer id.
 * Method: DELETE with ?id={id}.
 *
 * The ON DELETE CASCADE constraint on comments_week.week_id
 * automatically removes all comments for this week — no manual
 * deletion of comments is needed.
 *
 * Response (success): HTTP 200.
 * Response (not found): HTTP 404.
 */
function deleteWeek(PDO $db, $id): void
{
    // Validate that $id is provided and numeric.
    // If not, sendResponse HTTP 400.
    if ($id === null || !is_numeric($id)) {
        sendResponse(['success' => false, 'message' => 'Valid ID is required'], 400);
    }

    // Check that a week with this id exists.
    // If not, sendResponse HTTP 404.
    $stmt = $db->prepare("SELECT id FROM weeks WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        sendResponse(['success' => false, 'message' => 'Week not found'], 404);
    }

    // DELETE FROM weeks WHERE id = ?
    // (comments_week rows are removed automatically by ON DELETE CASCADE.)
    $stmt = $db->prepare("DELETE FROM weeks WHERE id = ?");
    $stmt->execute([$id]);

    // If rowCount() > 0, sendResponse HTTP 200.
    // Otherwise sendResponse HTTP 500.
    if ($stmt->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Week deleted successfully'], 200);
    } else {
        sendResponse(['success' => false, 'message' => 'Failed to delete week'], 500);
    }
}


// ============================================================================
// COMMENTS FUNCTIONS
// ============================================================================

/**
 * Get all comments for a specific week.
 * Method: GET with ?action=comments&week_id={id}.
 *
 * Reads from the comments_week table.
 * Returns an empty data array if no comments exist — not an error.
 *
 * Each comment object: { id, week_id, author, text, created_at }
 */
function getCommentsByWeek(PDO $db, $weekId): void
{
    // Validate that $weekId is provided and numeric.
    // If not, sendResponse HTTP 400.
    if ($weekId === null || !is_numeric($weekId)) {
        sendResponse(['success' => false, 'message' => 'Valid week_id is required'], 400);
    }

    // SELECT id, week_id, author, text, created_at
    // FROM comments_week
    // WHERE week_id = ?
    // ORDER BY created_at ASC
    $stmt = $db->prepare("SELECT id, week_id, author, text, created_at FROM comments_week WHERE week_id = ? ORDER BY created_at ASC");
    $stmt->execute([$weekId]);

    // Fetch all rows. Return sendResponse with the array
    // (empty array is valid).
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    sendResponse(['success' => true, 'data' => $comments], 200);
}


/**
 * Create a new comment.
 * Method: POST with ?action=comment.
 *
 * Required JSON body:
 *   week_id — integer FK into weeks.id (required)
 *   author  — string (required)
 *   text    — string (required, must be non-empty after trim)
 *
 * Response (success): HTTP 201 — { success, message, id, data: comment }
 * Response (week not found): HTTP 404.
 * Response (missing fields): HTTP 400.
 */
function createComment(PDO $db, array $data): void
{
    $weekId = $data['week_id'] ?? null;
    $author = trim($data['author'] ?? '');
    $text   = trim($data['text'] ?? '');

    // Validate that week_id, author, and text are all present and
    // non-empty after trimming. If any are missing, sendResponse HTTP 400.
    if ($weekId === null || $author === '' || $text === '') {
        sendResponse(['success' => false, 'message' => 'week_id, author, and text are required'], 400);
    }

    // Validate that week_id is numeric.
    if (!is_numeric($weekId)) {
        sendResponse(['success' => false, 'message' => 'Invalid week_id'], 400);
    }

    // Check that a week with this id exists in the weeks table.
    // If not, sendResponse HTTP 404.
    $stmt = $db->prepare("SELECT id FROM weeks WHERE id = ?");
    $stmt->execute([$weekId]);
    if (!$stmt->fetch()) {
        sendResponse(['success' => false, 'message' => 'Week not found'], 404);
    }

    // INSERT INTO comments_week (week_id, author, text)
    // VALUES (?, ?, ?)
    $stmt = $db->prepare("INSERT INTO comments_week (week_id, author, text) VALUES (?, ?, ?)");
    $stmt->execute([$weekId, $author, $text]);

    // If rowCount() > 0, sendResponse HTTP 201 with the new id
    // and the full new comment object.
    // Otherwise sendResponse HTTP 500.
    if ($stmt->rowCount() > 0) {
        $id = $db->lastInsertId();
        
        $stmt = $db->prepare("SELECT id, week_id, author, text, created_at FROM comments_week WHERE id = ?");
        $stmt->execute([$id]);
        $comment = $stmt->fetch(PDO::FETCH_ASSOC);

        sendResponse([
            'success' => true,
            'message' => 'Comment created successfully',
            'id'      => $id,
            'data'    => $comment
        ], 201);
    } else {
        sendResponse(['success' => false, 'message' => 'Failed to create comment'], 500);
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
    // If not, sendResponse HTTP 400.
    if ($commentId === null || !is_numeric($commentId)) {
        sendResponse(['success' => false, 'message' => 'Valid comment_id is required'], 400);
    }

    // Check that the comment exists in comments_week.
    // If not, sendResponse HTTP 404.
    $stmt = $db->prepare("SELECT id FROM comments_week WHERE id = ?");
    $stmt->execute([$commentId]);
    if (!$stmt->fetch()) {
        sendResponse(['success' => false, 'message' => 'Comment not found'], 404);
    }

    // DELETE FROM comments_week WHERE id = ?
    $stmt = $db->prepare("DELETE FROM comments_week WHERE id = ?");
    $stmt->execute([$commentId]);

    // If rowCount() > 0, sendResponse HTTP 200.
    // Otherwise sendResponse HTTP 500.
    if ($stmt->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Comment deleted successfully'], 200);
    } else {
        sendResponse(['success' => false, 'message' => 'Failed to delete comment'], 500);
    }
}


// ============================================================================
// MAIN REQUEST ROUTER
// ============================================================================

try {

    if ($method === 'GET') {

        // ?action=comments&week_id={id} → list comments for a week
        if ($action === 'comments') {
            getCommentsByWeek($db, $weekId);
        }
        // ?id={id} → single week
        elseif ($id !== null) {
            getWeekById($db, $id);
        }
        // no parameters → all weeks (supports ?search, ?sort, ?order)
        else {
            getAllWeeks($db);
        }

    } elseif ($method === 'POST') {

        // ?action=comment → create a comment in comments_week
        if ($action === 'comment') {
            createComment($db, $data);
        }
        // no action → create a new week
        else {
            createWeek($db, $data);
        }

    } elseif ($method === 'PUT') {

        // Update a week; id comes from the JSON body
        updateWeek($db, $data);

    } elseif ($method === 'DELETE') {

        // ?action=delete_comment&comment_id={id} → delete one comment
        if ($action === 'delete_comment') {
            deleteComment($db, $commentId);
        }
        // ?id={id} → delete a week (and its comments via CASCADE)
        else {
            deleteWeek($db, $id);
        }

    } else {
        // sendResponse HTTP 405 Method Not Allowed.
        sendResponse(['success' => false, 'message' => 'Method Not Allowed'], 405);
    }

} catch (PDOException $e) {
    // Log the error with error_log().
    // Return a generic HTTP 500 — do NOT expose $e->getMessage() to clients.
    error_log('Database error: ' . $e->getMessage());
    sendResponse(['success' => false, 'message' => 'Internal Server Error'], 500);

} catch (Exception $e) {
    // Log the error with error_log().
    // Return HTTP 500 using sendResponse().
    error_log('General error: ' . $e->getMessage());
    sendResponse(['success' => false, 'message' => 'Internal Server Error'], 500);
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