<?php
require_once 'db.php';
function start_fitfuel_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        session_start();
    }
}
function json_response(array $data,int $status=200): void {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data,JSON_UNESCAPED_SLASHES); exit;
}
function require_login(): int {
    start_fitfuel_session(); $id=(int)($_SESSION['user_id']??0);
    if($id<1) json_response(['success'=>false,'message'=>'Please log in.'],401); return $id;
}
function current_user(mysqli $conn,int $uid): array {
    $st=$conn->prepare("SELECT id,email,first_name,last_name,role,is_active FROM users WHERE id=? LIMIT 1");
    $st->bind_param("i",$uid);$st->execute();$u=$st->get_result()->fetch_assoc();$st->close();
    if(!$u || (int)$u['is_active']!==1) json_response(['success'=>false,'message'=>'Account unavailable.'],403);
    return $u;
}
function family_id_for_user(mysqli $conn,int $uid): ?int {
    $st=$conn->prepare("SELECT family_id FROM family_members WHERE user_id=? LIMIT 1");
    $st->bind_param("i",$uid);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close();
    return $r?(int)$r['family_id']:null;
}
?>
<?php
$uid=require_login();
if($_SERVER['REQUEST_METHOD']==='GET'){
$st=$conn->prepare("SELECT date_of_birth,height_inches,current_weight,goal_weight,activity_level,fitness_goal,daily_calorie_goal,daily_protein_goal,daily_carbs_goal,daily_fat_goal,daily_fiber_goal FROM user_profiles WHERE user_id=?");$st->bind_param("i",$uid);$st->execute();$p=$st->get_result()->fetch_assoc()?:[];$st->close();json_response(['success'=>true,'profile'=>$p]);
}
$in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))json_response(['success'=>false,'message'=>'Invalid request.'],400);
$dob=$in['date_of_birth']??null;$h=$in['height_inches']??null;$w=$in['current_weight']??null;$g=$in['goal_weight']??null;$a=$in['activity_level']??null;$fg=$in['fitness_goal']??null;$c=$in['daily_calorie_goal']??null;$p=$in['daily_protein_goal']??null;$cb=$in['daily_carbs_goal']??null;$f=$in['daily_fat_goal']??null;$fi=$in['daily_fiber_goal']??null;
$st=$conn->prepare("UPDATE user_profiles SET date_of_birth=?,height_inches=?,current_weight=?,goal_weight=?,activity_level=?,fitness_goal=?,daily_calorie_goal=?,daily_protein_goal=?,daily_carbs_goal=?,daily_fat_goal=?,daily_fiber_goal=? WHERE user_id=?");$st->bind_param("sdddssdddddi",$dob,$h,$w,$g,$a,$fg,$c,$p,$cb,$f,$fi,$uid);
if(!$st->execute())json_response(['success'=>false,'message'=>'Unable to save profile.'],500);$st->close();json_response(['success'=>true,'message'=>'Profile saved.']);
?>