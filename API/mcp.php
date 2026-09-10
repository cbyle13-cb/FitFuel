<?php
require_once 'session.php';
require_once 'workout_import_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function mcp_reply($id, array $result): void {
    echo json_encode(['jsonrpc'=>'2.0','id'=>$id,'result'=>$result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function mcp_error($id, int $code, string $message): void {
    echo json_encode(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function mcp_tool_result(array $data): array {
    return ['content'=>[['type'=>'text','text'=>json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],'structuredContent'=>$data,'isError'=>false];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error'=>'POST requests only.']);
    exit;
}
$request = json_decode(file_get_contents('php://input'), true);
if (!is_array($request)) mcp_error(null, -32700, 'Invalid JSON.');
$id = $request['id'] ?? null;
$method = (string)($request['method'] ?? '');

try {
    $userId = fitfuel_import_user($conn);
    if ($method === 'initialize') {
        mcp_reply($id, [
            'protocolVersion'=>'2025-03-26',
            'capabilities'=>['tools'=>new stdClass()],
            'serverInfo'=>['name'=>'FitFuel','version'=>'1.0.0'],
        ]);
    }
    if ($method === 'notifications/initialized') { http_response_code(202); exit; }
    if ($method === 'ping') mcp_reply($id, new stdClass());
    if ($method === 'tools/list') {
        mcp_reply($id, ['tools'=>[
            [
                'name'=>'get_training_context',
                'description'=>'Read the FitFuel member profile, home-equipment constraints, exercise catalog, recent completed sessions, saved templates, and prior weekly plans before building the next plan. This action does not change data.',
                'inputSchema'=>['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false],
            ],
            [
                'name'=>'save_weekly_plan',
                'description'=>'Save or replace one week of 3–5 structured workout templates in FitFuel. Call get_training_context first, use only exercise_id values from its exercise_catalog, label exactly three sessions priority, and pass the identical plan that is presented to the user in ChatGPT.',
                'inputSchema'=>[
                    'type'=>'object',
                    'required'=>['week_start','sessions'],
                    'additionalProperties'=>false,
                    'properties'=>[
                        'schema'=>['type'=>'string','enum'=>['fitfuel.weekly-plan.v1']],
                        'week_start'=>['type'=>'string','description'=>'Monday of the plan week in YYYY-MM-DD format.'],
                        'summary'=>['type'=>'string'],
                        'sessions'=>[
                            'type'=>'array','minItems'=>3,'maxItems'=>5,
                            'items'=>[
                                'type'=>'object','required'=>['day','name','priority','minutes','exercises'],'additionalProperties'=>false,
                                'properties'=>[
                                    'day'=>['type'=>'string','enum'=>['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']],
                                    'name'=>['type'=>'string','maxLength'=>100],
                                    'priority'=>['type'=>'string','enum'=>['priority','optional']],
                                    'minutes'=>['type'=>'integer','minimum'=>1,'maximum'=>180],
                                    'notes'=>['type'=>'string'],
                                    'exercises'=>[
                                        'type'=>'array','minItems'=>1,'maxItems'=>30,
                                        'items'=>[
                                            'type'=>'object','required'=>['exercise_id','sets','min_reps','max_reps','weight','increment','cap','rest_seconds'],'additionalProperties'=>false,
                                            'properties'=>[
                                                'exercise_id'=>['type'=>'string'],
                                                'sets'=>['type'=>'integer','minimum'=>1,'maximum'=>12],
                                                'min_reps'=>['type'=>'number','minimum'=>1,'maximum'=>600],
                                                'max_reps'=>['type'=>'number','minimum'=>1,'maximum'=>600],
                                                'weight'=>['type'=>'number','minimum'=>0,'maximum'=>2000],
                                                'increment'=>['type'=>'number','minimum'=>0,'maximum'=>100],
                                                'cap'=>['type'=>'number','minimum'=>0,'maximum'=>2000],
                                                'rest_seconds'=>['type'=>'number','minimum'=>0,'maximum'=>600],
                                                'progression'=>['type'=>'string'],
                                                'substitution'=>['type'=>'string'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]]);
    }
    if ($method === 'tools/call') {
        $parameters = is_array($request['params'] ?? null) ? $request['params'] : [];
        $name = (string)($parameters['name'] ?? '');
        $arguments = is_array($parameters['arguments'] ?? null) ? $parameters['arguments'] : [];
        if ($name === 'get_training_context') mcp_reply($id, mcp_tool_result(fitfuel_training_context($conn, $userId)));
        if ($name === 'save_weekly_plan') mcp_reply($id, mcp_tool_result(fitfuel_save_weekly_plan($conn, $userId, $arguments)));
        mcp_error($id, -32602, 'Unknown FitFuel tool.');
    }
    mcp_error($id, -32601, 'Method not found.');
} catch (DomainException $error) {
    http_response_code($error->getCode() ?: 401);
    mcp_error($id, -32001, $error->getMessage());
} catch (InvalidArgumentException $error) {
    mcp_error($id, -32602, $error->getMessage());
} catch (Throwable $error) {
    http_response_code(500);
    mcp_error($id, -32603, 'FitFuel could not complete the request.');
}

