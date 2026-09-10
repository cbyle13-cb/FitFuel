<?php

function fitfuel_ensure_import_tokens_table(mysqli $conn): void {
    $sql = "CREATE TABLE IF NOT EXISTS shortcut_import_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        token_name VARCHAR(100) NOT NULL DEFAULT 'FitFuel Integration',
        last_used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        revoked_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shortcut_token_hash (token_hash),
        KEY idx_shortcut_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) throw new RuntimeException('Unable to initialize integration access.');
}

function fitfuel_import_token(array $input = []): string {
    $token = trim((string)($input['import_key'] ?? $input['import_token'] ?? $_GET['key'] ?? ''));
    if ($token !== '') return $token;
    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) return trim($match[1]);
    return '';
}

function fitfuel_import_user(mysqli $conn, array $input = []): int {
    fitfuel_ensure_import_tokens_table($conn);
    $token = fitfuel_import_token($input);
    if (strlen($token) < 32) throw new DomainException('A valid FitFuel integration key is required.', 401);
    $hash = hash('sha256', $token);
    $statement = $conn->prepare("SELECT t.id,t.user_id,u.is_active FROM shortcut_import_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.revoked_at IS NULL LIMIT 1");
    $statement->bind_param('s', $hash);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!$row || (int)$row['is_active'] !== 1) throw new DomainException('FitFuel integration key is invalid or revoked.', 401);
    $tokenId = (int)$row['id'];
    $statement = $conn->prepare('UPDATE shortcut_import_tokens SET last_used_at=NOW() WHERE id=?');
    $statement->bind_param('i', $tokenId);
    $statement->execute();
    $statement->close();
    return (int)$row['user_id'];
}

function fitfuel_date(string $value): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Use dates in YYYY-MM-DD format.');
    return $value;
}

function fitfuel_week_token(int $userId, string $week, string $slot): string {
    $hash = substr(hash('sha256', $userId . '|' . $week . '|' . $slot), 0, 32);
    return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-4' . substr($hash, 13, 3) . '-a' . substr($hash, 17, 3) . '-' . substr($hash, 20, 12);
}

function fitfuel_exercise_catalog(): array {
    $path = dirname(__DIR__) . '/exercises.json';
    $catalog = json_decode((string)@file_get_contents($path), true);
    if (!is_array($catalog)) throw new RuntimeException('Exercise library is unavailable.');
    $byId = [];
    foreach ($catalog as $exercise) if (isset($exercise['id'])) $byId[(string)$exercise['id']] = $exercise;
    return $byId;
}

function fitfuel_training_context(mysqli $conn, int $userId): array {
    $catalog = array_values(fitfuel_exercise_catalog());
    $statement = $conn->prepare('SELECT date_of_birth,height_inches,current_weight,goal_weight,activity_level,fitness_goal FROM user_profiles WHERE user_id=? LIMIT 1');
    $statement->bind_param('i', $userId);
    $statement->execute();
    $profile = $statement->get_result()->fetch_assoc() ?: [];
    $statement->close();
    $statement = $conn->prepare("SELECT id,workout_date,workout_name,duration_minutes,notes FROM workout_logs WHERE user_id=? AND workout_date>=DATE_SUB(CURDATE(),INTERVAL 120 DAY) ORDER BY workout_date DESC,id DESC LIMIT 80");
    $statement->bind_param('i', $userId);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    $records = [];
    foreach ($rows as $row) {
        $record = json_decode((string)($row['notes'] ?? ''), true);
        if (!is_array($record) || ($record['schema'] ?? '') !== 'fitfuel.workout.v1') continue;
        $record['id'] = (int)$row['id'];
        $records[] = $record;
    }
    return [
        'schema' => 'fitfuel.training-context.v1',
        'profile' => $profile,
        'constraints' => [
            'equipment' => ['PowerBlocks adjustable dumbbells up to 50 lb', 'bench', 'stationary bike', 'walking pad', '15-lb kettlebell', 'bodyweight'],
            'session_minutes' => [35, 45],
            'required_sessions' => 3,
            'preferred_sessions' => [4, 5],
            'workout_free' => ['Tuesday morning', 'Sunday morning'],
            'considerations' => ['low-back/SI-joint and hip discomfort', 'weak glutes', 'right-shoulder discomfort'],
        ],
        'exercise_catalog' => array_map(function ($exercise) {
            return [
                'id' => $exercise['id'],
                'name' => $exercise['name'] ?? $exercise['id'],
                'equipment' => $exercise['equipment'] ?? null,
                'timed' => (bool)($exercise['timed'] ?? false),
                'primaryMuscles' => $exercise['primaryMuscles'] ?? [],
            ];
        }, $catalog),
        'recent_records' => $records,
    ];
}

function fitfuel_number($value, float $minimum, float $maximum, string $label): float {
    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < $minimum || (float)$value > $maximum) {
        throw new InvalidArgumentException('Invalid ' . $label . '.');
    }
    return (float)$value;
}

function fitfuel_save_weekly_plan(mysqli $conn, int $userId, array $plan): array {
    if (($plan['schema'] ?? 'fitfuel.weekly-plan.v1') !== 'fitfuel.weekly-plan.v1') throw new InvalidArgumentException('Invalid weekly plan schema.');
    $week = fitfuel_date((string)($plan['week_start'] ?? ''));
    $monday = new DateTimeImmutable($week);
    if ($monday->format('N') !== '1') throw new InvalidArgumentException('week_start must be a Monday.');
    $sessions = $plan['sessions'] ?? null;
    if (!is_array($sessions) || count($sessions) < 3 || count($sessions) > 5) throw new InvalidArgumentException('A weekly plan needs 3–5 sessions.');
    $catalog = fitfuel_exercise_catalog();
    $dayOffsets = ['monday'=>0,'tuesday'=>1,'wednesday'=>2,'thursday'=>3,'friday'=>4,'saturday'=>5,'sunday'=>6];
    $summary = trim(substr((string)($plan['summary'] ?? ''), 0, 2000));
    $prepared = [];
    $seenSlots = [];
    $priorityCount = 0;
    foreach ($sessions as $index => $session) {
        if (!is_array($session)) throw new InvalidArgumentException('Invalid session.');
        $day = strtolower(trim((string)($session['day'] ?? '')));
        if (!isset($dayOffsets[$day])) throw new InvalidArgumentException('Each session needs a weekday.');
        $slot = $day . '-' . ($index + 1);
        if (isset($seenSlots[$day])) throw new InvalidArgumentException('Use no more than one workout per day.');
        $seenSlots[$day] = true;
        $priority = strtolower((string)($session['priority'] ?? 'optional')) === 'priority' ? 'priority' : 'optional';
        if ($priority === 'priority') $priorityCount++;
        $name = trim((string)($session['name'] ?? ''));
        if ($name === '' || strlen($name) > 100) throw new InvalidArgumentException('Each session needs a name under 100 characters.');
        $minutes = (int)fitfuel_number($session['minutes'] ?? 40, 1, 180, 'session duration');
        $exercises = $session['exercises'] ?? null;
        if (!is_array($exercises) || count($exercises) < 1 || count($exercises) > 30) throw new InvalidArgumentException('Each session needs 1–30 exercises.');
        $entries = [];
        foreach ($exercises as $exercise) {
            $exerciseId = (string)($exercise['exercise_id'] ?? $exercise['exerciseId'] ?? '');
            if (!isset($catalog[$exerciseId])) throw new InvalidArgumentException('Unknown exercise: ' . $exerciseId);
            $setCount = (int)fitfuel_number($exercise['sets'] ?? 3, 1, 12, 'set count');
            $minimum = fitfuel_number($exercise['min_reps'] ?? $exercise['min'] ?? 8, 1, 600, 'minimum reps');
            $maximum = fitfuel_number($exercise['max_reps'] ?? $exercise['max'] ?? 12, 1, 600, 'maximum reps');
            if ($minimum > $maximum) throw new InvalidArgumentException('Minimum reps cannot exceed maximum reps.');
            $weight = fitfuel_number($exercise['weight'] ?? 0, 0, 2000, 'weight');
            $increment = fitfuel_number($exercise['increment'] ?? 0, 0, 100, 'increment');
            $cap = fitfuel_number($exercise['cap'] ?? max($weight, 50), 0, 2000, 'weight cap');
            if ($weight > $cap) throw new InvalidArgumentException('Starting weight cannot exceed its cap.');
            $rest = fitfuel_number($exercise['rest_seconds'] ?? $exercise['rest'] ?? 60, 0, 600, 'rest time');
            $entries[] = [
                'exerciseId' => $exerciseId,
                'min' => $minimum,
                'max' => $maximum,
                'weight' => $weight,
                'increment' => $increment,
                'cap' => $cap,
                'rest' => $rest,
                'effort' => 'unknown',
                'sets' => array_fill(0, $setCount, ['weight'=>$weight,'reps'=>$minimum,'done'=>false]),
                'suggestion' => trim(substr((string)($exercise['progression'] ?? ''), 0, 500)),
                'substitution' => trim(substr((string)($exercise['substitution'] ?? ''), 0, 500)),
            ];
        }
        $date = $monday->modify('+' . $dayOffsets[$day] . ' days')->format('Y-m-d');
        $token = fitfuel_week_token($userId, $week, $slot);
        $notes = trim(substr((string)($session['notes'] ?? ''), 0, 2000));
        $record = [
            'schema' => 'fitfuel.workout.v1',
            'kind' => 'template',
            'name' => $name,
            'date' => $date,
            'minutes' => $minutes,
            'notes' => trim($summary . ($summary && $notes ? "\n\n" : '') . $notes),
            'exercises' => $entries,
            'token' => $token,
            'source' => 'chatgpt_weekly_plan',
            'planWeek' => $week,
            'day' => ucfirst($day),
            'priority' => $priority,
        ];
        $prepared[] = ['record'=>$record,'token'=>$token];
    }
    if ($priorityCount !== 3) throw new InvalidArgumentException('Label exactly three sessions as priority.');

    $conn->begin_transaction();
    try {
        $activeIds = [];
        foreach ($prepared as $item) {
            $record = $item['record'];
            $needle = '%' . $item['token'] . '%';
            $statement = $conn->prepare("SELECT id FROM workout_logs WHERE user_id=? AND notes LIKE ? LIMIT 1 FOR UPDATE");
            $statement->bind_param('is', $userId, $needle);
            $statement->execute();
            $existing = $statement->get_result()->fetch_assoc();
            $statement->close();
            $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || strlen($json) > 60000) throw new InvalidArgumentException('A workout is too large to save.');
            $date = $record['date']; $name = $record['name']; $minutes = $record['minutes']; $completed = 0;
            if ($existing) {
                $id = (int)$existing['id'];
                $statement = $conn->prepare('UPDATE workout_logs SET workout_date=?,workout_name=?,duration_minutes=?,notes=?,completed=? WHERE id=? AND user_id=?');
                $statement->bind_param('ssisiii', $date, $name, $minutes, $json, $completed, $id, $userId);
            } else {
                $statement = $conn->prepare('INSERT INTO workout_logs(user_id,workout_date,workout_name,duration_minutes,notes,completed) VALUES(?,?,?,?,?,?)');
                $statement->bind_param('issisi', $userId, $date, $name, $minutes, $json, $completed);
            }
            if (!$statement->execute()) throw new RuntimeException('Workout could not be saved.');
            $id = $existing ? (int)$existing['id'] : (int)$conn->insert_id;
            $statement->close();
            $activeIds[] = $id;
        }
        $sourceNeedle = '%"source":"chatgpt_weekly_plan"%';
        $statement = $conn->prepare('SELECT id FROM workout_logs WHERE user_id=? AND completed=0 AND notes LIKE ? FOR UPDATE');
        $statement->bind_param('is', $userId, $sourceNeedle);
        $statement->execute();
        $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (in_array($id, $activeIds, true)) continue;
            $statement = $conn->prepare('DELETE FROM workout_logs WHERE id=? AND user_id=?');
            $statement->bind_param('ii', $id, $userId);
            $statement->execute();
            $statement->close();
        }
        $conn->commit();
        return ['success'=>true,'week_start'=>$week,'saved'=>count($activeIds),'workout_ids'=>$activeIds];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
