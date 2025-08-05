<?php
// 开启错误报告，方便调试。在生产环境中应关闭。
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 设置响应头为 JSON
header('Content-Type: application/json');

// 允许跨域请求 (CORS) - !!! 在生产环境中，您应该限制为只允许您的前端域名！
// header("Access-Control-Allow-Origin: http://localhost:8000"); // !!! 替换为您的前端域名
header("Access-Control-Allow-Origin: *"); // 允许所有来源 (仅用于快速测试，不安全)
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// 如果是 OPTIONS 请求，直接返回成功，用于预检请求
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204); // No Content
    exit();
}

// 数据库配置
$dbFile = 'logs.db'; // SQLite 数据库文件，与此 PHP 文件在同一目录下

// --- 数据库操作函数 ---

/**
 * 获取 SQLite 数据库连接
 * @param string $dbFile 数据库文件路径
 * @return ?PDO PDO 数据库连接对象，如果失败则返回 null
 */
function getDbConnection(string $dbFile): ?PDO {
    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        // 设置 PDO 错误模式为异常模式
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // 设置默认的获取模式为关联数组
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // 启用外键约束（如果需要）
        // $pdo->exec('PRAGMA foreign_keys = ON;');
        return $pdo;
    } catch (PDOException $e) {
        // 记录错误，但不向客户端暴露详细信息
        error_log("SQLite Connection Error: " . $e->getMessage());
        return null;
    }
}

/**
 * 初始化数据库表结构
 * @param PDO $pdo PDO 数据库连接对象
 */
function initializeDatabase(PDO $pdo): void {
    $sql = "CREATE TABLE IF NOT EXISTS logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                log_type TEXT NOT NULL,
                message TEXT NOT NULL,
                data TEXT,
                timestamp TEXT NOT NULL,
                received_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )";
    try {
        $pdo->exec($sql);
        // echo "Database table 'logs' initialized successfully.\n";
    } catch (PDOException $e) {
        error_log("Database Initialization Error: " . $e->getMessage());
        // 可以在这里抛出异常或返回错误
    }
}

/**
 * 插入一条日志记录到数据库
 * @param PDO $pdo PDO 数据库连接对象
 * @param string $type 日志类型 (e.g., 'Global', 'Hijack', 'System')
 * @param string $message 日志消息
 * @param array|null $data 附加数据
 * @return bool 插入成功返回 true，失败返回 false
 */
function insertLog(PDO $pdo, string $type, string $message, ?array $data): bool {
    $sql = "INSERT INTO logs (log_type, message, data, timestamp) 
            VALUES (:log_type, :message, :data, :timestamp)";
    
    // 将 data 数组转换为 JSON 字符串，以便存储
    $jsonData = $data ? json_encode($data) : null;

    try {
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            ':log_type' => $type,
            ':message' => $message,
            ':data' => $jsonData,
            ':timestamp' => $data['timestamp'] ?? $data['timestamp'] ?? date('c') // 从前端数据获取时间戳，或使用当前服务器时间
        ]);
        return $result;
    } catch (PDOException $e) {
        error_log("Database Insertion Error: " . $e->getMessage());
        return false;
    }
}

// --- 主逻辑 ---

// 确保请求是 POST 方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'Only POST method is allowed.']);
    exit();
}

// 获取 POST 请求体中的 JSON 数据
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, TRUE); // TRUE 使其解码为关联数组

// 检查 JSON 解码是否成功以及数据结构是否正确
if (json_last_error() !== JSON_ERROR_NONE || !isset($input['logs']) || !is_array($input['logs'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Invalid input data. Expected a JSON object with a "logs" array.']);
    exit();
}

// 获取数据库连接
$pdo = getDbConnection($dbFile);

if (!$pdo) {
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'Failed to connect to the database.']);
    exit();
}

// 初始化数据库表（如果不存在）
initializeDatabase($pdo);

// 遍历接收到的日志并插入数据库
$successCount = 0;
$errorCount = 0;

foreach ($input['logs'] as $logEntry) {
    // 验证每条日志的必要字段
    if (isset($logEntry['log_type'], $logEntry['message'], $logEntry['timestamp'])) {
        $type = filter_var($logEntry['log_type'], FILTER_SANITIZE_STRING);
        $message = filter_var($logEntry['message'], FILTER_SANITIZE_STRING);
        $data = isset($logEntry['data']) ? (is_array($logEntry['data']) ? $logEntry['data'] : ['raw_data' => $logEntry['data']]) : null;
        $timestamp = filter_var($logEntry['timestamp'], FILTER_SANITIZE_STRING);

        // 插入日志
        if (insertLog($pdo, $type, $message, $data)) {
            $successCount++;
        } else {
            $errorCount++;
        }
    } else {
        // 记录格式不正确的日志条目
        error_log("Skipping malformed log entry: " . json_encode($logEntry));
        $errorCount++;
    }
}

// 关闭数据库连接
$pdo = null;

// 返回响应
if ($errorCount === 0) {
    http_response_code(200); // OK
    echo json_encode(['status' => 'success', 'message' => "Successfully logged {$successCount} entries."]);
} else {
    http_response_code(207); // Multi-Status (if some succeeded, some failed)
    echo json_encode(['status' => 'partial_success', 'message' => "Logged {$successCount} entries, but encountered {$errorCount} errors."]);
}

?>
