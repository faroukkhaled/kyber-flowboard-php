<?php
declare(strict_types=1);
require __DIR__ . "/domain.php";
header("X-Content-Type-Options: nosniff");
header(
    "Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'none'",
);
header("Referrer-Policy: no-referrer");
function reply(int $status, mixed $value): never
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    echo json_encode($value, JSON_THROW_ON_ERROR);
    exit();
}
$host = $_SERVER["HTTP_HOST"] ?? "";
if (!in_array(explode(":", $host)[0], ["localhost", "127.0.0.1"], true)) {
    reply(403, ["error" => "Untrusted host"]);
}
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$method = $_SERVER["REQUEST_METHOD"];
$assets = [
    "/" => ["index.html", "text/html"],
    "/app.js" => ["app.js", "text/javascript"],
    "/style.css" => ["style.css", "text/css"],
];
if (isset($assets[$path]) && $method === "GET") {
    [$file, $mime] = $assets[$path];
    header("Content-Type: " . $mime . "; charset=utf-8");
    readfile(__DIR__ . "/" . $file);
    exit();
}
if ($method === "GET" && $path === "/api/config") {
    reply(
        200,
        json_decode(
            file_get_contents(__DIR__ . "/config.json"),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ),
    );
}
if (!str_starts_with($path, "/api/")) {
    reply(404, ["error" => "Not found"]);
}
if ($method !== "GET") {
    if (!in_array($method, ["POST", "PUT"], true)) {
        reply(405, ["error" => "Method not allowed"]);
    }
    if (
        ($_SERVER["HTTP_X_KYBER_CLIENT"] ?? "") !== "workspace" ||
        (isset($_SERVER["HTTP_ORIGIN"]) &&
            $_SERVER["HTTP_ORIGIN"] !== "http://" . $host)
    ) {
        reply(403, ["error" => "Cross-origin write blocked"]);
    }
    if (!str_starts_with($_SERVER["CONTENT_TYPE"] ?? "", "application/json")) {
        reply(415, ["error" => "JSON required"]);
    }
    $raw = file_get_contents("php://input", false, null, 0, 65537);
    if (strlen($raw) > 65536) {
        reply(413, ["error" => "Request too large"]);
    }
    try {
        $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        reply(400, ["error" => "Invalid JSON"]);
    }
    if (!is_array($input) || (array_is_list($input) && $input !== [])) {
        reply(400, ["error" => "Object required"]);
    }
}
$dir = __DIR__ . "/data";
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}
$lock = fopen($dir . "/workspace.lock", "c+");
if (!$lock || !flock($lock, LOCK_EX)) {
    reply(503, ["error" => "Storage unavailable"]);
}
try {
    $file = $dir . "/workspace.json";
    $state = is_file($file)
        ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)
        : ["records" => [], "events" => []];
    $rows = $state["records"];
    if ($method === "GET" && $path === "/api/records") {
        reply(200, [
            "records" => array_reverse($rows),
            "metrics" => metrics($rows),
            "events" => array_slice(array_reverse($state["events"]), 0, 30),
        ]);
    }
    if (
        !preg_match(
            '~^/api/records(?:/([a-f0-9-]+))?(?:/actions/([a-z-]+))?$~',
            $path,
            $match,
        )
    ) {
        reply(404, ["error" => "Not found"]);
    }
    $id = $match[1] ?? null;
    $verb = $match[2] ?? null;
    $index = null;
    $old = null;
    foreach ($rows as $i => $row) {
        if ($row["id"] === $id) {
            $old = $row;
            $index = $i;
            break;
        }
    }
    if ($id && !$old) {
        reply(404, ["error" => "Record not found"]);
    }
    if (
        $method === "GET" ||
        (!$id && $method !== "POST") ||
        ($id && !$verb && $method !== "PUT")
    ) {
        reply(405, ["error" => "Invalid method"]);
    }
    if (count($rows) >= 10000 && !$id) {
        reply(409, ["error" => "Record limit reached"]);
    }
    $value = $verb
        ? action($old, $verb, $input, $rows)
        : validate($input, $rows, $old);
    $value["id"] = $id ?? bin2hex(random_bytes(16));
    $value["createdAt"] = $old["createdAt"] ?? gmdate("c");
    $value["updatedAt"] = gmdate("c");
    if ($index === null) {
        $state["records"][] = $value;
    } else {
        $state["records"][$index] = $value;
    }
    $state["events"][] = [
        "at" => gmdate("c"),
        "message" =>
            ($verb ?? ($id ? "Updated" : "Created")) .
            " record " .
            substr($value["id"], 0, 8),
    ];
    $temp = $file . ".tmp";
    if (
        file_put_contents(
            $temp,
            json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        ) === false ||
        !rename($temp, $file)
    ) {
        throw new RuntimeException("Storage write failed");
    }
    reply($id ? 200 : 201, $value);
} catch (InvalidArgumentException $e) {
    reply(422, ["error" => $e->getMessage()]);
} catch (Throwable $e) {
    reply(500, ["error" => "Storage or processing failure"]);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
