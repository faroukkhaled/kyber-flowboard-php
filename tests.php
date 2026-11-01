<?php
require __DIR__ . "/domain.php";
function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function rejects(callable $f): void
{
    try {
        $f();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException("Expected validation rejection");
}
$i = [
    "title" => "Synthetic task",
    "assignee" => "Demo Agent",
    "status" => "Todo",
    "estimate" => "2",
    "blockedBy" => "",
    "notes" => "Synthetic",
];
$a = validate($i, [], null);
$a["id"] = "a";
$b = array_merge($a, ["id" => "b", "blockedBy" => "a"]);
rejects(
    fn() => validate(
        array_merge($i, ["status" => "Doing", "blockedBy" => "a"]),
        [$a],
        null,
    ),
);
rejects(fn() => validate(array_merge($i, ["blockedBy" => "b"]), [$a, $b], $a));
$rows = [];
for ($n = 0; $n < 3; $n++) {
    $rows[] = array_merge($a, ["id" => (string) $n, "status" => "Doing"]);
}
rejects(fn() => validate(array_merge($i, ["status" => "Doing"]), $rows, null));
check(action($a, "advance", [], [$a])["status"] === "Doing", "Advance");
echo "Flowboard checks passed\n";
