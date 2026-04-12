<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class DB {
    private static ?DB $instance = null;
    private PDO $pdo;

    private function __construct() {
        
        $host     = getenv('MYSQLHOST')     ?: DB_HOST;
        $user     = getenv('MYSQLUSER')     ?: DB_USER;
        $password = getenv('MYSQLPASSWORD') ?: DB_PASS;
        $dbname   = getenv('MYSQLDATABASE') ?: DB_NAME;
        $port     = getenv('MYSQLPORT')     ?: DB_PORT;

        $pdo = new PDO(
            "mysql:host=$host;port=$port;charset=utf8mb4",
            $user, $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->pdo = new PDO(
            "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
            $user, $password,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    public static function get(): self {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public function query(string $sql, array $p = []): array {
        $s = $this->pdo->prepare($sql);
        $s->execute($p);
        return $s->fetchAll();
    }

    public function run(string $sql, array $p = []): bool {
        return $this->pdo->prepare($sql)->execute($p);
    }

    public function lastId(): string { return $this->pdo->lastInsertId(); }
}

class LibraryServer implements MessageComponentInterface {

    protected \SplObjectStorage $clients;
    protected DB $db;

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        echo "\n🔌 Connecting to MySQL...\n";
        try {
            $this->db = DB::get();
            echo "✅ MySQL Connected!\n";
            $this->createTables();
        } catch (\Exception $e) {
            echo "❌ MySQL Error: " . $e->getMessage() . "\n";
            echo "💡 Check: XAMPP MySQL chal raha hai? php.ini mein extension=pdo_mysql uncomment hai?\n";
            exit(1);
        }
    }

    private function createTables(): void {
        $this->db->run("CREATE TABLE IF NOT EXISTS students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            roll_number VARCHAR(50) UNIQUE NOT NULL,
            barcode VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->run("CREATE TABLE IF NOT EXISTS seats (
            seat_id VARCHAR(10) PRIMARY KEY,
            is_occupied TINYINT(1) DEFAULT 0,
            current_student_id INT DEFAULT NULL
        )");

        $this->db->run("CREATE TABLE IF NOT EXISTS sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            seat_id VARCHAR(10) NOT NULL,
            entry_time DATETIME DEFAULT NOW(),
            exit_time DATETIME DEFAULT NULL,
            FOREIGN KEY (student_id) REFERENCES students(id)
        )");

        $this->db->run("CREATE TABLE IF NOT EXISTS visitors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            mobile VARCHAR(15) NOT NULL,
            email VARCHAR(100) DEFAULT NULL,
            purpose VARCHAR(50) DEFAULT NULL,
            entry_time DATETIME DEFAULT NOW(),
            visit_date VARCHAR(20) DEFAULT NULL,
            visit_time VARCHAR(20) DEFAULT NULL
        )");

        echo "✅ All tables ready!\n";

        $cnt = $this->db->query("SELECT COUNT(*) as c FROM seats")[0]['c'];
        if ($cnt == 0) {
            echo "⏳ Seeding 400 seats...\n";
            $vals = [];
            foreach (['A','B'] as $sec)
                for ($i = 1; $i <= 200; $i++)
                    $vals[] = "('{$sec}{$i}', 0, NULL)";
            $this->db->run("INSERT INTO seats (seat_id, is_occupied, current_student_id) VALUES " . implode(',', $vals));
            echo "✅ 400 seats initialized!\n";
        }
    }

    public function onOpen(ConnectionInterface $conn): void {
        $this->clients->attach($conn);
        echo "🔌 Connected: #{$conn->resourceId} | Total: {$this->clients->count()}\n";

        try {
            $rows    = $this->db->query("SELECT * FROM seats");
            $seatMap = [];
            foreach ($rows as $r)
                $seatMap[$r['seat_id']] = [
                    'occupied'  => (bool)$r['is_occupied'],
                    'studentId' => $r['current_student_id'],
                ];
            $conn->send(json_encode(['event' => 'all-seats', 'data' => $seatMap]));
        } catch (\Exception $e) {
            echo "Error sending seats: " . $e->getMessage() . "\n";
        }
    }

    public function onMessage(ConnectionInterface $from, $msg): void {
        $p = json_decode($msg, true);
        if (!$p || !isset($p['event'])) return;

        match ($p['event']) {
            'verify-student'     => $this->verifyStudent($from, $p['data'] ?? []),
            'book-seat'          => $this->bookSeat($from, $p['data'] ?? []),
            'student-exit'       => $this->studentExit($from, $p['data'] ?? []),
            'visitor-entry'      => $this->visitorEntry($from, $p['data'] ?? []),
            'get-student-logs'   => $this->getStudentLogs($from, $p['data'] ?? []),
            'get-visitor-logs'   => $this->getVisitorLogs($from, $p['data'] ?? []),
            'admin-release-seat' => $this->adminReleaseSeat($from, $p['data'] ?? []),
            'add-student'        => $this->addStudent($from, $p['data'] ?? []),
            default              => null,
        };
    }

    private function verifyStudent(ConnectionInterface $conn, array $d): void {
        try {
            $barcode  = $d['barcode'] ?? '';
            $students = $this->db->query(
                "SELECT * FROM students WHERE barcode = ? OR roll_number = ?",
                [$barcode, $barcode]
            );

            if (empty($students)) {
                $conn->send(json_encode(['event' => 'error-msg', 'data' => ['msg' => 'Student nahi mila! Admin se contact karo.']]));
                return;
            }

            $student  = $students[0];
            $sessions = $this->db->query(
                "SELECT * FROM sessions WHERE student_id = ? AND exit_time IS NULL",
                [$student['id']]
            );

            $isInside = count($sessions) > 0;
            if ($isInside) $student['seat_id'] = $sessions[0]['seat_id'];

            $conn->send(json_encode(['event' => 'student-verified', 'data' => compact('student', 'isInside')]));

        } catch (\Exception $e) {
            echo "verify-student error: " . $e->getMessage() . "\n";
            $conn->send(json_encode(['event' => 'error-msg', 'data' => ['msg' => 'Server error. Try again.']]));
        }
    }

    private function bookSeat(ConnectionInterface $conn, array $d): void {
        try {
            $studentId = $d['studentId'] ?? null;
            $seatId    = $d['seatId']    ?? null;

            $seats = $this->db->query(
                "SELECT * FROM seats WHERE seat_id = ? AND is_occupied = 0", [$seatId]
            );

            if (empty($seats)) {
                $conn->send(json_encode(['event' => 'error-msg', 'data' => ['msg' => "Seat $seatId abhi liya gaya! Doosra choose karo."]]));
                return;
            }

            $this->db->run("UPDATE seats SET is_occupied=1, current_student_id=? WHERE seat_id=?", [$studentId, $seatId]);
            $this->db->run("INSERT INTO sessions (student_id, seat_id, entry_time) VALUES (?,?,NOW())", [$studentId, $seatId]);

            $name = $this->db->query("SELECT name FROM students WHERE id=?", [$studentId])[0]['name'] ?? '';

            $this->broadcast(['event' => 'seat-update', 'data' => [
                'seatId'      => $seatId,
                'occupied'    => true,
                'studentId'   => $studentId,
                'studentName' => $name,
            ]]);

            echo "✅ Seat $seatId booked by $name\n";

        } catch (\Exception $e) {
            echo "book-seat error: " . $e->getMessage() . "\n";
        }
    }

    private function studentExit(ConnectionInterface $conn, array $d): void {
        try {
            $studentId = $d['studentId'] ?? null;
            $sessions  = $this->db->query(
                "SELECT * FROM sessions WHERE student_id=? AND exit_time IS NULL", [$studentId]
            );

            if (empty($sessions)) return;

            $seatId = $sessions[0]['seat_id'];
            $sessId = $sessions[0]['id'];

            $this->db->run("UPDATE sessions SET exit_time=NOW() WHERE id=?", [$sessId]);
            $this->db->run("UPDATE seats SET is_occupied=0, current_student_id=NULL WHERE seat_id=?", [$seatId]);

            $this->broadcast(['event' => 'seat-update', 'data' => [
                'seatId'      => $seatId,
                'occupied'    => false,
                'studentId'   => null,
                'studentName' => null,
            ]]);

            echo "🚪 Seat $seatId released by student $studentId\n";

        } catch (\Exception $e) {
            echo "student-exit error: " . $e->getMessage() . "\n";
        }
    }

    private function visitorEntry(ConnectionInterface $conn, array $d): void {
        try {
            $this->db->run(
                "INSERT INTO visitors (name, mobile, email, purpose, entry_time, visit_date, visit_time) VALUES (?,?,?,?,NOW(),?,?)",
                [$d['name'] ?? '', $d['mobile'] ?? '', $d['email'] ?? null, $d['purpose'] ?? null, $d['visit_date'] ?? null, $d['visit_time'] ?? null]
            );
            $conn->send(json_encode(['event' => 'visitor-saved', 'data' => ['success' => true]]));
            echo "👤 Visitor: {$d['name']} ({$d['mobile']})\n";
        } catch (\Exception $e) {
            echo "visitor-entry error: " . $e->getMessage() . "\n";
            $conn->send(json_encode(['event' => 'visitor-saved', 'data' => ['success' => false]]));
        }
    }

    private function addStudent(ConnectionInterface $conn, array $d): void {
        try {
            $name   = trim($d['name']        ?? '');
            $roll   = trim($d['roll_number'] ?? '');
            $barcode = trim($d['barcode']    ?? $roll);

            if (!$name || !$roll) {
                $conn->send(json_encode(['event' => 'student-added', 'data' => ['success' => false, 'msg' => 'Name aur Roll Number zaroori hain!']]));
                return;
            }

            $existing = $this->db->query("SELECT id FROM students WHERE roll_number=?", [$roll]);
            if (!empty($existing)) {
                $conn->send(json_encode(['event' => 'student-added', 'data' => ['success' => false, 'msg' => "Roll number $roll pehle se exist karta hai!"]]));
                return;
            }

            $this->db->run(
                "INSERT INTO students (name, roll_number, barcode) VALUES (?,?,?)",
                [$name, $roll, $barcode]
            );

            $conn->send(json_encode(['event' => 'student-added', 'data' => ['success' => true, 'msg' => "$name successfully add ho gaya!"]]));
            echo "🎓 Student added: $name ($roll)\n";

        } catch (\Exception $e) {
            echo "add-student error: " . $e->getMessage() . "\n";
            $conn->send(json_encode(['event' => 'student-added', 'data' => ['success' => false, 'msg' => 'Server error!']]));
        }
    }

    private function getStudentLogs(ConnectionInterface $conn, array $d): void {
        try {
            $filter = $d['filter'] ?? 'all';
            $where  = match($filter) {
                'today' => 'WHERE DATE(s.entry_time) = CURDATE()',
                'week'  => 'WHERE s.entry_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
                'month' => 'WHERE s.entry_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
                default => '',
            };

            $logs = $this->db->query("
                SELECT s.id, st.name, st.roll_number, s.seat_id,
                       s.entry_time, s.exit_time,
                       DATE_FORMAT(s.entry_time, '%d %b %Y') AS entry_date,
                       DATE_FORMAT(s.entry_time, '%h:%i %p')  AS entry_time_fmt,
                       DATE_FORMAT(s.exit_time,  '%d %b %Y') AS exit_date,
                       DATE_FORMAT(s.exit_time,  '%h:%i %p')  AS exit_time_fmt,
                       TIMEDIFF(COALESCE(s.exit_time, NOW()), s.entry_time) AS duration
                FROM sessions s
                JOIN students st ON s.student_id = st.id
                $where
                ORDER BY s.entry_time DESC LIMIT 500
            ");

            $conn->send(json_encode(['event' => 'student-logs', 'data' => $logs]));
            echo "📋 Sent " . count($logs) . " student logs [$filter]\n";

        } catch (\Exception $e) {
            echo "get-student-logs error: " . $e->getMessage() . "\n";
            $conn->send(json_encode(['event' => 'student-logs', 'data' => []]));
        }
    }

    private function getVisitorLogs(ConnectionInterface $conn, array $d): void {
        try {
            $filter = $d['filter'] ?? 'all';
            $where  = match($filter) {
                'today' => 'WHERE DATE(entry_time) = CURDATE()',
                'week'  => 'WHERE entry_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
                'month' => 'WHERE entry_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
                default => '',
            };

            $logs = $this->db->query("
                SELECT id, name, mobile, email, purpose, entry_time,
                       DATE_FORMAT(entry_time, '%h:%i %p') AS time,
                       DATE_FORMAT(entry_time, '%d %b %Y') AS date
                FROM visitors $where
                ORDER BY entry_time DESC LIMIT 500
            ");

            $conn->send(json_encode(['event' => 'visitor-logs', 'data' => $logs]));
            echo "📋 Sent " . count($logs) . " visitor logs [$filter]\n";

        } catch (\Exception $e) {
            echo "get-visitor-logs error: " . $e->getMessage() . "\n";
            $conn->send(json_encode(['event' => 'visitor-logs', 'data' => []]));
        }
    }

    private function adminReleaseSeat(ConnectionInterface $conn, array $d): void {
        try {
            $seatId   = $d['seatId'] ?? null;
            $sessions = $this->db->query(
                "SELECT * FROM sessions WHERE seat_id=? AND exit_time IS NULL", [$seatId]
            );

            if (!empty($sessions))
                $this->db->run("UPDATE sessions SET exit_time=NOW() WHERE id=?", [$sessions[0]['id']]);

            $this->db->run("UPDATE seats SET is_occupied=0, current_student_id=NULL WHERE seat_id=?", [$seatId]);

            $this->broadcast(['event' => 'seat-update', 'data' => [
                'seatId' => $seatId, 'occupied' => false, 'studentId' => null, 'studentName' => null,
            ]]);

            echo "🔓 Seat $seatId released by admin\n";

        } catch (\Exception $e) {
            echo "admin-release-seat error: " . $e->getMessage() . "\n";
        }
    }

    private function broadcast(array $payload): void {
        $msg = json_encode($payload);
        foreach ($this->clients as $client) $client->send($msg);
    }

    public function onClose(ConnectionInterface $conn): void {
        $this->clients->detach($conn);
        echo "🔴 Disconnected: #{$conn->resourceId} | Total: {$this->clients->count()}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void {
        echo "❌ Error: " . $e->getMessage() . "\n";
        $conn->close();
    }
}

$port = getenv('PORT') ?: WS_PORT;

echo "\n";
echo "╔══════════════════════════════════════╗\n";
echo "║   📚 Library Management System v2   ║\n";
echo "╚══════════════════════════════════════╝\n";

$server = IoServer::factory(
    new HttpServer(new WsServer(new LibraryServer())),
    $port,
    '0.0.0.0'
);

echo "\n🚀 WebSocket Server: ws://localhost:{$port}\n";
echo "💡 Apni HTML file browser mein kholo\n\n";

$server->run();