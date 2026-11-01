<?php
declare(strict_types=1);
function text(array $i, string $key, int $max = 2000): string
{
    $s = $i[$key] ?? "";
    if (!is_string($s) || trim($s) === "" || strlen($s) > $max) {
        throw new InvalidArgumentException($key . " is required");
    }
    return trim($s);
}
function choice(array $i, string $key, array $values): string
{
    $v = text($i, $key);
    if (!in_array($v, $values, true)) {
        throw new InvalidArgumentException("Invalid " . $key);
    }
    return $v;
}
function num(array $i, string $key, float $min = 0, float $max = 1e9): float
{
    $v = $i[$key] ?? "";
    if (
        !is_numeric($v) ||
        !is_finite((float) $v) ||
        (float) $v < $min ||
        (float) $v > $max
    ) {
        throw new InvalidArgumentException("Invalid " . $key);
    }
    return (float) $v;
}

function validate(array $i, array $rows, ?array $old): array
{
    $status = choice($i, "status", ["Todo", "Doing", "Review", "Done"]);
    $assignee = text($i, "assignee", 100);
    $dependency = trim((string) ($i["blockedBy"] ?? ""));
    $dep = null;
    foreach ($rows as $r) {
        if ($r["id"] === $dependency) {
            $dep = $r;
        }
    }
    if ($dependency !== "" && (!$dep || $dependency === ($old["id"] ?? null))) {
        throw new InvalidArgumentException(
            "Dependency must reference another existing task",
        );
    }
    if ($dep && $status !== "Todo" && $dep["status"] !== "Done") {
        throw new InvalidArgumentException(
            "Dependency must be completed first",
        );
    }
    $byId = [];
    foreach ($rows as $r) {
        $byId[$r["id"]] = $r;
    }
    $seen = [];
    $cursor = $dependency;
    while ($cursor !== "") {
        if (isset($seen[$cursor]) || $cursor === ($old["id"] ?? null)) {
            throw new InvalidArgumentException("Dependency cycle rejected");
        }
        $seen[$cursor] = true;
        $cursor = $byId[$cursor]["blockedBy"] ?? "";
    }
    if ($status === "Doing") {
        $wip = 0;
        foreach ($rows as $r) {
            if (
                $r["status"] === "Doing" &&
                strcasecmp($r["assignee"], $assignee) === 0 &&
                $r["id"] !== ($old["id"] ?? null)
            ) {
                $wip++;
            }
        }
        if ($wip >= 3) {
            throw new InvalidArgumentException("Assignee WIP limit is 3");
        }
    }
    if ($old && $old["status"] === "Done" && $status !== "Done") {
        foreach ($rows as $r) {
            if (
                ($r["blockedBy"] ?? "") === $old["id"] &&
                $r["status"] !== "Todo"
            ) {
                throw new InvalidArgumentException(
                    "Reopening would invalidate an active dependent task",
                );
            }
        };
    }
    return [
        "title" => text($i, "title", 180),
        "assignee" => $assignee,
        "status" => $status,
        "estimate" => num($i, "estimate", 0.25, 200),
        "blockedBy" => $dependency,
        "notes" => text($i, "notes"),
    ];
}
function metrics(array $rows): array
{
    $counts = ["Todo" => 0, "Doing" => 0, "Review" => 0, "Done" => 0];
    foreach ($rows as $r) {
        $counts[$r["status"]]++;
    }
    return $counts;
}
function action(array $row, string $verb, array $input, array $rows): array
{
    $stages = ["Todo", "Doing", "Review", "Done"];
    $at = array_search($row["status"], $stages, true);
    if ($verb !== "advance" || $at === 3) {
        throw new InvalidArgumentException("Task cannot advance");
    }
    $row["status"] = $stages[$at + 1];
    return validate($row, $rows, $row);
}
