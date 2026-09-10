<?php
require_once 'session.php';
require_once 'workout_import_lib.php';
header('Cache-Control: no-store');

try {
    $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) throw new InvalidArgumentException('Invalid JSON request.');
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_response(['success'=>false,'message'=>'Method not allowed.'], 405);
    }
    $userId = fitfuel_import_user($conn, $input);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') json_response(['success'=>true,'context'=>fitfuel_training_context($conn, $userId)]);
    $action = (string)($input['action'] ?? 'save_weekly_plan');
    if ($action !== 'save_weekly_plan') throw new InvalidArgumentException('Unknown action.');
    $plan = is_array($input['plan'] ?? null) ? $input['plan'] : $input;
    json_response(fitfuel_save_weekly_plan($conn, $userId, $plan));
} catch (DomainException $error) {
    json_response(['success'=>false,'message'=>$error->getMessage()], $error->getCode() ?: 401);
} catch (InvalidArgumentException $error) {
    json_response(['success'=>false,'message'=>$error->getMessage()], 400);
} catch (Throwable $error) {
    json_response(['success'=>false,'message'=>'FitFuel could not process the workout plan.'], 500);
}

